<?php
namespace Gaurav\LogMonitor\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Lock\LockManagerInterface;
use Gaurav\LogMonitor\Model\Config;
use Gaurav\LogMonitor\Model\Config\Source\Severity;
use Gaurav\LogMonitor\Model\ResourceModel\Alert as AlertResource;
use Gaurav\LogMonitor\Model\StateFactory;
use Gaurav\LogMonitor\Model\ResourceModel\State as StateResource;
use Psr\Log\LoggerInterface;

class LogReader
{
    /**
     * Safety caps on a single buffered error block so a malformed/non-standard
     * log file (one that never matches the timestamp pattern) can't grow the
     * in-memory buffer unbounded until EOF.
     */
    const MAX_BUFFER_LINES = 500;
    const MAX_BUFFER_BYTES = 65536;

    /**
     * Cap on stored trace length to keep DB rows and alert emails bounded.
     */
    const MAX_TRACE_LENGTH = 20000;

    const PREVIEW_LENGTH = 250;

    /**
     * Max bytes per fgets() call. A single huge line (e.g. a JSON dump in
     * debug.log) is read in pieces instead of being pulled into memory whole;
     * the pieces after the first are treated like trace lines and fall under
     * the buffer caps above.
     */
    const READ_CHUNK_BYTES = 65536;

    /**
     * Unique errors (or bytes of trace text) collected in memory before they
     * are written to the DB in one INSERT ... ON DUPLICATE KEY UPDATE.
     */
    const FLUSH_ROWS = 100;
    const FLUSH_BYTES = 524288;

    /**
     * Server-safety limits. They bound the work a single run can do, at the
     * cost of completeness:
     * - a file with more unread data than MAX_BYTES_PER_FILE (the first scan
     *   of an old log, an error storm, cron having been down) has only its
     *   newest MAX_BYTES_PER_FILE read - the older part is skipped for good;
     * - once a run has read MAX_BYTES_PER_RUN or run for MAX_RUN_SECONDS, it
     *   stops between two entries and the rest waits for the next run.
     */
    const MAX_BYTES_PER_FILE = 16777216;
    const MAX_BYTES_PER_RUN = 67108864;
    const MAX_RUN_SECONDS = 60;

    /**
     * Distinct alerts kept in the table. Once reached, new kinds of errors are
     * dropped (existing ones still count up), so a log full of unique messages
     * can't grow the table without bound.
     */
    const MAX_ALERT_ROWS = 10000;

    /**
     * The saved offset is moved forward after at most this much input, so a
     * run killed halfway recounts no more than this on the next run.
     */
    const CHECKPOINT_BYTES = 4194304;

    /**
     * Seeking into a gzip file decompresses everything before the offset.
     * Past this, recovering a rotated file's tail is skipped.
     */
    const MAX_GZIP_SEEK_BYTES = 268435456;

    /**
     * Newest rotated copies checked when looking for the one a live file was
     * rotated into.
     */
    const MAX_ROTATED_CANDIDATES = 5;

    /**
     * How much of a file's first line identifies it. Comparing it between runs
     * tells "the same file grew" apart from "the file was rotated and a new
     * one started under the same name".
     */
    const FINGERPRINT_BYTES = 1024;

    const LOCK_NAME = 'gaurav_logmonitor_process';

    const RESULT_DONE = 'done';
    const RESULT_DISABLED = 'disabled';
    const RESULT_LOCKED = 'locked';

    /**
     * "[2026-10-20T10:15:00.123456+00:00] channel.LEVEL: ..." (Monolog 2/3) or
     * "[2026-10-20 10:15:00] channel.LEVEL: ..." (Monolog 1). Group 1 is the
     * timestamp, group 2 the level when the line has one.
     */
    const ENTRY_HEADER_PATTERN = '/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}[^\]]*)\]\s*(?:[\w.\-]*?\.([A-Z]+):)?/';

    protected $directoryList;
    protected $fileDriver;
    protected $config;
    protected $alertResource;
    protected $stateFactory;
    protected $stateResource;
    protected $lockManager;
    protected $logger;

    /**
     * Per-run state. LogReader is a shared instance, so execute() resets these.
     */
    protected $pending = [];
    protected $pendingBytes = 0;
    protected $minSeverityRank = 0;
    protected $groupSimilar = true;
    protected $ignoredFiles = [];
    protected $alertRowCount = 0;
    protected $bytesLeft = 0;
    protected $deadline = 0;
    protected $stats = [];

    public function __construct(
        DirectoryList $directoryList,
        FileDriver $fileDriver,
        Config $config,
        AlertResource $alertResource,
        StateFactory $stateFactory,
        StateResource $stateResource,
        LockManagerInterface $lockManager,
        LoggerInterface $logger
    ) {
        $this->directoryList = $directoryList;
        $this->fileDriver = $fileDriver;
        $this->config = $config;
        $this->alertResource = $alertResource;
        $this->stateFactory = $stateFactory;
        $this->stateResource = $stateResource;
        $this->lockManager = $lockManager;
        $this->logger = $logger;
    }

    /**
     * Main entry point called by the Cron job and the CLI command.
     *
     * @param bool $forceReset re-read every live log (still limited to its newest MAX_BYTES_PER_FILE)
     * @return string one of the RESULT_* constants
     */
    public function execute($forceReset = false)
    {
        if (!$this->config->isEnabled()) {
            return self::RESULT_DISABLED;
        }

        // Non-blocking lock: the CLI command can be started while cron is
        // mid-scan. Without this a second run would race the first on the same
        // byte-offset state and corrupt it.
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            $this->logger->info('LogMonitor: previous scan still running, skipping this run.');
            return self::RESULT_LOCKED;
        }

        try {
            $this->pending = [];
            $this->pendingBytes = 0;
            $this->stats = [
                'files' => 0,
                'entries' => 0,
                'skipped_bytes' => 0,
                'deferred_files' => 0,
                'dropped_errors' => 0,
            ];
            $this->bytesLeft = static::MAX_BYTES_PER_RUN;
            $this->deadline = microtime(true) + static::MAX_RUN_SECONDS;
            $this->minSeverityRank = Severity::LEVELS[$this->config->getMinSeverity()];
            $this->groupSimilar = $this->config->isGroupSimilarEnabled();
            $this->ignoredFiles = $this->config->getIgnoredFiles(); // e.g., ['debug.log', 'payment_*.log']

            $logDir = $this->directoryList->getPath(DirectoryList::LOG);
            if (!$this->fileDriver->isDirectory($logDir)) {
                return self::RESULT_DONE;
            }

            $this->alertRowCount = $this->alertResource->countAlerts();

            foreach ($this->fileDriver->readDirectory($logDir) as $filePath) {
                $fileName = basename($filePath);
                if (substr($fileName, -4) !== '.log' || $this->isIgnored($fileName)) {
                    continue;
                }

                if ($this->isRunBudgetExhausted()) {
                    $this->stats['deferred_files']++;
                    continue;
                }

                // A single corrupt/oversized file must not abort the whole run -
                // every other file still needs to be scanned this cycle.
                try {
                    if ($this->fileDriver->isFile($filePath)) {
                        $this->processFile($filePath, $fileName, $forceReset);
                    }
                } catch (\Throwable $e) {
                    // Drop anything half-collected from the failed file so it isn't
                    // written under the next file's name.
                    $this->pending = [];
                    $this->pendingBytes = 0;
                    $this->logger->error("LogMonitor Error processing {$fileName}: " . $e->getMessage());
                }
            }

            $this->logLimitsReached();
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }

        return self::RESULT_DONE;
    }

    /**
     * Counters from the most recent execute(), for the CLI command's summary.
     *
     * @return array files, entries, skipped_bytes, deferred_files, dropped_errors
     */
    public function getLastRunStats()
    {
        return $this->stats;
    }

    /**
     * Process an individual live log file using memory-efficient byte-offsets
     */
    protected function processFile($filePath, $fileName, $forceReset)
    {
        // Load the state to get the last read byte offset
        $state = $this->stateFactory->create();
        $this->stateResource->load($state, $fileName, 'file_name');

        $knownOffset = (int)$state->getLastByteOffset();
        $knownFingerprint = (string)$state->getFingerprint();

        $handle = $this->fileDriver->fileOpen($filePath, 'rb');

        try {
            $fileSize = fstat($handle)['size'];
            if ($fileSize === 0) {
                return;
            }

            $fingerprint = $this->readFingerprint($handle, false);
            $lastOffset = $knownOffset;

            // A different first line means the file was rotated (moved away or
            // copy-truncated) and this is a new file under the same name. An empty
            // known fingerprint is a row saved before fingerprints existed: adopt
            // the current one instead of treating it as a rotation and rescanning.
            $rotated = $knownFingerprint !== '' && $knownFingerprint !== $fingerprint;

            // Same file, same size: nothing to read and no state to save. (Size
            // alone isn't enough - a freshly rotated file can match the old offset.)
            if (!$forceReset && !$rotated && $knownFingerprint !== '' && $fileSize === $knownOffset) {
                return;
            }

            if ($rotated && $lastOffset > 0 && $this->config->shouldScanArchives()) {
                $this->processRotatedTail($filePath, $fileName, $knownFingerprint, $lastOffset);
            }

            if ($forceReset || $rotated || $fileSize < $lastOffset) {
                $lastOffset = 0;
            }

            // Never read more than MAX_BYTES_PER_FILE of a file in one run. If more
            // is waiting, the older part is skipped and only the newest is read.
            $startOffset = max($lastOffset, $fileSize - static::MAX_BYTES_PER_FILE);
            $this->stats['skipped_bytes'] += $startOffset - $lastOffset;

            $state->setFileName($fileName);
            $state->setFingerprint($fingerprint);
            $state->setLastByteOffset($startOffset);

            if ($fileSize > $startOffset) {
                $this->stats['files']++;

                // Jump directly to the new data. Reading stops at the size measured
                // above; anything appended meanwhile is picked up next run.
                fseek($handle, $startOffset);
                $newOffset = $this->parseLines(
                    $handle,
                    $fileName,
                    $startOffset,
                    $fileSize - $startOffset,
                    false,
                    true,
                    function ($offset) use ($state) {
                        $state->setLastByteOffset($offset);
                        $this->stateResource->save($state);
                    }
                );
                $state->setLastByteOffset($newOffset);
            }

            $this->stateResource->save($state);
        } finally {
            $this->fileDriver->fileClose($handle);
        }
    }

    /**
     * After a rotation, read whatever the rotated copy gained after our last
     * scan (from $offset to its end), so entries logged in the minutes before
     * rotation aren't lost.
     *
     * The rotated copy is found by its first line rather than its name, so
     * numbered (exception.log.1), dated (exception.log-20261020) and gzipped
     * variants all work, and only the unread tail is counted - the part before
     * $offset was already counted while it was the live file.
     */
    protected function processRotatedTail($liveFilePath, $fileName, $fingerprint, $offset)
    {
        $rotatedPath = $this->findRotatedCopy($liveFilePath, $fileName, $fingerprint);
        if ($rotatedPath === null) {
            $this->logger->warning(
                "LogMonitor: {$fileName} was rotated, but its rotated copy wasn't found next to it, "
                . 'so entries written just before rotation may have been missed.'
            );
            return;
        }

        $isGzip = substr($rotatedPath, -3) === '.gz';
        if ($isGzip && $offset > static::MAX_GZIP_SEEK_BYTES) {
            $this->logger->info(
                "LogMonitor: not recovering the end of rotated {$fileName} - it is too far into a gzip file to reach cheaply."
            );
            return;
        }

        $handle = $this->openFile($rotatedPath, $isGzip);

        try {
            if ($isGzip) {
                // Decompresses up to $offset (capped above); once per rotation.
                gzseek($handle, $offset);
                $start = $offset;
                $byteLimit = static::MAX_BYTES_PER_FILE;
            } else {
                $size = fstat($handle)['size'];
                $start = max($offset, $size - static::MAX_BYTES_PER_FILE);
                $this->stats['skipped_bytes'] += max(0, $start - $offset);
                fseek($handle, $start);
                $byteLimit = max(0, $size - $start);
            }
            $this->parseLines($handle, $fileName, $start, $byteLimit, $isGzip, false);
        } finally {
            $this->closeFile($handle, $isGzip);
        }
    }

    /**
     * Find the rotated copy of $fileName (exception.log.1, exception.log.1.gz,
     * exception.log-20261020, ...) whose first line matches $fingerprint,
     * checking only the newest few.
     *
     * @return string|null
     */
    protected function findRotatedCopy($liveFilePath, $fileName, $fingerprint)
    {
        $candidates = [];
        foreach ($this->fileDriver->readDirectory(dirname($liveFilePath)) as $path) {
            $name = basename($path);
            $suffix = substr($name, strlen($fileName), 1);
            if ($name === $fileName || strpos($name, $fileName) !== 0 || ($suffix !== '.' && $suffix !== '-')) {
                continue;
            }

            try {
                if ($this->fileDriver->isFile($path)) {
                    $candidates[$path] = (int)$this->fileDriver->stat($path)['mtime'];
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        arsort($candidates);
        foreach (array_slice(array_keys($candidates), 0, static::MAX_ROTATED_CANDIDATES) as $path) {
            $isGzip = substr($path, -3) === '.gz';
            try {
                $handle = $this->openFile($path, $isGzip);
            } catch (\Exception $e) {
                continue;
            }

            $matches = $this->readFingerprint($handle, $isGzip) === $fingerprint;
            $this->closeFile($handle, $isGzip);

            if ($matches) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Shared line-by-line parser used by both the live file (positioned via
     * fseek at a byte offset) and a rotated file's unread tail. Groups each
     * timestamped line with the stack-trace lines under it and collects the
     * result for a batched DB write.
     *
     * @param resource $handle positioned at $offset
     * @param string $logFile name the alerts are recorded under
     * @param int $offset uncompressed byte position $handle is at
     * @param int $byteLimit stop after consuming this many bytes
     * @param bool $isGzip
     * @param bool $isLive the file may still be being written to
     * @param callable|null $checkpoint called with a safe resume offset as data is written
     * @return int offset of the first byte not consumed
     */
    protected function parseLines($handle, $logFile, $offset, $byteLimit, $isGzip, $isLive, ?callable $checkpoint = null)
    {
        $endOffset = $offset + $byteLimit;
        $checkpointOffset = $offset;
        $header = null;
        $buffer = '';
        $bufferLines = 0;

        while ($offset < $endOffset
            && ($line = $isGzip ? gzgets($handle, self::READ_CHUNK_BYTES) : fgets($handle, self::READ_CHUNK_BYTES)) !== false
        ) {
            // A last line with no newline yet is still being written by the
            // logger. Leave it for the next run instead of consuming half of it.
            if ($isLive && substr($line, -1) !== "\n" && feof($handle)) {
                break;
            }

            if (preg_match(self::ENTRY_HEADER_PATTERN, $line, $matches)) {
                // Out of time or bytes for this run: stop between two entries,
                // so the next run resumes exactly at this line.
                if ($this->isRunBudgetExhausted()) {
                    break;
                }

                // 1. Save the previously buffered multi-line error
                if ($header !== null) {
                    $this->collectEntry($logFile, $header, $buffer);
                }

                // Everything before this line is now collected, so it's a safe
                // point to write to the DB and record how far we got.
                if ($this->isBatchFull()) {
                    $this->flush();
                }
                if ($checkpoint && $offset - $checkpointOffset >= static::CHECKPOINT_BYTES) {
                    $this->flush();
                    $checkpoint($offset);
                    $checkpointOffset = $offset;
                }

                $header = $matches;
                $buffer = $line;
                $bufferLines = 1;
            } elseif ($header !== null && $bufferLines < self::MAX_BUFFER_LINES && strlen($buffer) < self::MAX_BUFFER_BYTES) {
                // This line has no timestamp; it is part of a Stack Trace. Append it,
                // bounded so a file that never matches the timestamp pattern can't
                // grow this buffer to the size of the whole remaining file.
                $buffer .= $line;
                $bufferLines++;
            }
            // Trace lines before the first timestamp belong to an entry that began
            // before $offset and was already counted (or skipped), so they're ignored.

            $offset += strlen($line);
            $this->bytesLeft -= strlen($line);
        }

        // Save the very last error block in the file
        if ($header !== null) {
            $this->collectEntry($logFile, $header, $buffer);
        }
        $this->flush();

        return $offset;
    }

    /**
     * Fingerprint, deduplicate and queue one log entry for the next batch write.
     *
     * @param string $logFile
     * @param array $header ENTRY_HEADER_PATTERN matches for the entry's first line
     * @param string $text the entry: first line plus any trace lines
     */
    protected function collectEntry($logFile, array $header, $text)
    {
        $severity = isset($header[2]) && $header[2] !== '' ? $header[2] : Severity::UNKNOWN;

        // Unrecognised levels (UNKNOWN included) can't be ranked, so they're
        // always kept rather than silently dropped.
        if (isset(Severity::LEVELS[$severity]) && Severity::LEVELS[$severity] < $this->minSeverityRank) {
            return;
        }

        // The first line minus its timestamp: "channel.LEVEL: message {context} [extra]"
        $newlinePos = strpos($text, "\n");
        $firstLine = $newlinePos === false ? $text : substr($text, 0, $newlinePos);
        $message = trim(substr($firstLine, strlen($header[1]) + 2));

        $hash = md5($this->groupSimilar ? $this->normalizeForGrouping($message) : $message);
        $seenAt = $this->toUtc($header[1]);
        $key = $logFile . '|' . $hash;

        $this->stats['entries']++;

        if (isset($this->pending[$key])) {
            $this->pending[$key]['occurrence_count']++;
            if ($seenAt < $this->pending[$key]['first_seen_at']) {
                $this->pending[$key]['first_seen_at'] = $seenAt;
            }
            if ($seenAt > $this->pending[$key]['last_seen_at']) {
                $this->pending[$key]['last_seen_at'] = $seenAt;
            }
            return;
        }

        // Preview and trace are only stored when the row is first created; an
        // existing row just has its count and timestamps updated.
        $trace = $this->truncate(rtrim($text), self::MAX_TRACE_LENGTH, "\n... [truncated]");
        $this->pending[$key] = [
            'log_file' => $logFile,
            'error_hash' => $hash,
            'severity' => $severity,
            'error_preview' => $this->truncate($message, self::PREVIEW_LENGTH),
            'full_trace' => $trace,
            'occurrence_count' => 1,
            'first_seen_at' => $seenAt,
            'last_seen_at' => $seenAt,
        ];
        $this->pendingBytes += strlen($trace);
    }

    /**
     * Strip the parts of a message that differ between occurrences of the same
     * problem, so e.g. "Order 1001 failed" and "Order 1002 failed" share a hash.
     * Only the hash input is normalized; the stored preview/trace keep the real text.
     */
    protected function normalizeForGrouping($message)
    {
        return preg_replace(
            [
                '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i',
                '/[\w.+\-]+@[\w\-]+(?:\.[\w\-]+)+/',
                '/\b(?=[a-f]*\d)[0-9a-f]{12,}\b/i',
                '/(?<![a-z])\d+/i',
            ],
            ['{uuid}', '{email}', '{hex}', '0'],
            $message
        );
    }

    protected function isBatchFull()
    {
        return count($this->pending) >= self::FLUSH_ROWS || $this->pendingBytes >= self::FLUSH_BYTES;
    }

    protected function isRunBudgetExhausted()
    {
        return $this->bytesLeft <= 0 || microtime(true) >= $this->deadline;
    }

    /**
     * Write the collected batch. Errors already in the table only have their
     * count and timestamps updated (their trace isn't sent at all); new ones
     * are inserted until MAX_ALERT_ROWS is reached and dropped after that.
     */
    protected function flush()
    {
        if (!$this->pending) {
            return;
        }

        $hashesByFile = [];
        foreach ($this->pending as $row) {
            $hashesByFile[$row['log_file']][] = $row['error_hash'];
        }
        $existing = [];
        foreach ($hashesByFile as $logFile => $hashes) {
            foreach ($this->alertResource->getExistingHashes($logFile, $hashes) as $hash) {
                $existing[$logFile . '|' . $hash] = true;
            }
        }

        $rows = [];
        foreach ($this->pending as $key => $row) {
            if (isset($existing[$key])) {
                $row['full_trace'] = '';
            } elseif ($this->alertRowCount >= static::MAX_ALERT_ROWS) {
                $this->stats['dropped_errors']++;
                continue;
            } else {
                $this->alertRowCount++;
            }
            $rows[] = $row;
        }

        if ($rows) {
            $this->alertResource->upsertAlerts($rows);
        }
        $this->pending = [];
        $this->pendingBytes = 0;
    }

    /**
     * One informational line per run when a limit cut the work short. INFO is
     * below the default minimum severity, so these don't become alerts.
     */
    protected function logLimitsReached()
    {
        if ($this->stats['skipped_bytes'] > 0) {
            $this->logger->info(sprintf(
                'LogMonitor: skipped %.1f MB of older log data to stay within the per-run read limit.',
                $this->stats['skipped_bytes'] / 1048576
            ));
        }
        if ($this->stats['deferred_files'] > 0) {
            $this->logger->info(sprintf(
                'LogMonitor: run limit reached, %d log file(s) left for the next run.',
                $this->stats['deferred_files']
            ));
        }
        if ($this->stats['dropped_errors'] > 0) {
            $this->logger->info(sprintf(
                'LogMonitor: the limit of %d stored alerts is reached; %d new kind(s) of error were not recorded. '
                . 'Delete old alerts or set a retention period.',
                static::MAX_ALERT_ROWS,
                $this->stats['dropped_errors']
            ));
        }
    }

    /**
     * Log timestamps carry their own offset (Monolog 2+) or are UTC (Magento
     * forces UTC for Monolog 1), and the alert table stores UTC.
     */
    protected function toUtc($timestamp)
    {
        $time = strtotime($timestamp);
        return gmdate('Y-m-d H:i:s', $time === false ? time() : $time);
    }

    /**
     * Cut to at most $maxBytes without splitting a multi-byte character, and
     * replace invalid UTF-8 so the DB doesn't silently truncate the value.
     */
    protected function truncate($text, $maxBytes, $suffix = '')
    {
        $text = mb_scrub($text, 'UTF-8');
        if (strlen($text) <= $maxBytes) {
            return $text;
        }
        return mb_strcut($text, 0, $maxBytes, 'UTF-8') . $suffix;
    }

    /**
     * MD5 of the file's first line (up to FINGERPRINT_BYTES). Leaves the handle
     * at an arbitrary position - callers seek where they need afterwards.
     */
    protected function readFingerprint($handle, $isGzip)
    {
        if ($isGzip) {
            gzrewind($handle);
            $firstLine = gzgets($handle, self::FINGERPRINT_BYTES + 1);
        } else {
            rewind($handle);
            $firstLine = fgets($handle, self::FINGERPRINT_BYTES + 1);
        }

        return $firstLine === false || $firstLine === '' ? '' : md5($firstLine);
    }

    protected function isIgnored($fileName)
    {
        foreach ($this->ignoredFiles as $pattern) {
            if ($pattern === $fileName || fnmatch($pattern, $fileName)) {
                return true;
            }
        }
        return false;
    }

    protected function openFile($path, $isGzip)
    {
        if (!$isGzip) {
            return $this->fileDriver->fileOpen($path, 'rb');
        }

        $handle = function_exists('gzopen') ? @gzopen($path, 'rb') : false;
        if (!$handle) {
            throw new \RuntimeException("Unable to open archive: {$path}");
        }
        return $handle;
    }

    protected function closeFile($handle, $isGzip)
    {
        if ($isGzip) {
            gzclose($handle);
        } else {
            $this->fileDriver->fileClose($handle);
        }
    }
}
