<?php
namespace Gaurav\LogMonitor\Model\ResourceModel;

use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Alert extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('gaurav_logmonitor_alerts', 'entity_id');
    }

    /**
     * Which of $hashes already have a row for $logFile.
     *
     * @return string[]
     */
    public function getExistingHashes($logFile, array $hashes)
    {
        if (empty($hashes)) {
            return [];
        }

        $connection = $this->getConnection();
        return $connection->fetchCol(
            $connection->select()
                ->from($this->getMainTable(), ['error_hash'])
                ->where('log_file = ?', $logFile)
                ->where('error_hash IN (?)', $hashes)
        );
    }

    /**
     * @return int
     */
    public function countAlerts()
    {
        $connection = $this->getConnection();
        return (int)$connection->fetchOne(
            $connection->select()->from($this->getMainTable(), [new Expression('COUNT(*)')])
        );
    }

    /**
     * Insert new alerts and merge repeats into existing ones in a single query.
     *
     * Rows are matched on the (log_file, error_hash) unique key. For an existing
     * row only the count and the first/last-seen window change - its severity,
     * preview and trace stay as first recorded.
     *
     * @param array $rows each with log_file, error_hash, severity, error_preview,
     *                    full_trace, occurrence_count, first_seen_at, last_seen_at
     * @return int affected rows
     */
    public function upsertAlerts(array $rows)
    {
        if (empty($rows)) {
            return 0;
        }

        return $this->getConnection()->insertOnDuplicate(
            $this->getMainTable(),
            $rows,
            [
                'occurrence_count' => new Expression('occurrence_count + VALUES(occurrence_count)'),
                'first_seen_at' => new Expression('LEAST(first_seen_at, VALUES(first_seen_at))'),
                'last_seen_at' => new Expression('GREATEST(last_seen_at, VALUES(last_seen_at))'),
            ]
        );
    }
}
