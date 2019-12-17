<?php

namespace Riskified\Decider\Model\Observer;

use Riskified\Decider\Model\Api\Builder\Advice as AdviceBuilder;
use Riskified\Decider\Model\Logger\Order as OrderLogger;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Riskified\Decider\Model\Api\Order as OrderApi;
use Magento\Framework\Event\ObserverInterface;
use Riskified\Decider\Model\Api\Api;

class OrderPlacedAfter implements ObserverInterface
{
    const XML_ADVISE_ENABLED = 'riskified/riskified_advise_process/enabled';

    private $paymentMethods = ['adyen_cc'];

    /**
     * @var AdviceBuilder
     */
    private $adviceBuilder;
    /**
     * @var OrderLogger
     */
    private $logger;

    /**
     * @var OrderApi
     */
    private $orderApi;

    /**
     * @var Api
     */
    private $api;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * OrderPlacedAfter constructor.
     * @param AdviceBuilder $adviceBuilder
     * @param OrderLogger $logger
     * @param OrderApi $orderApi
     * @param Api $api
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        AdviceBuilder $adviceBuilder,
        OrderLogger $logger,
        OrderApi $orderApi,
        Api $api
    ) {
        $this->adviceBuilder = $adviceBuilder;
        $this->scopeConfig = $scopeConfig;
        $this->orderApi = $orderApi;
        $this->logger = $logger;
        $this->api = $api;
        $this->api->initSdk();
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $order = $observer->getOrder();
        $quote = $observer->getQuote();
        $quotePayment = $quote->getPayment();
        $paymetMethod = $quotePayment->getMethod();

        //check current paymentMethode is included as valid to trigger 3DSecure here
        if(in_array($paymetMethod, $this->paymentMethods) == 1){
            $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
            $adviseEnabled = $this->scopeConfig->getValue(self::XML_ADVISE_ENABLED, $storeScope);
            //check whether Riskified Advise is enabled in admin settings
            if($adviseEnabled == 1){
                //check order whether is fraud and adjust event action.
                $isFraud = $this->isOrderFraud($quote);
            }else{
                //when advise is enabled(admin) but payment method doesn't need 3DSec
                $isFraud = false;
            }
        }else {
            $isFraud = false;
        }

        //send order to proper Riskified endpoint
        if($isFraud === true){
            $action = Api::ACTION_CHECKOUT_DENIED;
        }else{
            $action = Api::ACTION_UPDATE;
        }

        if (!$order) {
            return;
        }

        if ($order->dataHasChangedFor('state')) {
            try {
                $this->orderApi->post($order, $action);
            } catch (\Exception $e) {
                $this->logger->critical($e);
            }
        } else {
            $this->logger->debug(__("No data found"));
        }
    }

    /**
     * Based on Riskified Advise Call returns fraud status. If positive fraud incident is saved in db (additional data).
     * @param $quote
     * @return bool
     */
    private function isOrderFraud($quote)
    {
        $this->logger->addInfo(sprintf(__('advise_log_first_fraud'), $quote->getEntityId()));
        $this->adviceBuilder->build(['quote_id' => $quote->getEntityId()]);
        $callResponse = $this->adviceBuilder->request();
        
        //in case when riskified call return error
        if(!isset($callResponse->checkout)){
            $apiCallResponse = json_decode($callResponse);
            $logMessage = sprintf('Payment Refused - Riskified error. Status: %s. Error content: %s', $apiCallResponse->status, $apiCallResponse->error);
            $this->logger->log($logMessage);
            $isFraud = false;
            return $isFraud;
        }
        $status = $callResponse->checkout->status;
        $authType = $callResponse->checkout->authentication_type->auth_type;
        if($status != "captured"){
            $this->logger->addInfo(sprintf(__('advise_log_quote_fraud'), $quote->getEntityId()));
            $isFraud = true;
            $paymentDetails = array(
                'date' => $currentDate = date('Y-m-d H:i:s', time()),
                'is_fraud' => 'true',
                'auth_type' => $authType,
                'status' => $status
            );
            //saves fraud incident details in quote Payment (additional data)
            $this->updateQuotePaymentDetailsInDb($quote, $paymentDetails);
        }else{
            $isFraud = false;
            $this->logger->addInfo(sprintf(__('advise_log_quote_not_fraud'), $quote->getEntityId()));
        }

        return $isFraud;
    }

    /**
     * Saves quote payment details (additional data).
     * @param $quoteId
     * @param $paymentDetails
     * @throws \Exception
     */
    protected function updateQuotePaymentDetailsInDb($quote, $paymentDetails)
    {
        if(isset($quote)){
            $this->logger->addInfo(sprintf(__('advise_log_quote_found'), $quote->getEntityId()));
            $quotePayment = $quote->getPayment();
            $additionalData = $quotePayment->getAdditionalData();
            //avoid overwriting quotePayment additional data
            if(is_array($additionalData)){
                $additionalData['fraud'] = $paymentDetails;
                $additionalData = json_encode($additionalData);
            }else{
                $additionalData = ['fraud' => $paymentDetails];
                $additionalData = json_encode($additionalData);
            }
            try{
                $quotePayment->setAdditionalData($additionalData);
                $quotePayment->save();
            }catch(RuntimeException $e){
                $this->logger->addInfo(sprintf(__('advise_log_cannot_save'), $e->getMessage()));
            }
        }else{
            $this->logger->addInfo(sprintf(__('advise_log_no_quote_found'), $quote->getEntityId()));
        }
    }
}
