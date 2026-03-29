<?php
namespace Gaurav\LogMonitor\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    const XML_PATH_ENABLED = 'logmonitor/general/enabled';
    const XML_PATH_IGNORE_FILES = 'logmonitor/general/ignore_files';
    const XML_PATH_RECIPIENT_EMAILS = 'logmonitor/alerts/recipient_emails';
    const XML_PATH_THRESHOLD = 'logmonitor/alerts/threshold';

    protected $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    public function isEnabled()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function getIgnoredFiles()
    {
        $files = $this->scopeConfig->getValue(self::XML_PATH_IGNORE_FILES, ScopeInterface::SCOPE_STORE);
        if (empty($files)) {
            return [];
        }
        // Convert comma-separated string to an array, clean up whitespace
        return array_map('trim', explode(',', $files));
    }

    public function getRecipientEmails()
    {
        $emails = $this->scopeConfig->getValue(self::XML_PATH_RECIPIENT_EMAILS, ScopeInterface::SCOPE_STORE);
        if (empty($emails)) {
            // Fallback to general store email
            $fallback = $this->scopeConfig->getValue('trans_email/ident_general/email', ScopeInterface::SCOPE_STORE);
            return $fallback ? [$fallback] : [];
        }
        return array_map('trim', explode(',', $emails));
    }

    public function getAlertThreshold()
    {
        $threshold = (int)$this->scopeConfig->getValue(self::XML_PATH_THRESHOLD, ScopeInterface::SCOPE_STORE);
        return $threshold > 0 ? $threshold : 1;
    }
}