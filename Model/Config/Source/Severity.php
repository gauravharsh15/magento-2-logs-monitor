<?php
namespace Gaurav\LogMonitor\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Monolog severity levels, used for the "Minimum Severity" setting and to
 * rank alerts in the email.
 */
class Severity implements OptionSourceInterface
{
    /**
     * Assigned to entries whose level couldn't be parsed. Never filtered out.
     */
    const UNKNOWN = 'UNKNOWN';

    /**
     * Level => Monolog numeric rank, lowest first.
     */
    const LEVELS = [
        'DEBUG' => 100,
        'INFO' => 200,
        'NOTICE' => 250,
        'WARNING' => 300,
        'ERROR' => 400,
        'CRITICAL' => 500,
        'ALERT' => 550,
        'EMERGENCY' => 600,
    ];

    /**
     * @return array
     */
    public function toOptionArray()
    {
        $options = [];
        foreach (array_keys(self::LEVELS) as $level) {
            $options[] = [
                'value' => $level,
                'label' => __('%1 and above', ucfirst(strtolower($level))),
            ];
        }
        return $options;
    }
}
