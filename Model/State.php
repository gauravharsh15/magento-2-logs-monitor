<?php
namespace Gaurav\LogMonitor\Model;

use Magento\Framework\Model\AbstractModel;

class State extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\Gaurav\LogMonitor\Model\ResourceModel\State::class);
    }

    public function getFileName()
    {
        return $this->getData('file_name');
    }

    public function setFileName($fileName)
    {
        return $this->setData('file_name', $fileName);
    }

    public function getLastByteOffset()
    {
        return $this->getData('last_byte_offset');
    }

    public function setLastByteOffset($offset)
    {
        return $this->setData('last_byte_offset', $offset);
    }
}