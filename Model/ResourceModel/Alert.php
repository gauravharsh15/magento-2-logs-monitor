<?php
namespace Gaurav\LogMonitor\Model\ResourceModel;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Alert extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('gaurav_logmonitor_alerts', 'entity_id');
    }

    /**
     * Load an alert by its (log_file, error_hash) composite key.
     *
     * The dedup unique key is (log_file, error_hash), not error_hash alone -
     * loading by hash only would match the wrong row when the same error text
     * appears in two different log files.
     */
    public function loadByFileAndHash(AbstractModel $object, $logFile, $hash)
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable())
            ->where('log_file = ?', $logFile)
            ->where('error_hash = ?', $hash);

        $data = $connection->fetchRow($select);
        if ($data) {
            $object->setData($data);
            $this->_afterLoad($object);
        }

        return $this;
    }
}