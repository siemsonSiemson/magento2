<?php
namespace Riskified\Decider\Api;

use \Magento\Store\Model\ScopeInterface as ScopeInterface;

class Config
{
    private $version;
    private $_scopeConfig;
    private $cookieManager;
    private $fullModuleList;
    private $checkoutSession;
    private $store;

    const BEACON_URL = 'beacon.riskified.com';

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager,
        \Magento\Framework\Module\FullModuleList $fullModuleList,
        \Magento\Checkout\Model\Session $checkoutSession
    ) {
        $this->_scopeConfig     = $scopeConfig;
        $this->cookieManager    = $cookieManager;
        $this->fullModuleList   = $fullModuleList;
        $this->checkoutSession  = $checkoutSession;
    }

    public function isEnabled()
    {
        return $this->_scopeConfig->getValue('riskified/riskified_general/enabled');
    }

    public function getHeaders()
    {
        return [
            'headers' => [
                'X_RISKIFIED_VERSION:' . $this->version
            ]
        ];
    }

    public function getAuthToken()
    {
        return $this->_scopeConfig->getValue(
            'riskified/riskified/key',
            $this->getStore()
        );
    }

    public function getConfigStatusControlActive()
    {
        return $this->_scopeConfig->getValue(
            'riskified/riskified/order_status_sync',
            $this->getStore()
        );
    }

    public function getConfigEnv()
    {
        return '\Riskified\Common\Env::' . $this->_scopeConfig->getValue(
                'riskified/riskified/env',
                $this->getStore()
            );
    }

    public function getSessionId()
    {
        return $this->checkoutSession->getQuoteId();
    }

    public function getConfigEnableAutoInvoice()
    {
        return $this->_scopeConfig->getValue(
            'riskified/riskified/auto_invoice_enabled',
            $this->getStore()
        );
    }

    public function getConfigAutoInvoiceCaptureCase()
    {
        return $this->_scopeConfig->getValue(
            'riskified/riskified/auto_invoice_capture_case',
            $this->getStore()
        );
    }

    public function getConfigBeaconUrl()
    {
        return self::BEACON_URL;
    }

    public function getShopDomain()
    {
        return $this->_scopeConfig->getValue(
            'riskified/riskified/domain',
            $this->getStore()
        );
    }

    public function getExtensionVersion()
    {
        $moduleConfig = $this->fullModuleList->getOne('Riskified_Decider');
        return $moduleConfig['setup_version'];
    }

    public function getDeclinedState()
    {
        return $this->_scopeConfig->getValue(
            'riskified/riskified/declined_state',
            $this->getStore()
        );
    }

    public function getDeclinedStatus()
    {
        $state = $this->getDeclinedState();
        return $this->_scopeConfig->getValue(
            'riskified/riskified/declined_status_' . $state,
            $this->getStore()
        );
    }

    public function getApprovedState()
    {
        return $this->_scopeConfig->getValue(
            'riskified/riskified/approved_state',
            $this->getStore()
        );
    }

    public function getApprovedStatus()
    {
        $state = $this->getApprovedState();
        return $this->_scopeConfig->getValue(
            'riskified/riskified/approved_status_' . $state,
            $this->getStore()
        );
    }

    public function isLoggingEnabled()
    {
        return (bool)$this->_scopeConfig->getValue(
            'riskified/riskified/debug_logs',
            $this->getStore()
        );
    }

    public function isAutoInvoiceEnabled()
    {
        return (bool)$this->_scopeConfig->getValue(
            'riskified/riskified/auto_invoice_enabled',
            $this->getStore()
        );
    }

    public function getInvoiceCaptureCase()
    {
        $captureCase = $this->_scopeConfig->getValue(
            'riskified/riskified/auto_invoice_capture_case',
            $this->getStore()
        );

        $availableStatuses = [
            \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE,
            \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE
        ];

        if (!in_array($captureCase, $availableStatuses)) {
            $captureCase = \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE;
        }

        return $captureCase;
    }

    public function getCaptureCase()
    {
        $captureCase = $this->_scopeConfig->getValue(
            'riskified/riskified/auto_invoice_capture_case',
            $this->getStore()
        );

        $avialableStatuses =  [
            \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE,
            \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE
        ];

        if (!in_array($captureCase, $avialableStatuses)) {
            $captureCase = \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE;
        }

        return $captureCase;
    }

    public function isDeclineNotificationEnabled()
    {
        return (bool)$this->_scopeConfig->getValue(
            'riskified/decline_notification/enabled',
            $this->getStore()
        );
    }

    public function getDeclineNotificationSender()
    {
        return $this->_scopeConfig->getValue(
            'riskified/decline_notification/email_identity',
            $this->getStore()
        );
    }

    public function getDeclineNotificationSenderEmail()
    {
        return $this->_scopeConfig->getValue(
            'trans_email/ident_' . $this->getDeclineNotificationSender() . '/email',
            $this->getStore()
        );
    }

    public function getDeclineNotificationSenderName()
    {
        return $this->_scopeConfig->getValue(
            'trans_email/ident_' . $this->getDeclineNotificationSender() . '/name',
            $this->getStore()
        );
    }

    public function getDeclineNotificationSubject()
    {
        return $this->_scopeConfig->getValue(
            'riskified/decline_notification/title',
            $this->getStore()
        );
    }

    public function getDeclineNotificationContent()
    {
        return $this->_scopeConfig->getValue(
            'riskified/decline_notification/content',
            $this->getStore()
        );
    }

    public function setStore($order)
    {
        $this->store = $order->getStore();
    }

    public function getStore()
    {
        return (!is_null($this->store)) ? $this->store : ScopeInterface::SCOPE_STORES;
    }
}
