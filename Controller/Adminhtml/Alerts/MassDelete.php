<?php
namespace Gaurav\LogMonitor\Controller\Adminhtml\Alerts;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Ui\Component\MassAction\Filter;
use Gaurav\LogMonitor\Model\ResourceModel\Alert\CollectionFactory;
use Magento\Framework\Controller\ResultFactory;

class MassDelete extends Action
{
    /**
     * Using the custom ACL we defined
     */
    const ADMIN_RESOURCE = 'Gaurav_LogMonitor::alerts';

    protected $filter;
    protected $collectionFactory;

    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory
    ) {
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            // Get the collection of selected records from the grid
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $collectionSize = $collection->getSize();

            // Iterate and delete
            foreach ($collection as $alert) {
                $alert->delete();
            }

            $this->messageManager->addSuccessMessage(__('A total of %1 alert(s) have been deleted.', $collectionSize));
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        // Redirect back to the grid
        /** @var \Magento\Backend\Model\View\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $resultRedirect->setPath('*/*/');
    }
}