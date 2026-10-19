<?php
namespace Gaurav\LogMonitor\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\App\Area;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Gaurav\LogMonitor\Model\Config;
use Gaurav\LogMonitor\Model\Config\Source\Severity;
use Gaurav\LogMonitor\Model\LogReader;
use Psr\Log\LoggerInterface;

class SendAlerts
{
    /**
     * Alerts rendered in one email. The rest are summarised as "N more" (and
     * still marked notified), so the first run over a large backlog sends one
     * readable digest instead of a multi-megabyte email.
     */
    const MAX_ALERTS_PER_EMAIL = 50;

    /**
     * IDs read and updated per query when marking the alerts not shown in the
     * email as notified, so memory stays flat however many are pending.
     */
    const UPDATE_CHUNK_SIZE = 1000;

    protected $resourceConnection;
    protected $transportBuilder;
    protected $directoryList;
    protected $config;
    protected $storeManager;
    protected $logger;

    public function __construct(
        ResourceConnection $resourceConnection,
        TransportBuilder $transportBuilder,
        DirectoryList $directoryList,
        Config $config,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->transportBuilder = $transportBuilder;
        $this->directoryList = $directoryList;
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    public function execute()
    {
        // 1. Check Master Kill Switch
        if (!$this->config->isEnabled()) {
            return;
        }

        $recipientEmails = $this->config->getRecipientEmails();
        if (empty($recipientEmails)) {
            // Alerts stay pending, so they go out once an address is configured.
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $alertsTable = $this->resourceConnection->getTableName('gaurav_logmonitor_alerts');

        // 2. Which alerts are due.
        // Comparing against last_notified_count (not just is_notified=0) means an
        // error that was already emailed once will re-alert if it recurs at least
        // $threshold more times, instead of being suppressed forever after the
        // first notification. Written as an addition rather than
        // occurrence_count - last_notified_count, which errors out on UNSIGNED
        // columns if the result would ever be negative.
        $pendingSelect = $connection->select()
            ->from($alertsTable, [])
            ->where('occurrence_count >= last_notified_count + ?', $this->config->getAlertThreshold());

        // An error that was already emailed only comes back after the re-alert
        // interval, so one that recurs all day is in one email a day, not every
        // hourly one.
        $renotifyHours = $this->config->getRenotifyHours();
        if ($renotifyHours > 0) {
            $pendingSelect->where(
                'last_notified_at IS NULL OR last_notified_at <= ?',
                gmdate('Y-m-d H:i:s', time() - $renotifyHours * 3600)
            );
        }

        // Totals are counted in SQL, and only the alerts shown in the email are
        // loaded, so memory doesn't grow with the number of pending alerts.
        $totals = $connection->fetchRow(
            (clone $pendingSelect)->columns([
                'total' => new Expression('COUNT(*)'),
                'new_count' => new Expression('SUM(last_notified_count = 0)'),
                'max_id' => new Expression('MAX(entity_id)'),
            ])
        );
        $total = (int)$totals['total'];
        if ($total === 0) {
            return;
        }

        // Most severe first, then the ones that happened most since the last email.
        // full_trace is intentionally excluded - the email only needs error_preview.
        $shownAlerts = $connection->fetchAll(
            (clone $pendingSelect)
                ->columns([
                    'entity_id',
                    'log_file',
                    'error_preview',
                    'severity',
                    'occurrence_count',
                    'last_notified_count',
                    'first_seen_at',
                    'last_seen_at',
                ])
                ->order(new Expression($this->getSeverityRankSql($connection) . ' DESC'))
                ->order(new Expression('(CAST(occurrence_count AS SIGNED) - CAST(last_notified_count AS SIGNED)) DESC'))
                ->limit(self::MAX_ALERTS_PER_EMAIL)
        );

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

        // 4. Prepare email variables.
        // Magento email templates have no {{loop}} directive, so the alert list is
        // built here in PHP and handed over as one pre-rendered HTML string.
        $alertsHtml = $this->buildAlertsHtml($shownAlerts, $total);
        if ($this->countAlerts($connection, $alertsTable) >= LogReader::MAX_ALERT_ROWS) {
            $alertsHtml = $this->buildLimitWarningHtml() . $alertsHtml;
        }

        $templateVars = [
            'store_url' => $this->getStoreUrl(),
            'hostname' => gethostname() ?: 'unknown',
            'file_size' => round($totalSizeBytes / 1048576, 2) . ' MB (Total)',
            'last_modified' => $latestModified > 0 ? gmdate('Y-m-d H:i:s', $latestModified) . ' UTC' : 'Unknown',
            'error_count' => $total,
            'new_count' => (int)$totals['new_count'],
            'recurring_count' => $total - (int)$totals['new_count'],
            'alerts_html' => $alertsHtml,
        ];

        // 5. Send to the configured recipients
        try {
            $transport = $this->transportBuilder
                ->setTemplateIdentifier('gaurav_logmonitor_alert_template')
                ->setTemplateOptions(['area' => Area::AREA_ADMINHTML, 'store' => Store::DEFAULT_STORE_ID])
                ->setTemplateVars($templateVars)
                ->setFromByScope('general');

            foreach ($recipientEmails as $email) {
                $transport->addTo($email);
            }

            $transport->getTransport()->sendMessage();
        } catch (\Exception $e) {
            // The alerts stay pending, so the next hourly run retries them.
            $this->logger->error('LogMonitor: could not send the alert email: ' . $e->getMessage());
            return;
        }

        // 6. Mark everything that was due as notified.
        $this->markNotified($connection, $alertsTable, $pendingSelect, $shownAlerts, (int)$totals['max_id']);
    }

    /**
     * Snapshot each alert's occurrence_count as of this email, so future
     * recurrences are measured from here.
     *
     * The alerts shown get the count that was displayed, so occurrences a
     * concurrent scan adds in between still count towards the next alert. The
     * rest are updated in ID chunks; alerts created after the email was built
     * (ID above $maxId) are left for the next one.
     */
    protected function markNotified($connection, $alertsTable, Select $pendingSelect, array $shownAlerts, $maxId)
    {
        $notifiedAt = gmdate('Y-m-d H:i:s');

        $countsById = [];
        foreach ($shownAlerts as $alert) {
            $countsById[(int)$alert['entity_id']] = (int)$alert['occurrence_count'];
        }
        if ($countsById) {
            $connection->update(
                $alertsTable,
                [
                    'is_notified' => 1,
                    'last_notified_count' => $connection->getCaseSql('entity_id', $countsById),
                    'last_notified_at' => $notifiedAt,
                ],
                ['entity_id IN (?)' => array_keys($countsById)]
            );
        }

        $lastId = 0;
        do {
            $idSelect = (clone $pendingSelect)
                ->columns(['entity_id'])
                ->where('entity_id > ?', $lastId)
                ->where('entity_id <= ?', $maxId)
                ->order('entity_id ASC')
                ->limit(self::UPDATE_CHUNK_SIZE);
            if ($countsById) {
                $idSelect->where('entity_id NOT IN (?)', array_keys($countsById));
            }

            $ids = $connection->fetchCol($idSelect);
            if (!$ids) {
                break;
            }

            $connection->update(
                $alertsTable,
                [
                    'is_notified' => 1,
                    'last_notified_count' => new Expression('occurrence_count'),
                    'last_notified_at' => $notifiedAt,
                ],
                ['entity_id IN (?)' => $ids]
            );
            $lastId = (int)end($ids);
        } while (count($ids) === self::UPDATE_CHUNK_SIZE);
    }

    /**
     * Render each alert row's HTML here in PHP, since the .html email template
     * itself has no loop construct available to it.
     */
    protected function buildAlertsHtml(array $alerts, $total)
    {
        $html = '';

        foreach ($alerts as $alert) {
            $occurrenceCount = (int)$alert['occurrence_count'];
            $sinceLastEmail = $occurrenceCount - (int)$alert['last_notified_count'];
            $isNew = (int)$alert['last_notified_count'] === 0;

            $severity = htmlspecialchars((string)$alert['severity'], ENT_QUOTES, 'UTF-8');
            $severityColor = $this->getSeverityColor($alert['severity']);
            $statusLabel = $isNew ? 'NEW' : 'RECURRING';
            $countText = $isNew
                ? "Seen {$occurrenceCount} time" . ($occurrenceCount === 1 ? '' : 's')
                : "+{$sinceLastEmail} since last alert ({$occurrenceCount} total)";
            $logFile = htmlspecialchars($alert['log_file'], ENT_QUOTES, 'UTF-8');
            $errorPreview = htmlspecialchars($alert['error_preview'], ENT_QUOTES, 'UTF-8');
            $firstSeenAt = htmlspecialchars($alert['first_seen_at'], ENT_QUOTES, 'UTF-8');
            $lastSeenAt = htmlspecialchars($alert['last_seen_at'], ENT_QUOTES, 'UTF-8');

            $html .= <<<HTML
<div style="margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px dashed #eee;">
    <div style="margin-bottom: 5px;">
        <span style="background-color: {$severityColor}; color: #ffffff; padding: 3px 8px; border-radius: 3px; font-size: 12px; font-weight: bold;">{$severity}</span>
        <span style="background-color: #ffeeee; color: #cc0000; padding: 3px 8px; border-radius: 3px; font-size: 12px; font-weight: bold; margin-left: 6px;">{$statusLabel} &middot; {$countText}</span>
        <span style="font-size: 12px; color: #666; margin-left: 10px;">File: {$logFile}</span>
    </div>
    <p style="font-family: monospace; font-size: 13px; background: #f9f9f9; padding: 10px; border-left: 3px solid {$severityColor}; margin: 0; word-break: break-all;">
        {$errorPreview}
    </p>
    <div style="font-size: 11px; color: #999; margin-top: 5px;">
        First seen: {$firstSeenAt} UTC | Last seen: {$lastSeenAt} UTC
    </div>
</div>
HTML;
        }

        $remaining = $total - count($alerts);
        if ($remaining > 0) {
            $html .= '<p style="font-size: 13px; color: #666;">... and ' . $remaining
                . ' more. See <strong>System &gt; Log Monitor Alerts</strong> in the admin for the full list.</p>';
        }

        return $html;
    }

    protected function buildLimitWarningHtml()
    {
        return '<p style="background: #fff3e0; border-left: 3px solid #ef6c00; padding: 10px; font-size: 13px;">'
            . '<strong>The alert list is full (' . LogReader::MAX_ALERT_ROWS . ' alerts).</strong> '
            . 'New kinds of errors are not being recorded until old alerts are deleted in '
            . '<strong>System &gt; Log Monitor Alerts</strong> or removed by the retention setting.</p>';
    }

    protected function countAlerts($connection, $alertsTable)
    {
        return (int)$connection->fetchOne(
            $connection->select()->from($alertsTable, [new Expression('COUNT(*)')])
        );
    }

    /**
     * SQL CASE giving each severity its Monolog rank; unrecognised levels
     * (UNKNOWN) rank alongside ERROR.
     */
    protected function getSeverityRankSql($connection)
    {
        $cases = [];
        foreach (Severity::LEVELS as $level => $rank) {
            $cases[$connection->quote($level)] = $rank;
        }
        return (string)$connection->getCaseSql('severity', $cases, Severity::LEVELS['ERROR']);
    }

    /**
     * Unrecognised levels (UNKNOWN) rank alongside ERROR.
     */
    protected function getSeverityRank($severity)
    {
        return Severity::LEVELS[$severity] ?? Severity::LEVELS['ERROR'];
    }

    protected function getSeverityColor($severity)
    {
        $rank = $this->getSeverityRank($severity);
        if ($rank >= Severity::LEVELS['CRITICAL']) {
            return '#b71c1c';
        }
        if ($rank >= Severity::LEVELS['ERROR']) {
            return $severity === Severity::UNKNOWN ? '#616161' : '#d84315';
        }
        if ($rank >= Severity::LEVELS['WARNING']) {
            return '#ef6c00';
        }
        return '#1565c0';
    }

    /**
     * Base URL of the default store view, so a shared inbox can tell which
     * environment (production, staging, ...) an alert came from.
     */
    protected function getStoreUrl()
    {
        try {
            $store = $this->storeManager->getDefaultStoreView() ?: $this->storeManager->getStore();
            return $store->getBaseUrl();
        } catch (\Exception $e) {
            return 'unknown';
        }
    }
}
