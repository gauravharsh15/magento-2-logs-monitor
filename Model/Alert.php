<?php
namespace Gaurav\LogMonitor\Model;

use Magento\Framework\Model\AbstractModel;

class Alert extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\Gaurav\LogMonitor\Model\ResourceModel\Alert::class);
    }
}