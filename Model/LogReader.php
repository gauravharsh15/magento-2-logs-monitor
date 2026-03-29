<?php
namespace Gaurav\LogMonitor\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Gaurav\LogMonitor\Model\Config;
use Gaurav\LogMonitor\Model\AlertFactory;
use Gaurav\LogMonitor\Model\ResourceModel\Alert as AlertResource;
use Gaurav\LogMonitor\Model\StateFactory; 
use Gaurav\LogMonitor\Model\ResourceModel\State as StateResource;
use Psr\Log\LoggerInterface;

class LogReader
{
    protected $directoryList;
    protected $fileDriver;
    protected $config;
    protected $alertFactory;
    protected $alertResource;
    protected $stateFactory;
    protected $stateResource;
    protected $logger;

    public function __construct(
        DirectoryList $directoryList,
        FileDriver $fileDriver,
        Config $config,
        AlertFactory $alertFactory,
        AlertResource $alertResource,
        StateFactory $stateFactory,
        StateResource $stateResource,
        LoggerInterface $logger
    ) {
        $this->directoryList = $directoryList;
        $this->fileDriver = $fileDriver;
        $this->config = $config;
        $this->alertFactory = $alertFactory;
        $this->alertResource = $alertResource;
        $this->stateFactory = $stateFactory;
        $this->stateResource = $stateResource;
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

        $logDir = $this->directoryList->getPath(DirectoryList::LOG);
        $ignoredFiles = $this->config->getIgnoredFiles(); // e.g., ['debug.log', 'system.log']

        try {
            $files = $this->fileDriver->readDirectory($logDir);
            
            foreach ($files as $filePath) {
                // Only process .log files and skip ignored ones
                if (pathinfo($filePath, PATHINFO_EXTENSION) === 'log') {
                    $fileName = basename($filePath);
                    if (!in_array($fileName, $ignoredFiles)) {
                        $this->processFile($filePath, $fileName, $forceReset);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("LogMonitor Error: " . $e->getMessage());
        }
    }

    /**
     * Process an individual log file using memory-efficient byte-offsets
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

        $buffer = '';
        $currentSeverity = 'INFO';
        $currentHash = '';
        $isFirstLine = true;

        while (($line = fgets($fileHandle)) !== false) {
            // Check if line starts with Magento timestamp: [YYYY-MM-DD HH:MM:SS]
            if (preg_match('/^\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}.*?\]/', $line)) {
                
                // 1. Save the previously buffered multi-line error
                if (!$isFirstLine && !empty($buffer)) {
                    $this->saveAlert($currentHash, $currentSeverity, $buffer, $fileName);
                }

                $isFirstLine = false;
                $buffer = $line;
                
                // 2. Extract Severity Level (e.g., main.CRITICAL: -> CRITICAL)
                if (preg_match('/\.([A-Z]+)\:/', $line, $matches)) {
                    $currentSeverity = $matches[1];
                } else {
                    $currentSeverity = 'INFO';
                }

                // 3. Generate Deduplication Hash (Strip timestamp first)
                $cleanLine = trim(preg_replace('/^\[.*?\] /', '', $line));
                
                // Optional: Strip dynamic object IDs or file paths from hash if needed
                // $cleanLine = preg_replace('/obj_id=\d+/', 'obj_id=X', $cleanLine);
                
                $currentHash = md5($cleanLine);
            } else {
                // This line has no timestamp; it is part of a Stack Trace. Append it.
                $buffer .= $line;
            }
        }

        // Save the very last error block in the file
        if (!empty($buffer)) {
            $this->saveAlert($currentHash, $currentSeverity, $buffer, $fileName);
        }

        // Save the new byte offset back to the state table
        $newOffset = ftell($fileHandle);
        $this->fileDriver->fileClose($fileHandle);

        $state->setFileName($fileName);
        $state->setLastByteOffset($newOffset);
        $this->stateResource->save($state);
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

        $alert = $this->alertFactory->create();
        $this->alertResource->load($alert, $hash, 'error_hash');

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
            $alert->setErrorPreview(substr(trim($fullTrace), 0, 250)); 
            $alert->setFullTrace($fullTrace);
            $alert->setOccurrenceCount(1);
            $alert->setIsNotified(0); // 0 means email hasn't been sent yet
            $alert->setCreatedAt(date('Y-m-d H:i:s'));
            $alert->setLastSeenAt(date('Y-m-d H:i:s'));
        }

        $this->alertResource->save($alert);
    }
}