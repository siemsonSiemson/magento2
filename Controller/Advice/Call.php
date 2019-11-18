<?php
namespace Riskified\Decider\Controller\Advice;
use http\Exception\RuntimeException;
use Riskified\Decider\Model\Api\Builder\Advice as AdviceBuilder;
use Riskified\Decider\Model\Api\Request\Advice as AdviceRequest;
use \Magento\Quote\Model\QuoteFactory;
use Riskified\Decider\Api\Log as Logger;
use Riskified\Decider\Api\Api;
class Call extends \Magento\Framework\App\Action\Action
{
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
     * @var \Magento\Framework\Controller\Result\JsonFactory
     */
    protected $resultJsonFactory;
    /**
     * Call constructor.
     * @param \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Framework\App\Request\Http $request
     * @param \Magento\Checkout\Model\Session $session
     * @param QuoteFactory $quoteFactory
     * @param AdviceBuilder $adviceBuilder
     * @param AdviceRequest $adviceRequest
     * @param Logger $logger
     * @param Api $api
     */
    public function __construct(
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Checkout\Model\Session $session,
        QuoteFactory $quoteFactory,
        AdviceBuilder $adviceBuilder,
        AdviceRequest $adviceRequest,
        Logger $logger,
        Api $api
    ){
        $this->quoteFactory = $quoteFactory;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->adviceBuilder = $adviceBuilder;
        $this->adviceRequest = $adviceRequest;
        $this->request = $request;
        $this->session = $session;
        $this->logger = $logger;
        $this->api = $api;
        return parent::__construct($context);
    }
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
        $params = $this->request->getParams();
        //When 3D Secure response is denied
        if(isset($params['mode'])){
            $quoteId = $params['quote_id'];
            //saves 3D Secure Response data in quotePayment table (additional data)
            $this->updateQuotePaymentDetailsInDb($quoteId, $params);
        }else{
            $this->api->initSdk();
            $this->logger->log('Riskified Advise Call building json data from quote id: ' . $params['quote_id']);
            $this->adviceBuilder->build($params);
            $callResponse = $this->adviceBuilder->request();
            $status = $callResponse->checkout->status;
            $authType = $callResponse->checkout->authentication_type->auth_type;
            $this->logger->log('Riskified Advise Call Response status: ' . $status);
            $paymentDetails = array('auth_type' => $authType, 'status' => $status);
            //saves advise call returned data in quote Payment (additional data)
            $this->updateQuotePaymentDetailsInDb($params['quote_id'], $paymentDetails);
            //use this status while backend order validation
            $this->session->setAdviceCallStatus($status);
            if($status != "captured"){
                //checkout API denied
                $adviceCallStatus = 3;
                $message = 'Checkout Declined.';
                $this->messageManager->addError(__("Checkout Declined"));
            }else {
                if($authType == "sca"){
                    $adviceCallStatus = false;
                    $message = 'Transaction type: sca.';
                }else{
                    $adviceCallStatus = true;
                    $message = 'Transaction type: tra.';
                }
            }
            return  $this->resultJsonFactory->create()->setData(['advice_status' => false, 'message' => $message]);
        }
    }
    /**
     * Saves quote payment details (additional data).
     * @param $quoteId
     * @param $paymentDetails
     * @throws \Exception
     */
    public function updateQuotePaymentDetailsInDb($quoteId, $paymentDetails)
    {
        $quoteFactory = $this->quoteFactory;
        $quote = $quoteFactory->create()->load($quoteId);
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
}