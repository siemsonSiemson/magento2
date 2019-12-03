<?php
namespace Riskified\Decider\Controller;

use Riskified\Decider\Model\Api\Request\Advice as AdviceRequest;
use Riskified\Decider\Model\Api\Builder\Advice as AdviceBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Riskified\Decider\Model\Api\Order as OrderApi;
use Riskified\Decider\Model\Api\Log as Logger;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Model\OrderFactory;
use http\Exception\RuntimeException;
use Riskified\Decider\Model\Api\Api;
use Magento\Framework\Registry;

abstract class AdviceAbstract extends \Magento\Framework\App\Action\Action
{
    const XML_ADVISE_ENABLED = 'riskified/riskified_advise_process/enabled';
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;
    /**
     * @var Registry
     */
    protected $registry;
    /**
     * @var AdviceBuilder
     */
    protected $adviceBuilder;
    /**
     * @var AdviceRequest
     */
    protected $adviceRequest;
    /**
     * @var Api
     */
    protected $api;
    /**
     * @var Logger
     */
    protected $logger;
    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $session;
    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    protected $request;
    /**
     * @var OrderApi
     */
    protected $apiOrderLayer;
    /**
     * @var QuoteFactory
     */
    protected $quoteFactory;
    /**
     * @var OrderFactory
     */
    protected $orderFactory;
    /**
     * @var QuoteIdMaskFactory
     */
    protected $quoteIdMaskFactory;
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
     * @param OrderFactory $orderFactory
     * @param AdviceBuilder $adviceBuilder
     * @param AdviceRequest $adviceRequest
     * @param OrderApi $orderApi
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
        OrderFactory $orderFactory,
        AdviceBuilder $adviceBuilder,
        AdviceRequest $adviceRequest,
        OrderApi $orderApi,
        Registry $registry,
        Logger $logger,
        Api $api
    ){
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->adviceBuilder = $adviceBuilder;
        $this->adviceRequest = $adviceRequest;
        $this->quoteFactory = $quoteFactory;
        $this->orderFactory = $orderFactory;
        $this->scopeConfig = $scopeConfig;
        $this->apiOrderLayer = $orderApi;
        $this->registry = $registry;
        $this->request = $request;
        $this->session = $session;
        $this->logger = $logger;
        $this->api = $api;
        return parent::__construct($context);
    }

    /**
     * Sends Denied Quote to Riskified Api
     */
    protected function sendDeniedOrderToRiskified($quote)
    {
        $orderFactory = $this->orderFactory->create();
        $order = $orderFactory->loadByAttribute('quote_id', $quote->getEntityId());
        //when order hasn't been already set use quote instead
        if(is_numeric($order->getEntityId()) != 1){
            $order = $quote;
        }
        $this->apiOrderLayer->post(
            $order,
            Api::ACTION_CHECKOUT_DENIED
        );
    }

    /**
     * Returns unmasked quote id.
     * @param $cartId
     * @return int
     */
    protected function getQuoteId($cartId)
    {
        if(is_numeric($cartId)){
            $quoteId = $cartId;
        }else{
            $quoteIdMask = $this->quoteIdMaskFactory->create()->load($cartId, 'masked_id');
            $quoteId = $quoteIdMask->getQuoteId();
        }

        return intval($quoteId);
    }

    /**
     * Checks if Advice Call is enabled in admin panel
     * @return mixed
     */
    protected function isEnabled()
    {
        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        $adviseEnabled = $this->scopeConfig->getValue(self::XML_ADVISE_ENABLED, $storeScope);
        
        return  intval($adviseEnabled);
    }
}