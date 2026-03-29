<?php
namespace Gaurav\LogMonitor\Model\ResourceModel\Alert;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Gaurav\LogMonitor\Model\Alert as AlertModel;
use Gaurav\LogMonitor\Model\ResourceModel\Alert as AlertResourceModel;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'entity_id';

    protected function _construct()
    {
        $this->_init(AlertModel::class, AlertResourceModel::class);
    }
}