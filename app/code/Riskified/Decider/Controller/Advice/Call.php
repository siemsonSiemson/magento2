<?php
namespace Riskified\Decider\Controller\Advice;

use Riskified\Decider\Api\Builder\Advice as AdviceBuilder;
use Riskified\Decider\Api\Request\Advice as AdviceRequest;
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

    protected $resultJsonFactory;


    public function __construct(
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Checkout\Model\Session $session,
        AdviceBuilder $adviceBuilder,
        AdviceRequest $adviceRequest,
        Logger $logger,
        Api $api
    ){
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
     * Function fetches post data from order payment step, and passing it to Riskified Advice Api validation.
     * As a response validation status is returned.
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\Result\Json|\Magento\Framework\Controller\ResultInterface
     * @throws \Riskified\OrderWebhook\Exception\CurlException
     * @throws \Riskified\OrderWebhook\Exception\UnsuccessfulActionException
     */
    public function execute()
    {
        $params = $this->request->getParams();
        $this->api->initSdk();

        $this->logger->log('Riskified Advise Call building json data from quote id: ' . $params['quote_id']);
        $this->adviceBuilder->build($params);
        $callResponse = $this->adviceBuilder->request();
        $status = $callResponse->checkout->status;
        $authType = $callResponse->checkout->authentication_type->auth_type;
        $this->logger->log('Riskified Advise Call Response status: ' . $status);

        if($status != "captured"){
            $adviceCallStatus = false;
        }else {
            if($authType == "sca" || $authType == "tra"){
                $adviceCallStatus = false;
            }else{
                $adviceCallStatus = true;
            }
        }

        //use this status while backend order validation
        $this->session->setAdviceCallStatus($adviceCallStatus);

        return  $this->resultJsonFactory->create()->setData(['advice_status' => $adviceCallStatus]);
    }
}