<?php
namespace Gaurav\LogMonitor\Controller\Adminhtml\Alerts;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Gaurav\LogMonitor\Model\AlertFactory;

class View extends Action implements HttpGetActionInterface
{
    /**
     * Without this, the default ADMIN_RESOURCE ('Magento_Backend::admin') applies,
     * which any backend-logged-in admin passes regardless of role - letting them
     * read raw stack traces (which can contain paths, tokens, DB details) even if
     * they were never granted the Log Monitor ACL resource.
     */
    const ADMIN_RESOURCE = 'Gaurav_LogMonitor::alerts';

    protected $rawResultFactory;
    protected $alertFactory;

    public function __construct(
        Context $context,
        RawFactory $rawResultFactory,
        AlertFactory $alertFactory
    ) {
        parent::__construct($context);
        $this->rawResultFactory = $rawResultFactory;
        $this->alertFactory = $alertFactory;
    }

    public function execute()
    {
        $id = (int)$this->getRequest()->getParam('id');
        $alert = $this->alertFactory->create()->load($id);

        $result = $this->rawResultFactory->create();
        $result->setHeader('Content-Type', 'text/html; charset=UTF-8', true);

        if (!$alert->getId()) {
            $result->setHttpResponseCode(404);
            $result->setContents('Error details not found.');
            return $result;
        }

        // Every value here comes from a log file, so all of it is escaped.
        $details = [
            'Log File' => $alert->getLogFile(),
            'Occurrences' => $alert->getOccurrenceCount(),
            'First Seen' => $alert->getFirstSeenAt() . ' UTC',
            'Last Seen' => $alert->getLastSeenAt() . ' UTC',
            'Last Emailed' => $alert->getLastNotifiedAt() ? $alert->getLastNotifiedAt() . ' UTC' : 'Never',
        ];

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'
            . $this->escape($alert->getSeverity() . ' - ' . $alert->getLogFile())
            . '</title></head><body style="font-family: Arial, sans-serif; margin: 20px;">';
        // Output the full trace inside <pre> tags for clean developer formatting
        $html .= '<h2>Log Severity: ' . $this->escape($alert->getSeverity()) . '</h2>';
        $html .= '<table style="border-collapse: collapse; margin-bottom: 15px; font-size: 14px;">';
        foreach ($details as $label => $value) {
            $html .= '<tr><th style="text-align: left; padding: 3px 15px 3px 0;">' . $this->escape($label)
                . '</th><td>' . $this->escape($value) . '</td></tr>';
        }
        $html .= '</table>';
        $html .= '<pre style="background:#1e1e1e; color:#d4d4d4; padding:20px; border-radius:5px; white-space: pre-wrap; word-wrap: break-word;">';
        $html .= $this->escape($alert->getFullTrace());
        $html .= '</pre></body></html>';

        $result->setContents($html);
        return $result;
    }

    protected function escape($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
