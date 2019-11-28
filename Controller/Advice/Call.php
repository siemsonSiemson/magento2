<?php

namespace Riskified\Decider\Controller\Advice;

class Call extends \Riskified\Decider\Controller\AdviceHelper
{

    /**
     * Function fetches post data from order checkout payment step.
     * When 'mode' parameter is present data comes from 3D Secure Payment Authorisation Refuse and refusal details are saved in quotePayment table (additional_data). Order state is set as 'ACTION_CHECKOUT_DENIED'.
     * In other cases collected payment data are send for validation to Riskified Advise Api and validation status is returned to frontend. Additionally when validation status is not 'captured' order state is set as 'ACTION_CHECKOUT_DENIED'.
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

        $params = $this->request->getParams();
        $quoteId = $this->getQuoteId($params['quote_id']);
        $quoteFactory = $this->quoteFactory;
        $quote = $quoteFactory->create()->load($quoteId);
        //save quote object into registry
        $this->registry->register($quoteId, $quote);

        $this->api->initSdk();
        $this->logger->log(sprintf(__('advise_log_json_build'), $quoteId));
        $this->adviceBuilder->build($params);
        $callResponse = $this->adviceBuilder->request();
        $status = $callResponse->checkout->status;
        $authType = $callResponse->checkout->authentication_type->auth_type;
        $this->logger->log(sprintf(__('advise_log_status'), $status));
        //use this status while backend order validation
        $this->session->setAdviceCallStatus($status);
        if($status != "captured"){
            $adviceCallStatus = 3;
            $paymentDetails = array(
                'date' => $currentDate = date('Y-m-d H:i:s', time()),
                'auth_type' => 'fraud',
                'status' => $status
            );
            $logMessage = sprintf(__('advise_log_quote_denied'), $quoteId);
            //saves advise call returned data in quote Payment (additional data)
            $this->updateQuotePaymentDetailsInDb($quoteId, $paymentDetails);
            //Riskified defined order as fraud - order data is send to Riskified
            $this->sendDeniedOrderToRiskified($quote);
            $this->logger->log($logMessage);
            $message = __('checkout_declined');
            $this->messageManager->addError(__('checkout_declined'));
        }else {
            $paymentDetails = array(
                'date' => $currentDate = date('Y-m-d H:i:s', time()),
                'auth_type' => $authType,
                'status' => $status
            );
            if($authType == "sca"){
                $adviceCallStatus = false;
                $message = __('advice_sca');
            }else{
                $adviceCallStatus = true;
                $message = __('advice_tra');
            }
            //saves advise call returned data in quote Payment (additional data)
            $this->updateQuotePaymentDetailsInDb($quoteId, $paymentDetails);
        }

        return  $this->resultJsonFactory->create()->setData(['advice_status' => $adviceCallStatus, 'message' => $message]);
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
        if (isset($quote)) {
            $this->logger->log(sprintf(__('advise_log_quote_save'), $quoteId));
            $quotePayment = $quote->getPayment();
            $additionalData = $quotePayment->getAdditionalData();

            //avoid overwriting quotePayment additional data
            if (!is_array($additionalData)) {
                $additionalData = [];
            }
            $additionalData[$paymentDetails['auth_type']] = $paymentDetails;
            $additionalData = json_encode($additionalData);
            try {
                $quotePayment->setAdditionalData($additionalData);
                $quotePayment->save();
            } catch (RuntimeException $e) {
                $this->logger->log(sprintf(__('advise_log_no_quote_found'), $e->getMessage()));
            }
        } else {
            $this->logger->log(sprintf(__('advise_log_no_quote_found'), $quoteId));
        }
    }
}