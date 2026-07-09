<?php
namespace Gaurav\LogMonitor\Cron;

use Magento\Framework\App\ResourceConnection;
use Gaurav\LogMonitor\Model\Config;

class CleanupAlerts
{
    protected $resourceConnection;
    protected $config;

    public function __construct(
        ResourceConnection $resourceConnection,
        Config $config
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->config = $config;
    }

    public function execute()
    {
        $retentionDays = $this->config->getRetentionDays();
        if ($retentionDays <= 0) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $alertsTable = $this->resourceConnection->getTableName('gaurav_logmonitor_alerts');

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

        $connection->delete($alertsTable, ['last_seen_at < ?' => $cutoff]);
    }
}
