<?php
namespace Gaurav\LogMonitor\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\App\Area;
use Magento\Store\Model\Store;
use Magento\Framework\App\Filesystem\DirectoryList;
use Gaurav\LogMonitor\Model\Config;

class SendAlerts
{
    protected $resourceConnection;
    protected $transportBuilder;
    protected $directoryList;
    protected $config;

    public function __construct(
        ResourceConnection $resourceConnection,
        TransportBuilder $transportBuilder,
        DirectoryList $directoryList,
        Config $config
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->transportBuilder = $transportBuilder;
        $this->directoryList = $directoryList;
        $this->config = $config;
    }

    public function execute()
    {
        // 1. Check Master Kill Switch
        if (!$this->config->isEnabled()) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $alertsTable = $this->resourceConnection->getTableName('gaurav_logmonitor_alerts');
        $threshold = $this->config->getAlertThreshold();

        // 2. Fetch pending alerts that meet the threshold.
        // Comparing against last_notified_count (not just is_notified=0) means an
        // error that was already emailed once will re-alert if it recurs at least
        // $threshold more times, instead of being suppressed forever after the
        // first notification. full_trace is intentionally excluded - the email
        // template only needs error_preview, and full_trace is unbounded TEXT
        // that would otherwise bloat cron memory for no reason.
        $select = $connection->select()
            ->from($alertsTable, [
                'entity_id',
                'log_file',
                'error_hash',
                'error_preview',
                'severity',
                'occurrence_count',
                'last_notified_count',
                'first_seen_at',
                'last_seen_at',
            ])
            ->where('(occurrence_count - last_notified_count) >= ?', $threshold);

        $pendingAlerts = $connection->fetchAll($select);

        if (empty($pendingAlerts)) {
            return;
        }

        // 3. Get File Stats for the email header (Aggregating all .log files)
        $logDir = $this->directoryList->getPath(DirectoryList::LOG);
        $logFiles = glob($logDir . DIRECTORY_SEPARATOR . '*.log');
        
        $totalSizeBytes = 0;
        $latestModified = 0;
        
        if ($logFiles) {
            foreach ($logFiles as $file) {
                if (is_file($file)) {
                    $totalSizeBytes += filesize($file);
                    
                    $mtime = filemtime($file);
                    if ($mtime > $latestModified) {
                        $latestModified = $mtime;
                    }
                }
            }
        }
        
        $fileSizeMb = round($totalSizeBytes / 1048576, 2) . ' MB (Total)';
        $lastModified = $latestModified > 0 ? date('Y-m-d H:i:s', $latestModified) : 'Unknown';

        // 4. Prepare email variables
        $templateVars = [
            'file_size' => $fileSizeMb,
            'last_modified' => $lastModified,
            'error_count' => count($pendingAlerts),
            'alerts' => $pendingAlerts
        ];

        // 5. Fetch custom recipients from system config
        $recipientEmails = $this->config->getRecipientEmails();
        
        if (!empty($recipientEmails)) {
            $transport = $this->transportBuilder
                ->setTemplateIdentifier('gaurav_logmonitor_alert_template')
                ->setTemplateOptions(['area' => Area::AREA_ADMINHTML, 'store' => Store::DEFAULT_STORE_ID])
                ->setTemplateVars($templateVars)
                ->setFromByScope('general');
            
            // Add all configured recipient emails
            foreach ($recipientEmails as $email) {
                $transport->addTo($email);
            }

            try {
                $transport->getTransport()->sendMessage();
                
                // 6. Mark as notified, and snapshot the occurrence_count each alert
                // was at when this email went out, so future recurrences are
                // measured against this point rather than never re-alerting again.
                $alertIds = array_column($pendingAlerts, 'entity_id');
                $connection->update(
                    $alertsTable,
                    [
                        'is_notified' => 1,
                        'last_notified_count' => new Expression('occurrence_count'),
                    ],
                    ['entity_id IN (?)' => $alertIds]
                );
            } catch (\Exception $e) {
                // Fails silently in cron
            }
        }
    }
}