<?php

namespace Riskified\Decider\Controller\Order;

class Deny extends \Riskified\Decider\Controller\AdviceHelper
{
    /**
     * Function fetches post data from order checkout payment step.
     * When 'mode' parameter is present data comes from 3D Secure Payment Authorisation Refuse and refusal details are saved in quotePayment table (additional_data). Order state is set as 'ACTION_CHECKOUT_DENIED'.
     * As a response validation status and message are returned.
     *
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\Result\Json|\Magento\Framework\Controller\ResultInterface
     * @throws \Riskified\OrderWebhook\Exception\CurlException
     * @throws \Riskified\OrderWebhook\Exception\UnsuccessfulActionException
     */
    public function execute()
    {
        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        $adviseEnabled = $this->scopeConfig->getValue(self::XML_ADVISE_ENABLED, $storeScope);
        //check whether Riskified Advise is enabled in admin settings
        if($adviseEnabled == 0){
            return  $this->resultJsonFactory->create()->setData(['advice_status' => 'disabled']);
        }
        $payload = $this->request->getParams();
        $quoteId = $this->getQuoteId($payload['quote_id']);
        $quoteFactory = $this->quoteFactory;
        $quote = $quoteFactory->create()->load($quoteId);
        if(!is_null($quote)){
            $message = sprintf(__('deny_controller_deny'), $quoteId);
            //saves 3D Secure Response data in quotePayment table (additional data)
            $payload['date'] = $currentDate = date('Y-m-d H:i:s', time());
            $this->updateQuotePaymentDetailsInDb($quote, $payload);
            //Riskified defined order as fraud - order data is send to Riskified
            $this->sendDeniedOrderToRiskified($quote);
            $this->logger->log($message);
        }else{
            $message = sprintf(__('deny_controller_not_found'), $quoteId);
            $this->logger->log($message);
        }

        return  $this->resultJsonFactory->create()->setData(['message' => $message]);
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
            $this->logger->log(sprintf(__('advise_log_quote_found'), $quote->getEntityId()));
            $quotePayment = $quote->getPayment();
            $additionalData = $quotePayment->getAdditionalData();
            //avoid overwriting quotePayment additional data
            if (!is_array($additionalData)) {
                $additionalData = [];
            }
            $additionalData['3d_secure'] = $paymentDetails;
            $additionalData = json_encode($additionalData);
            try{
                $quotePayment->setAdditionalData($additionalData);
                $quotePayment->save();
            }catch(RuntimeException $e){
                $this->logger->log(sprintf(__('advise_log_cannot_save'), $e->getMessage()));
            }
        }else{
            $this->logger->log(sprintf(__('advise_log_no_quote_found'), $quote->getEntityId()));
        }
    }
}