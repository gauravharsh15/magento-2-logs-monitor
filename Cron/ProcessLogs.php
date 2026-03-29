<?php
namespace Gaurav\LogMonitor\Cron;

use Gaurav\LogMonitor\Model\LogReader;

class ProcessLogs
{
    protected $logReader;

    public function __construct(LogReader $logReader)
    {
        $this->logReader = $logReader;
    }

    public function execute()
    {
        $this->logReader->execute();
    }
}