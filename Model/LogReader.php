<?php
namespace Gaurav\LogMonitor\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Lock\LockManagerInterface;
use Gaurav\LogMonitor\Model\Config;
use Gaurav\LogMonitor\Model\AlertFactory;
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

    const LOCK_NAME = 'gaurav_logmonitor_process';

    protected $directoryList;
    protected $fileDriver;
    protected $config;
    protected $alertFactory;
    protected $alertResource;
    protected $stateFactory;
    protected $stateResource;
    protected $resourceConnection;
    protected $lockManager;
    protected $logger;

    public function __construct(
        DirectoryList $directoryList,
        FileDriver $fileDriver,
        Config $config,
        AlertFactory $alertFactory,
        AlertResource $alertResource,
        StateFactory $stateFactory,
        StateResource $stateResource,
        ResourceConnection $resourceConnection,
        LockManagerInterface $lockManager,
        LoggerInterface $logger
    ) {
        $this->directoryList = $directoryList;
        $this->fileDriver = $fileDriver;
        $this->config = $config;
        $this->alertFactory = $alertFactory;
        $this->alertResource = $alertResource;
        $this->stateFactory = $stateFactory;
        $this->stateResource = $stateResource;
        $this->resourceConnection = $resourceConnection;
        $this->lockManager = $lockManager;
        $this->logger = $logger;
    }

    /**
     * Main entry point called by the Cron job
     */
    public function execute($forceReset = false)
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        // Non-blocking lock: on 10GB+ logs a scan can outlast the 5-minute cron
        // schedule, so without this a second overlapping run would race the
        // first on the same byte-offset state and corrupt it.
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            $this->logger->info('LogMonitor: previous scan still running, skipping this run.');
            return;
        }

        try {
            $logDir = $this->directoryList->getPath(DirectoryList::LOG);
            $ignoredFiles = $this->config->getIgnoredFiles(); // e.g., ['debug.log', 'system.log']
            $scanArchives = $this->config->shouldScanArchives();

            $files = $this->fileDriver->readDirectory($logDir);

            foreach ($files as $filePath) {
                $fileName = basename($filePath);

                // A single corrupt/oversized file must not abort the whole run -
                // every other file still needs to be scanned this cycle.
                try {
                    if (pathinfo($filePath, PATHINFO_EXTENSION) === 'log') {
                        if (!in_array($fileName, $ignoredFiles)) {
                            $this->processFile($filePath, $fileName, $forceReset);
                        }
                    } elseif ($scanArchives && $this->isArchiveFile($fileName)) {
                        $baseName = $this->getArchiveBaseName($fileName);
                        if (!in_array($baseName, $ignoredFiles) && !in_array($fileName, $ignoredFiles)) {
                            $this->processArchiveFile($filePath, $fileName);
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->error("LogMonitor Error processing {$fileName}: " . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("LogMonitor Error: " . $e->getMessage());
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    /**
     * Process an individual live log file using memory-efficient byte-offsets
     */
    protected function processFile($filePath, $fileName, $forceReset)
    {
        $fileSize = $this->fileDriver->stat($filePath)['size'];

        // Load the state to get the last read byte offset
        $state = $this->stateFactory->create();
        $this->stateResource->load($state, $fileName, 'file_name');

        $lastOffset = $state->getLastByteOffset() ?: 0;

        // If forced reset, or if file size is smaller than offset (log rotation occurred)
        if ($forceReset || $fileSize < $lastOffset) {
            $lastOffset = 0;
        }

        // If there is no new data, skip to save resources
        if ($fileSize == $lastOffset) {
            return;
        }

        $fileHandle = $this->fileDriver->fileOpen($filePath, 'r');

        // Jump directly to the new data
        fseek($fileHandle, $lastOffset);

        $this->parseLines($fileHandle, $fileName);

        // Save the new byte offset back to the state table
        $newOffset = ftell($fileHandle);
        $this->fileDriver->fileClose($fileHandle);

        $state->setFileName($fileName);
        $state->setLastByteOffset($newOffset);
        $this->stateResource->save($state);
    }

    /**
     * Process a rotated/gzipped archive exactly once.
     *
     * Archives are immutable once logrotate creates them, so unlike the live
     * file there is no byte-offset to track - just whether we've already
     * fully read this exact (name, size) archive before. Alerts are tagged
     * with the archive's original base name (e.g. "exception.log") so they
     * merge into the same alert history as the live file instead of creating
     * a separate, orphaned set of rows per rotation.
     */
    protected function processArchiveFile($filePath, $fileName)
    {
        $fileSize = $this->fileDriver->stat($filePath)['size'];
        if ($fileSize === 0) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $archiveTable = $this->resourceConnection->getTableName('gaurav_logmonitor_archive_state');

        $alreadyProcessed = $connection->fetchOne(
            $connection->select()
                ->from($archiveTable, ['entity_id'])
                ->where('archive_name = ?', $fileName)
                ->where('file_size = ?', $fileSize)
        );

        if ($alreadyProcessed) {
            return;
        }

        $isGzip = pathinfo($filePath, PATHINFO_EXTENSION) === 'gz';
        $handle = $isGzip ? @gzopen($filePath, 'rb') : $this->fileDriver->fileOpen($filePath, 'r');

        if (!$handle) {
            throw new \RuntimeException("Unable to open archive: {$filePath}");
        }

        $baseName = $this->getArchiveBaseName($fileName);

        try {
            $this->parseLines($handle, $baseName, $isGzip);
        } finally {
            $isGzip ? gzclose($handle) : $this->fileDriver->fileClose($handle);
        }

        $connection->insert($archiveTable, [
            'archive_name' => $fileName,
            'file_size' => $fileSize,
            'processed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Shared line-by-line parser used by both the live file (positioned via
     * fseek at a byte offset) and archive files (read from the start).
     * Reads to EOF and saves each buffered error block as it completes.
     */
    protected function parseLines($fileHandle, $fileName, $isGzip = false)
    {
        $readLine = $isGzip ? 'gzgets' : 'fgets';

        $buffer = '';
        $bufferLines = 0;
        $currentSeverity = 'UNKNOWN';
        $currentHash = '';
        $isFirstLine = true;

        while (($line = $readLine($fileHandle)) !== false) {
            // Check if line starts with Magento timestamp: [YYYY-MM-DD HH:MM:SS]
            if (preg_match('/^\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}.*?\]/', $line)) {

                // 1. Save the previously buffered multi-line error
                if (!$isFirstLine && $buffer !== '') {
                    $this->saveAlert($currentHash, $currentSeverity, $buffer, $fileName);
                }

                $isFirstLine = false;
                $buffer = $line;
                $bufferLines = 1;

                // 2. Extract Severity Level (e.g., main.CRITICAL: -> CRITICAL)
                if (preg_match('/\.([A-Z]+)\:/', $line, $matches)) {
                    $currentSeverity = $matches[1];
                } else {
                    // Unrecognized format - keep it visible as UNKNOWN rather than
                    // silently defaulting to INFO (which gets filtered out below).
                    $currentSeverity = 'UNKNOWN';
                }

                // 3. Generate Deduplication Hash (Strip timestamp first)
                $cleanLine = trim(preg_replace('/^\[.*?\] /', '', $line));
                $currentHash = md5($cleanLine);
            } elseif ($bufferLines < self::MAX_BUFFER_LINES && strlen($buffer) < self::MAX_BUFFER_BYTES) {
                // This line has no timestamp; it is part of a Stack Trace. Append it,
                // bounded so a file that never matches the timestamp pattern can't
                // grow this buffer to the size of the whole remaining file.
                $buffer .= $line;
                $bufferLines++;
            }
        }

        // Save the very last error block in the file
        if ($buffer !== '') {
            $this->saveAlert($currentHash, $currentSeverity, $buffer, $fileName);
        }
    }

    /**
     * True if the file name looks like a rotated/gzipped copy of a .log file,
     * e.g. exception.log.1, exception.log.1.gz, exception.log.gz
     */
    protected function isArchiveFile($fileName)
    {
        return (bool)preg_match('/\.log(\.\d+)?\.gz$|\.log\.\d+$/', $fileName);
    }

    /**
     * Strip rotation/gzip suffixes to recover the original live file name,
     * so archive-derived alerts merge into the same log_file history.
     */
    protected function getArchiveBaseName($fileName)
    {
        $name = preg_replace('/\.gz$/', '', $fileName);
        $name = preg_replace('/\.\d+$/', '', $name);
        return $name;
    }

    /**
     * Deduplicate and save the error to the database
     */
    protected function saveAlert($hash, $severity, $fullTrace, $fileName)
    {
        // Don't log INFO or DEBUG messages to keep the dashboard clean
        if (in_array($severity, ['INFO', 'DEBUG'])) {
            return;
        }

        $fullTrace = trim($fullTrace);
        if (strlen($fullTrace) > self::MAX_TRACE_LENGTH) {
            $fullTrace = substr($fullTrace, 0, self::MAX_TRACE_LENGTH) . "\n... [truncated]";
        }

        $alert = $this->alertFactory->create();
        $this->alertResource->loadByFileAndHash($alert, $fileName, $hash);

        if ($alert->getId()) {
            // Error exists: Increment count and update timestamp
            $count = $alert->getOccurrenceCount() + 1;
            $alert->setOccurrenceCount($count);
            $alert->setLastSeenAt(date('Y-m-d H:i:s'));
            // Note: We don't overwrite the full_trace to save DB performance,
            // as the stack trace for the exact same error hash is usually identical.
        } else {
            // New Error: Create a fresh record
            $alert->setErrorHash($hash);
            $alert->setSeverity($severity);
            $alert->setLogFile($fileName);
            $alert->setErrorPreview(substr($fullTrace, 0, 250));
            $alert->setFullTrace($fullTrace);
            $alert->setOccurrenceCount(1);
            $alert->setIsNotified(0); // 0 means email hasn't been sent yet
            $alert->setLastNotifiedCount(0);
            $alert->setCreatedAt(date('Y-m-d H:i:s'));
            $alert->setLastSeenAt(date('Y-m-d H:i:s'));
        }

        $this->alertResource->save($alert);
    }
}
