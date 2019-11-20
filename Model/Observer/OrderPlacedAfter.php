<?php

namespace Riskified\Decider\Model\Observer;

use Magento\Framework\Event\ObserverInterface;
use Riskified\Decider\Model\Api\Builder\Advice as AdviceBuilder;
use Riskified\Decider\Model\Logger\Order as OrderLogger;
use Riskified\Decider\Model\Api\Order as OrderApi;
use Riskified\Decider\Model\Api\Api;

class OrderPlacedAfter implements ObserverInterface
{
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
     * OrderPlacedAfter constructor.
     * @param AdviceBuilder $adviceBuilder
     * @param OrderLogger $logger
     * @param OrderApi $orderApi
     * @param Api $api
     */
    public function __construct(
        AdviceBuilder $adviceBuilder,
        OrderLogger $logger,
        OrderApi $orderApi,
        Api $api
    ) {
        $this->adviceBuilder = $adviceBuilder;
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

        //check order whether is fraud and adjust event action.
        $isFraud = $this->isOrderFraud($quote);
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
                $this->_orderApi->post($order, $action);
            } catch (\Exception $e) {
                $this->_logger->critical($e);
            }
        } else {
            $this->_logger->debug(__("No data found"));
        }
    }

    /**
     * Based on Riskified Advise Call returns fraud status. If positive fraud incident is saved in db (additional data).
     * @param $quote
     * @return bool
     */
    private function isOrderFraud($quote)
    {
        $this->logger->log('First Riskified fraud checking for Quote: ' . $quote->getEntityId() . '. Process withing OrderPlacedAfter observer.');
        $this->adviceBuilder->build(['quote_id' => $quote->getEntityId()]);
        $callResponse = $this->adviceBuilder->request();
        $status = $callResponse->checkout->status;
        $authType = $callResponse->checkout->authentication_type->auth_type;

        if($status != "captured"){
            $this->logger->log('Quote: ' . $quote->getEntityId() . ' is fraud - verified by Riskified.');
            $isFraud = true;
            $paymentDetails = array('auth_type' => $authType, 'status' => $status);
            //saves fraud incident details in quote Payment (additional data)
            $this->updateQuotePaymentDetailsInDb($quote->getEntityId(), $paymentDetails);
        }else{
            $isFraud = false;
            $this->logger->log('Quote: ' . $quote->getEntityId() . ' is not a fraud - verified by Riskified.');
        }

        return $isFraud;
    }

    /**
     * Saves quote payment details (additional data).
     * @param $quoteId
     * @param $paymentDetails
     * @throws \Exception
     */
    protected function updateQuotePaymentDetailsInDb($quoteId, $paymentDetails)
    {
        $quote = $this->registry->registry($quoteId);
        if(isset($quote)){
            $this->logger->log('Quote ' . $quoteId . ' found - saving fraud details as additional quotePayment data in db.');
            $quotePayment = $quote->getPayment();
            $currentDate = date('Y-m-d H:i:s', time());
            $additionalData = $quotePayment->getAdditionalData();
            //avoid overwriting quotePayment additional data
            if(is_array($additionalData)){
                $additionalData[$currentDate] = $paymentDetails;
                $additionalData = json_encode($additionalData);
            }else{
                $additionalData = [$currentDate =>$paymentDetails];
                $additionalData = json_encode($additionalData);
            }
            try{
                $quotePayment->setAdditionalData($additionalData);
                $quotePayment->save();
            }catch(RuntimeException $e){
                $this->logger->log('Cannot save quotePayment additional data ' . $e->getMessage());
            }
        }else{
            $this->logger->log('Quote ' . $quoteId . ' not found to save additional quotePayment data in db.');
        }
    }
}
