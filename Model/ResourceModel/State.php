<?php
namespace Gaurav\LogMonitor\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class State extends AbstractDb
{
    protected function _construct()
    {
        // Table name and primary key
        $this->_init('gaurav_logmonitor_state', 'entity_id');
    }
}