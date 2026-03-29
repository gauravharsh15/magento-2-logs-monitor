<?php
namespace Gaurav\LogMonitor\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Alert extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('gaurav_logmonitor_alerts', 'entity_id');
    }
}