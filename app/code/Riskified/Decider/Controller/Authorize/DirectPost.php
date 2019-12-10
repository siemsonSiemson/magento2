<?php
namespace Riskified\Decider\Controller;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Riskified\Decider\Api\Order as OrderApi;
use Riskified\Decider\Api\Log as Logger;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Model\OrderFactory;
use Riskified\Decider\Api\Api;
use Magento\Framework\Registry;

class DirectPost extends \Magento\Framework\App\Action\Action
{
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;
    /**
     * @var Registry
     */
    protected $registry;
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
     * DirectPost constructor.
     * @param \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory
     * @param \Magento\Quote\Model\QuoteIdMaskFactory $quoteIdMaskFactory
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Framework\App\Request\Http $request
     * @param \Magento\Checkout\Model\Session $session
     * @param ScopeConfigInterface $scopeConfig
     * @param QuoteFactory $quoteFactory
     * @param OrderFactory $orderFactory
     * @param OrderApi $orderApi
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
        OrderApi $orderApi,
        Logger $logger,
        Api $api
    ){
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->quoteFactory = $quoteFactory;
        $this->orderFactory = $orderFactory;
        $this->apiOrderLayer = $orderApi;
        $this->logger = $logger;
        $this->api = $api;

        return parent::__construct($context);
    }

    public function execute()
    {
        $params = $this->request->getParams();
        $quoteId = $this->getQuoteId($params['quote_id']);

        print_r($quoteId);
        die;
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
     * Triggers sending quote/order (updated) to Riskified
     * @param $quote
     */
    protected function sendUpdatedOrderToRiskified($quote)
    {
        $orderFactory = $this->orderFactory->create();
        $order = $orderFactory->loadByAttribute('quote_id', $quote->getEntityId());
        //when order hasn't been already set use quote instead
        if(is_numeric($order->getEntityId()) != 1){
            $order = $quote;
        }
        $this->apiOrderLayer->post(
            $order,
            Api::ACTION_UPDATE
        );
    }
}