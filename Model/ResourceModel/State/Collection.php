<?php
namespace Gaurav\LogMonitor\Model\ResourceModel\State;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'entity_id';

    protected function _construct()
    {
        $this->_init(
            \Gaurav\LogMonitor\Model\State::class,
            \Gaurav\LogMonitor\Model\ResourceModel\State::class
        );
    }
}