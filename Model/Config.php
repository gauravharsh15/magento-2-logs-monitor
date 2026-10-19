<?php
namespace Gaurav\LogMonitor\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Gaurav\LogMonitor\Model\Config\Source\Severity;

/**
 * All settings are read at default (global) scope: the scanner and alert jobs
 * run from cron for the whole server, with no store context to resolve a
 * website/store-view value against.
 */
class Config
{
    const XML_PATH_ENABLED = 'logmonitor/general/enabled';
    const XML_PATH_IGNORE_FILES = 'logmonitor/general/ignore_files';
    const XML_PATH_MIN_SEVERITY = 'logmonitor/general/min_severity';
    const XML_PATH_GROUP_SIMILAR = 'logmonitor/general/group_similar';
    const XML_PATH_SCAN_ARCHIVES = 'logmonitor/general/scan_archives';
    const XML_PATH_RETENTION_DAYS = 'logmonitor/general/retention_days';
    const XML_PATH_RECIPIENT_EMAILS = 'logmonitor/alerts/recipient_emails';
    const XML_PATH_THRESHOLD = 'logmonitor/alerts/threshold';
    const XML_PATH_RENOTIFY_HOURS = 'logmonitor/alerts/renotify_hours';

    const DEFAULT_MIN_SEVERITY = 'NOTICE';

    protected $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    public function isEnabled()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /**
     * File names or shell-style patterns (e.g. "debug.log", "payment_*.log").
     */
    public function getIgnoredFiles()
    {
        return $this->splitList($this->scopeConfig->getValue(self::XML_PATH_IGNORE_FILES));
    }

    /**
     * Lowest Monolog level that gets recorded. Entries with an unrecognised
     * level are always recorded regardless.
     */
    public function getMinSeverity()
    {
        $severity = strtoupper((string)$this->scopeConfig->getValue(self::XML_PATH_MIN_SEVERITY));
        return isset(Severity::LEVELS[$severity]) ? $severity : self::DEFAULT_MIN_SEVERITY;
    }

    /**
     * Whether numbers, IDs, hashes and email addresses are ignored when
     * deciding if two errors are the same.
     */
    public function isGroupSimilarEnabled()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_GROUP_SIMILAR);
    }

    /**
     * Valid recipient addresses. Blank entries (e.g. from a trailing comma) and
     * malformed ones are dropped here, since a single bad address makes the
     * whole send fail.
     *
     * There's deliberately no fallback to the store's contact address: that is
     * often a customer-service inbox, and these emails contain error details.
     */
    public function getRecipientEmails()
    {
        $emails = $this->splitList($this->scopeConfig->getValue(self::XML_PATH_RECIPIENT_EMAILS));

        return array_values(array_filter($emails, function ($email) {
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        }));
    }

    public function getAlertThreshold()
    {
        $threshold = (int)$this->scopeConfig->getValue(self::XML_PATH_THRESHOLD);
        return $threshold > 0 ? $threshold : 1;
    }

    /**
     * Minimum hours between two emails about the same error. 0 disables the
     * cooldown, so a recurring error is included in every hourly email.
     */
    public function getRenotifyHours()
    {
        return max(0, (int)$this->scopeConfig->getValue(self::XML_PATH_RENOTIFY_HOURS));
    }

    /**
     * Whether to read the unread tail of a log's rotated copy (exception.log.1,
     * exception.log.1.gz, ...) when the live file is rotated. Off by default.
     */
    public function shouldScanArchives()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_SCAN_ARCHIVES);
    }

    /**
     * Alert retention window in days. 0 (or unset) disables automatic cleanup.
     */
    public function getRetentionDays()
    {
        return max(0, (int)$this->scopeConfig->getValue(self::XML_PATH_RETENTION_DAYS));
    }

    /**
     * Convert a comma-separated setting to a trimmed list with blanks removed.
     */
    protected function splitList($value)
    {
        if ($value === null || trim($value) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
    }
}
