<?php
namespace Gaurav\LogMonitor\Controller\Adminhtml\Alerts;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\RawFactory;
use Gaurav\LogMonitor\Model\AlertFactory;

class View extends Action
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
        
        if ($alert->getId()) {
            // Output the full trace inside <pre> tags for clean developer formatting
            $html = '<h2>Log Severity: ' . $alert->getSeverity() . '</h2>';
            $html .= '<pre style="background:#1e1e1e; color:#d4d4d4; padding:20px; border-radius:5px; white-space: pre-wrap; word-wrap: break-word;">';
            $html .= htmlspecialchars($alert->getFullTrace());
            $html .= '</pre>';
            
            $result->setContents($html);
        } else {
            $result->setContents('Error details not found.');
        }

        return $result;
    }
}