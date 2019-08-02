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
    private $_adviceBuilder;

    /**
     * @var AdviceRequest
     */
    private $_adviceRequest;

    /**
     * @var Api
     */
    private $_api;

    /**
     * @var Logger
     */
    private $_logger;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $_session;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    protected $_request;

    /**
     * AdviceCall constructor.
     * @param \Magento\Framework\App\Action\Context $context
     * @param Logger $_logger
     * @param AdviceBuilder $_adviceBuilder
     * @param AdviceRequest $_adviceRequest
     * @param Api $_api
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Checkout\Model\Session $_session,
        \Magento\Framework\App\Request\Http $_request,
        Logger $_logger,
        AdviceBuilder $_adviceBuilder,
        AdviceRequest $_adviceRequest,
        Api $_api
    ){
        $this->_adviceBuilder = $_adviceBuilder;
        $this->_adviceRequest = $_adviceRequest;
        $this->_request = $_request;
        $this->_session = $_session;
        $this->_api = $_api;
        $this->_logger = $_logger;

        return parent::__construct($context);
    }

    public function execute()
    {
        $params = $this->_request->getParams();
        $this->_api->initSdk();
        $this->_adviceBuilder->build($params);
        $callResponse = $this->_adviceBuilder->request();
        $this->_logger->log('Riskified Advise Call Response status: ' . $callResponse->checkout->status);

        $adviceCallStatus = ($callResponse->checkout->status == "captured" ? 'true' : 'false');

        //use this status while backend order validation
        $this->_session->setAdviceCallStatus($adviceCallStatus);

        echo $adviceCallStatus;
    }
}