<?php
namespace Riskified\Decider\Api;

use function GuzzleHttp\Psr7\str;
use Riskified\Common\Signature;
use Riskified\OrderWebhook\Model;
use Riskified\OrderWebhook\Transport;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Order
{
    const XML_ADVISE_BIN = 'riskified/riskified_advise_process/bin';
    private $_api;
    private $_orderHelper;
    private $_context;
    private $_eventManager;
    private $_messageManager;
    private $_backendAuthSession;
    private $_orderFactory;
    private $_logger;
    private $_session;
    private $_date;
    private $_queueFactory;
    private $_scopeConfig;
    private $_apiConfig;
    private $_response;

    public function __construct(
        Api $api,
        Order\Helper $orderHelper,
        Config $apiConfig,
        Order\Log $logger,
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Backend\Model\Auth\Session $backendAuthSession,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Magento\Sales\Model\Order $orderFactory,
        \Magento\Framework\Stdlib\DateTime\DateTime $date,
        \Riskified\Decider\Model\QueueFactory $queueFactory,
        \Magento\Framework\Session\SessionManagerInterface $session,
        ScopeConfigInterface $scopeConfig

    )
    {
        $this->_api = $api;
        $this->_orderHelper = $orderHelper;
        $this->_apiConfig = $apiConfig;
        $this->_context = $context;
        $this->_eventManager = $context->getEventManager();
        $this->_backendAuthSession = $backendAuthSession;
        $this->_messageManager = $messageManager;
        $this->_orderFactory = $orderFactory;
        $this->_logger = $logger;
        $this->_session = $session;
        $this->_date = $date;
        $this->_queueFactory = $queueFactory;
        $this->_scopeConfig = $scopeConfig;

        $this->_api->initSdk();
    }

    public function post($order, $action)
    {
        if (!$this->_apiConfig->isEnabled()) {
            return;
        }

        $transport = $this->_api->getTransport();

        if (!$order) {
            throw new \Exception("Order doesn't not exists");
        }
        $this->_orderHelper->setOrder($order);
        $eventData = array(
            'order' => $order,
            'action' => $action
        );
        try {
            switch ($action) {
                case Api::ACTION_CREATE:
                    $orderForTransport = $this->load($order);
                    $this->_response = $transport->createOrder($orderForTransport);
                    break;
                case Api::ACTION_UPDATE:
                    $orderForTransport = $this->load($order);
                    $this->_response = $transport->updateOrder($orderForTransport);
                    break;
                case Api::ACTION_SUBMIT:
                    $orderForTransport = $this->load($order);
                    $this->_response = $transport->submitOrder($orderForTransport);
                    break;
                case Api::ACTION_CANCEL:
                    $orderForTransport = $this->_orderHelper->getOrderCancellation();
                    $this->_response = $transport->cancelOrder($orderForTransport);
                    break;
                case Api::ACTION_FULFILL:
                    $orderForTransport = $this->_orderHelper->getOrderFulfillments();
                    $this->_response = $transport->fulfillOrder($orderForTransport);
                    break;
                case Api::ACTION_REFUND:
                    $orderForTransport = $this->loadRefund();
                    $this->_response = $transport->refundOrder($orderForTransport);
                    break;
                case Api::ACTION_CHECKOUT_DENIED:
                    if(get_class($order) == 'Magento\Quote\Model\Quote\Interceptor'){
                        $this->_orderHelper->setQuote($order);
                        $checkoutForTransport = $this->loadQuote($order);
                    }else{
                        $checkoutForTransport = $this->loadOrder($order);
                    }
                    $this->_response = $transport->deniedCheckout($checkoutForTransport);
                    break;
            }
            $eventData['response'] = $this->_response;

            $this->_eventManager->dispatch(
                'riskified_decider_post_order_success',
                $eventData
            );
        } catch (\Riskified\OrderWebhook\Exception\CurlException $curlException) {
            $this->_raiseOrderUpdateEvent($order, 'error', null, 'Error transferring order data to Riskified');
            $this->scheduleSubmissionRetry($order, $action);

            $this->_eventManager->dispatch(
                'riskified_decider_post_order_error',
                $eventData
            );
            throw $curlException;
        } catch (\Riskified\OrderWebhook\Exception\MalformedJsonException $e) {
            if(strstr($e->getMessage(), "504") && strstr($e->getMessage(), "Status Code:")) {
                $this->_raiseOrderUpdateEvent($order, 'error', null, 'Error transferring order data to Riskified');
                $this->scheduleSubmissionRetry($order, $action);
            }
            $this->_eventManager->dispatch(
                'riskified_decider_post_order_error',
                $eventData
            );
            throw $e;
        } catch (\Exception $e) {
            $this->_eventManager->dispatch(
                'riskified_decider_post_order_error',
                $eventData
            );
            throw $e;
        }
        return $this->_response;
    }

    private function _raiseOrderUpdateEvent($order, $status, $oldStatus, $description)
    {
        $eventData = array(
            'order' => $order,
            'status' => $status,
            'old_status' => $oldStatus,
            'description' => $description
        );
        $this->_eventManager->dispatch(
            'riskified_decider_order_update',
            $eventData
        );

        $eventIdentifier = preg_replace("/[^a-z]/", '_', strtolower($status));

        $this->_eventManager->dispatch(
            'riskified_decider_order_update_' . $eventIdentifier,
            $eventData
        );
        return;
    }

    private function getCustomerSession()
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        return $objectManager->get('Magento\Customer\Model\Session');
    }

    private function loadRefund()
    {
        $refund = new Model\Refund();
        $refund->id = strval($this->_orderHelper->getOrderOrigId());
        $refundDetails = $this->_orderHelper->getRefundDetails();
        $refund->refunds = array_filter($refundDetails, 'strlen');

        return $refund;
    }

    private function load($model)
    {
        $gateway = 'unavailable';
        if ($model->getPayment()) {
            $gateway = $model->getPayment()->getMethod();
        }
        if(is_null($model->getRiskifiedCartToken())){
            $cartToken = $this->_session->getSessionId();
            //save card_token into db
            $model->setRiskifiedCartToken($cartToken);
            $model->save();
        }else{
            $cartToken = $model->getRiskifiedCartToken();
        }

        $order_array = array(
            'id' => $this->_orderHelper->getOrderOrigId(),
            'name' => $model->getIncrementId(),
            'email' => $model->getCustomerEmail(),
            'created_at' => $this->_orderHelper->formatDateAsIso8601($model->getCreatedAt()),
            'currency' => $model->getOrderCurrencyCode(),
            'updated_at' => $this->_orderHelper->formatDateAsIso8601($model->getUpdatedAt()),
            'gateway' => $gateway,
            'browser_ip' => $this->_orderHelper->getRemoteIp(),
            'note' => $model->getCustomerNote(),
            'total_price' => $model->getGrandTotal(),
            'total_discounts' => $model->getDiscountAmount(),
            'subtotal_price' => $model->getBaseSubtotalInclTax(),
            'discount_codes' => $this->_orderHelper->getDiscountCodes($model),
            'taxes_included' => true,
            'total_tax' => $model->getBaseTaxAmount(),
            'total_weight' => $model->getWeight(),
            'cancelled_at' => $this->_orderHelper->formatDateAsIso8601($this->_orderHelper->getCancelledAt()),
            'financial_status' => $model->getState(),
            'fulfillment_status' => $model->getStatus(),
            'vendor_id' => $model->getStoreId(),
            'vendor_name' => $model->getStoreName(),
            'cart_token' => $cartToken
        );
        
        if ($this->_orderHelper->isAdmin()) {
            unset($order_array['browser_ip']);
            unset($order_array['cart_token']);
            $order_array['source'] = 'admin';
        }else{
            $order_array['source'] = 'web';
        }

        $order = new Model\Order(array_filter($order_array, 'strlen'));
        $order->customer = $this->_orderHelper->getCustomer();
        $order->shipping_address = $this->_orderHelper->getShippingAddress();
        $order->billing_address = $this->_orderHelper->getBillingAddress();
        $order->payment_details = $this->_orderHelper->getPaymentDetails();
        $order->line_items = $this->_orderHelper->getLineItems();
        $order->shipping_lines = $this->_orderHelper->getShippingLines();

        if (!$this->_backendAuthSession->isLoggedIn()) {
            $order->client_details = $this->_orderHelper->getClientDetails();
        }
        return $order;
    }

    public function update($order, $status, $oldStatus, $description)
    {
        if (!$this->_apiConfig->isEnabled()) {
            return;
        }

        $this->_logger->log('Dispatching event for order ' . $order->getId() . ' with status "' . $status .
            '" old status "' . $oldStatus . '" and description "' . $description . '"');
        $eventData = array(
            'order' => $order,
            'status' => $status,
            'old_status' => $oldStatus,
            'description' => $description
        );

        $this->_eventManager->dispatch(
            'riskified_decider_order_update',
            $eventData
        );
        $eventIdentifier = preg_replace("/[^a-z]/", '_', strtolower($status));
        $this->_eventManager->dispatch(
            'riskified_decider_order_update_' . $eventIdentifier,
            $eventData
        );
        return;
    }

    public function loadOrderByOrigId($full_orig_id)
    {
        if (!$full_orig_id) {
            return null;
        }
        $magento_ids = explode("_", $full_orig_id);

        /**
         * validate if provided is is matching
        */
        $order_id = false;
        $increment_id = false;

        if (isset($magento_ids[0])) {
            $order_id = $magento_ids[0];
        }

        if (isset($magento_ids[1])) {
            $increment_id = $magento_ids[1];
        }

        if ($order_id && $increment_id) {
            return $this->_orderFactory->getCollection()
                ->addFieldToFilter('entity_id', $order_id)
                ->addFieldToFilter('increment_id', $increment_id)
                ->getFirstItem();
        }

        if (!$order_id && $increment_id) {
            return $this->_orderFactory->loadByIncrementId($increment_id);
        }

        if ($order_id) {
            return $this->_orderFactory->load($order_id);
        }

        return null;
    }

    public function postHistoricalOrders($models)
    {
        if (!$this->_apiConfig->isEnabled()) {
            return;
        }
        $orders = array();

        foreach ($models as $model) {
            $orders[] = $this->getOrder($model);
        }

        $msgs = $this->_api->getTransport()->sendHistoricalOrders($orders);
        return "Success decidery uploaded " . count($msgs) . " orders." . PHP_EOL;
    }

    public function scheduleSubmissionRetry(\Magento\Sales\Model\Order $order, $action)
    {
        $this->_logger->log("Scheduling submission retry for order " . $order->getId());

        try {
            $existingRetries = $this->_queueFactory->create()->getCollection()
                ->addFieldToFilter('order_id', $order->getId())
                ->addFieldToFilter('action', $action);

            if ($existingRetries->getSize() == 0) {
                $queue = $this->_queueFactory->create();
                $queue->addData(array(
                        'order_id' => $order->getId(),
                        'action' => $action,
                        'updated_at' => $this->_date->gmtDate()
                ))->save();

                $this->_logger->log("New retry scheduled successfully");
            }
        } catch (\Exception $e) {
            $this->_logger->logException($e);
        }
    }

    public function sendOrders($order_ids)
    {
        if (!$this->_apiConfig->isEnabled()) {
            return;
        }
        $i = 0;
        foreach ($order_ids as $order_id) {
            $order = $this->_orderFactory->load($order_id);
            try {
                $this->post($order, \Riskified\Decider\Api\Api::ACTION_SUBMIT);
                $i++;
            } catch (\Exception $e) {

            }
        }

        return $i;
    }

    private function loadOrder($model)
    {
        $gateway = 'unavailable';
        if ($model->getPayment()) {
            $gateway = $model->getPayment()->getMethod();
        }
        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        $bin = $this->_scopeConfig->getValue(self::XML_ADVISE_BIN, $storeScope);
        $order_array = [
            'id' => $model->getQuoteId(),
            'name' => $model->getIncrementId(),
            'email' => $model->getCustomerEmail(),
            'created_at' => $this->_orderHelper->formatDateAsIso8601($model->getCreatedAt()),
            'currency' => $model->getOrderCurrencyCode(),
            'updated_at' => $this->_orderHelper->formatDateAsIso8601($model->getUpdatedAt()),
            'gateway' => $gateway,
            'browser_ip' => $this->_orderHelper->getRemoteIp(),
            'note' => $model->getCustomerNote(),
            'total_price' => $model->getGrandTotal(),
            'total_discounts' => $model->getDiscountAmount(),
            'subtotal_price' => $model->getBaseSubtotalInclTax(),
            'discount_codes' => $this->_orderHelper->getDiscountCodes($model),
            'taxes_included' => true,
            'total_tax' => $model->getBaseTaxAmount(),
            'total_weight' => $model->getWeight(),
            'cancelled_at' => $this->_orderHelper->formatDateAsIso8601($this->_orderHelper->getCancelledAt()),
            'financial_status' => $model->getState(),
            'fulfillment_status' => $model->getStatus(),
            'vendor_id' => $model->getStoreId(),
            'vendor_name' => $model->getStoreName(),
            'authentication_type' => new Model\AuthenticationType([
                'auth_type' => 'fraud',
                'exemption_method' => '3ds'
            ]),
            'bin' => $bin
        ];

        if ($this->_orderHelper->getCustomerSession()->isLoggedIn()) {
            unset($order_array['browser_ip']);
            unset($order_array['cart_token']);
        }

        $payload = array_filter($order_array, 'strlen');
        $order = new Model\Checkout($payload);
        $order->customer = $this->_orderHelper->getCustomer();
        $order->shipping_address = $this->_orderHelper->getShippingAddress();
        $order->payment_details = $this->_orderHelper->getPaymentDetails();
        $order->line_items = $this->_orderHelper->getLineItems();
        $order->shipping_lines = $this->_orderHelper->getShippingLines();
        if (!$this->_backendAuthSession->isLoggedIn()) {
            $order->client_details = $this->_orderHelper->getClientDetails();
        }

        return $order;
    }

    private function loadQuote($model)
    {
        $gateway = 'unavailable';
        if ($model->getPayment()) {
            $gateway = $model->getPayment()->getMethod();
        }
        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        $bin = $this->_scopeConfig->getValue(self::XML_ADVISE_BIN, $storeScope);
        $order_array = [
            'id' => (int) $model->getQuoteId(),
            'name' => $model->getIncrementId(),
            'email' => $model->getCustomerEmail(),
            'created_at' => $this->_orderHelper->formatDateAsIso8601($model->getCreatedAt()),
            'currency' => $model->getOrderCurrencyCode(),
            'updated_at' => $this->_orderHelper->formatDateAsIso8601($model->getUpdatedAt()),
            'gateway' => $gateway,
            'note' => $model->getCustomerNote(),
            'total_price' => $model->getGrandTotal(),
            'total_discounts' => $model->getDiscountAmount(),
            'subtotal_price' => $model->getBaseSubtotalInclTax(),
            'discount_codes' => $this->_orderHelper->getQuoteDiscountCodes($model),
            'taxes_included' => true,
            'total_tax' => $model->getBaseTaxAmount(),
            'total_weight' => $model->getWeight(),
            'financial_status' => $model->getState(),
            'fulfillment_status' => $model->getStatus(),
            'vendor_id' => $model->getStoreId(),
            'vendor_name' => $model->getStoreName(),
            'authentication_type' => new Model\AuthenticationType([
                'auth_type' => 'fraud',
                'exemption_method' => '3ds'
            ]),
            'bin' => $bin
        ];

        if ($this->_orderHelper->getCustomerSession()->isLoggedIn()) {
            unset($order_array['browser_ip']);
            unset($order_array['cart_token']);
        }
        $payload = array_filter($order_array, 'strlen');
        $order = new Model\Checkout($payload);
        $order->customer = $this->_orderHelper->getQuoteCustomer();
        $order->shipping_address = $this->_orderHelper->getQuoteShippingAddress();
        $order->billing_address = $this->_orderHelper->getQuoteBillingAddress();
        $order->payment_details = $this->_orderHelper->getQuotePaymentDetails();
        $order->line_items = $this->_orderHelper->getQuoteLineItems();
        $order->shipping_lines = $this->_orderHelper->getQuoteShippingLines();
        if (!$this->_backendAuthSession->isLoggedIn()) {
            $order->client_details = $this->_orderHelper->getClientDetails();
        }

        return $order;
    }
}