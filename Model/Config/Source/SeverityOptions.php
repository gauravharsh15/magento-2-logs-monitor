<?php
namespace Gaurav\LogMonitor\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Gaurav\LogMonitor\Model\ResourceModel\Alert\CollectionFactory;

class SeverityOptions implements OptionSourceInterface
{
    protected $collectionFactory;

    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        CollectionFactory $collectionFactory
    ) {
        $this->collectionFactory = $collectionFactory;
    }

    /**
     * Get distinct severity levels dynamically from the database
     *
     * @return array
     */
    public function toOptionArray()
    {
        $options = [];
        
        // Load the alert collection
        $collection = $this->collectionFactory->create();
        
        // Run a highly optimized query to get only unique severity values
        $collection->getSelect()
            ->reset(\Magento\Framework\DB\Select::COLUMNS)
            ->columns('severity')
            ->where('severity IS NOT NULL')
            ->where('severity != ?', '')
            ->group('severity');

        // Loop through the distinct results and build the dropdown array
        foreach ($collection as $item) {
            $severity = $item->getData('severity');
            $options[] = [
                'value' => $severity,
                // ucfirst(strtolower()) turns "CRITICAL" into "Critical" for a cleaner UI
                'label' => ucfirst(strtolower($severity)) 
            ];
        }

        return $options;
    }
}