<?php
namespace Gaurav\LogMonitor\Controller\Adminhtml\Alerts;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Gaurav\LogMonitor\Model\ResourceModel\Alert\CollectionFactory;
use Magento\Framework\Controller\ResultFactory;

/**
 * POST only: the grid's mass action always posts with a form key, and a GET
 * with excluded=false would otherwise select - and delete - every alert.
 */
class MassDelete extends Action implements HttpPostActionInterface
{
    /**
     * Using the custom ACL we defined
     */
    const ADMIN_RESOURCE = 'Gaurav_LogMonitor::alerts';

    const DELETE_CHUNK_SIZE = 1000;

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

            // Delete by ID instead of loading each alert: "Select All" on a big
            // table would otherwise pull every full_trace into memory first.
            $connection = $collection->getConnection();
            $deleted = 0;
            foreach (array_chunk($collection->getAllIds(), self::DELETE_CHUNK_SIZE) as $ids) {
                $deleted += $connection->delete($collection->getMainTable(), ['entity_id IN (?)' => $ids]);
            }

            $this->messageManager->addSuccessMessage(__('A total of %1 alert(s) have been deleted.', $deleted));
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        // Redirect back to the grid
        /** @var \Magento\Backend\Model\View\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $resultRedirect->setPath('*/*/');
    }
}
