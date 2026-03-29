<?php
namespace Gaurav\LogMonitor\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Filesystem\DirectoryList;
use Gaurav\LogMonitor\Model\Config;

class LogReader
{
    protected $resourceConnection;
    protected $directoryList;
    protected $config;

    public function __construct(
        ResourceConnection $resourceConnection,
        DirectoryList $directoryList,
        Config $config
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->directoryList = $directoryList;
        $this->config = $config;
    }

    public function execute()
    {
        if (!$this->config->isEnabled()) {
            return;
        }
        // Get the Magento var/log directory path
        $logDir = $this->directoryList->getPath(DirectoryList::LOG);
        $logFiles = glob($logDir . DIRECTORY_SEPARATOR . '*.log');

        $ignoredFiles = $this->config->getIgnoredFiles();

        if ($logFiles) {
            foreach ($logFiles as $filePath) {
                if (is_file($filePath)) {
                    $fileName = basename($filePath);
                    
                    // 3. Skip if this file is in the ignore list
                    if (in_array($fileName, $ignoredFiles)) {
                        continue;
                    }
                    
                    $this->processFile($filePath, $fileName);
                }
            }
        }
    }

    protected function processFile($filePath, $fileName)
    {
        $connection = $this->resourceConnection->getConnection();
        $stateTable = $this->resourceConnection->getTableName('gaurav_logmonitor_state');
        
        // 1. Get current file size
        $currentFileSize = filesize($filePath);
        if ($currentFileSize === 0) return; // File is empty, skip

        // 2. Get saved byte offset from DB
        $savedOffset = $connection->fetchOne(
            $connection->select()->from($stateTable, 'last_byte_offset')->where('file_path = ?', $filePath)
        );
        $savedOffset = $savedOffset ? (int)$savedOffset : 0;

        // 3. Handle Log Rotation (If file is smaller than our offset, it was zipped/wiped)
        if ($currentFileSize < $savedOffset) {
            $savedOffset = 0;
        }

        // 4. Open file and jump to offset (ZERO memory footprint)
        $handle = fopen($filePath, 'r');
        fseek($handle, $savedOffset);

        // 5. Read line by line
        while (($line = fgets($handle)) !== false) {
            // Broadened to catch Errors, Criticals, Warnings, and generic Exceptions
            if (stripos($line, 'error') !== false || 
                stripos($line, 'critical') !== false || 
                stripos($line, 'warning') !== false ||
                stripos($line, 'exception') !== false) {
                
                $this->processErrorLine($line, $fileName, $connection);
            }
        }

        // 6. Save the exact byte position where we stopped
        $newOffset = ftell($handle);
        fclose($handle);

        $connection->insertOnDuplicate(
            $stateTable,
            ['file_path' => $filePath, 'last_byte_offset' => $newOffset, 'updated_at' => date('Y-m-d H:i:s')],
            ['last_byte_offset', 'updated_at']
        );
    }

    protected function processErrorLine($line, $fileName, $connection)
    {
        $alertsTable = $this->resourceConnection->getTableName('gaurav_logmonitor_alerts');

        // Strips standard Magento formatting like "[2026-03-29 05:14:23] report.CRITICAL: "
        $cleanError = preg_replace('/^\[.*?\]\s*[a-zA-Z0-9_-]+\.[A-Z]+:\s*/', '', trim($line));
        
        // Fallback: If it's a custom log without the 'report.CRITICAL:' part, just strip the date brackets
        if ($cleanError === trim($line)) {
            $cleanError = preg_replace('/^\[.*?\]\s*/', '', $cleanError);
        }
        
        if (empty($cleanError)) return;

        // Create the fingerprint
        $errorHash = md5($cleanError);
        
        // Take a 255 char snippet for the email preview
        $preview = substr($cleanError, 0, 255);

        // Upsert into the alerts table (Insert if new, update count if exists)
        $sql = "INSERT INTO {$alertsTable} (log_file, error_hash, error_preview, occurrence_count, is_notified, first_seen_at, last_seen_at) 
                VALUES (?, ?, ?, 1, 0, NOW(), NOW()) 
                ON DUPLICATE KEY UPDATE 
                occurrence_count = occurrence_count + 1,
                last_seen_at = NOW(),
                is_notified = 0"; // Reset is_notified to 0 so the dev gets reminded if it happens again

        $connection->query($sql, [$fileName, $errorHash, $preview]);
    }
}