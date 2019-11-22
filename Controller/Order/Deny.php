<?php
namespace Riskified\Decider\Controller\Order;

use Riskified\Decider\Model\Api\Request\Advice as AdviceRequest;
use Riskified\Decider\Model\Api\Builder\Advice as AdviceBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Riskified\Decider\Model\Api\Log as Logger;
use \Magento\Quote\Model\QuoteFactory;
use http\Exception\RuntimeException;
use Riskified\Decider\Model\Api\Api;
use \Magento\Framework\Registry;


class Deny extends \Magento\Framework\App\Action\Action
{
    const XML_ADVISE_ENABLED = 'riskified/riskified_advise_process/enabled';
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;
    /**
     * @var Registry
     */
    private $registry;
    /**
     * @var AdviceBuilder
     */
    private $adviceBuilder;
    /**
     * @var AdviceRequest
     */
    private $adviceRequest;
    /**
     * @var Api
     */
    private $api;
    /**
     * @var Logger
     */
    private $logger;
    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $session;
    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    protected $request;
    /**
     * @var QuoteFactory
     */
    private $quoteFactory;
    /**
     * @var QuoteIdMaskFactory
     */
    private $quoteIdMaskFactory;
    /**
     * @var \Magento\Framework\Controller\Result\JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * Deny constructor.
     * @param \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory
     * @param \Magento\Quote\Model\QuoteIdMaskFactory $quoteIdMaskFactory
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Framework\App\Request\Http $request
     * @param \Magento\Checkout\Model\Session $session
     * @param ScopeConfigInterface $scopeConfig
     * @param QuoteFactory $quoteFactory
     * @param AdviceBuilder $adviceBuilder
     * @param AdviceRequest $adviceRequest
     * @param Registry $registry
     * @param Logger $logger
     * @param Api $api
     */
    public function __construct(
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
        \Magento\Quote\Model\QuoteIdMaskFactory $quoteIdMaskFactory,
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Checkout\Model\Session $session,
        ScopeConfigInterface $scopeConfig,
        QuoteFactory $quoteFactory,
        AdviceBuilder $adviceBuilder,
        AdviceRequest $adviceRequest,
        Registry $registry,
        Logger $logger,
        Api $api
    ){
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->adviceBuilder = $adviceBuilder;
        $this->adviceRequest = $adviceRequest;
        $this->quoteFactory = $quoteFactory;
        $this->scopeConfig = $scopeConfig;
        $this->registry = $registry;
        $this->request = $request;
        $this->session = $session;
        $this->logger = $logger;
        $this->api = $api;
        return parent::__construct($context);
    }

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
        if($adviseEnabled === 1){
            return  $this->resultJsonFactory->create()->setData(['advice_status' => 'disabled']);
        }
        $params = $this->request->getParams();
        $quoteId = $this->getQuoteId($params['quote_id']);
        $quoteFactory = $this->quoteFactory;
        $quote = $quoteFactory->create()->load($quoteId);
        if(!is_null($quote)){
            $message = 'Quote ' . $quoteId . ' is set as denied and sent to Riskified. Additional data saved in database (paymentQuote table). 3D Secure verification level - failed.';
            //saves 3D Secure Response data in quotePayment table (additional data)
            $this->updateQuotePaymentDetailsInDb($quoteId, $params);
            //Riskified defined order as fraud - order data is send to Riskified
            $this->sendDeniedOrderToRiskified($params, $quote);
            $this->logger->log($message);
        }else{
            $message = 'Quote ' . $quoteId . ' cannot be found - cannot send fraud try to Riskified.';
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
    protected function updateQuotePaymentDetailsInDb($quoteId, $paymentDetails)
    {
        $quote = $this->registry->registry($quoteId);
        if(isset($quote)){
            $this->logger->log('Quote ' . $quoteId . ' found - saving Riskified Advise or 3D Secure Response as additional quotePayment data in db.');
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

    /**
     * Sends Denied Quote to Riskified Api
     */
    protected function sendDeniedOrderToRiskified($params, $quote)
    {
        $this->_eventManager->dispatch(
            'riskified_decider_deny_order_cause_riskified_fraud_or_thredesecure_fail',
            ['postPayload' => $params, 'quote' => $quote]
        );
    }

    /**
     * Returns unmasked quote id.
     * @param $cartId
     * @return int|string
     */
    protected function getQuoteId($cartId)
    {
        if(is_numeric($cartId)){
            $quoteId = $cartId;
        }else{
            $quoteIdMask = $this->quoteIdMaskFactory->create()->load($cartId, 'masked_id');
            $quoteId = $quoteIdMask->getQuoteId();
        }

        return $quoteId;
    }
}