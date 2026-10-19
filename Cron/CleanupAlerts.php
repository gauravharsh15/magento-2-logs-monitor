<?php
namespace Gaurav\LogMonitor\Cron;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Gaurav\LogMonitor\Model\Config;

class CleanupAlerts
{
    const DELETE_CHUNK_SIZE = 500;

    protected $resourceConnection;
    protected $config;
    protected $directoryList;

    public function __construct(
        ResourceConnection $resourceConnection,
        Config $config,
        DirectoryList $directoryList
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->config = $config;
        $this->directoryList = $directoryList;
    }

    public function execute()
    {
        $this->removeStateOfDeletedFiles();

        $retentionDays = $this->config->getRetentionDays();
        if ($retentionDays <= 0) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $alertsTable = $this->resourceConnection->getTableName('gaurav_logmonitor_alerts');

        // last_seen_at is stored in UTC.
        $cutoff = gmdate('Y-m-d H:i:s', time() - $retentionDays * 86400);

        $connection->delete($alertsTable, ['last_seen_at < ?' => $cutoff]);
    }

    /**
     * Forget the read position of log files that no longer exist, so the state
     * table doesn't keep a row for every file ever seen (e.g. dated logs from
     * extensions that write one file per day).
     */
    protected function removeStateOfDeletedFiles()
    {
        $logDir = $this->directoryList->getPath(DirectoryList::LOG);
        if (!is_dir($logDir)) {
            // Unmounted or missing log directory: don't take that as every file
            // having been deleted.
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $stateTable = $this->resourceConnection->getTableName('gaurav_logmonitor_state');

        $deleted = [];
        foreach ($connection->fetchCol($connection->select()->from($stateTable, ['file_name'])) as $fileName) {
            if (!is_file($logDir . DIRECTORY_SEPARATOR . $fileName)) {
                $deleted[] = $fileName;
            }
        }

        foreach (array_chunk($deleted, self::DELETE_CHUNK_SIZE) as $chunk) {
            $connection->delete($stateTable, ['file_name IN (?)' => $chunk]);
        }
    }
}
