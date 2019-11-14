<?php
namespace Riskified\Decider\Observer;

use Magento\Framework\Event\ObserverInterface;
use Riskified\Decider\Api\Api;

/**
 * Class OrderPaymentFailed
 * @package Riskified\Decider\Observer
 */
class OrderPaymentFailed implements ObserverInterface
{
    /**
     * @var \Riskified\Decider\Api\Log
     */
    private $logger;

    /**
     * @var \Riskified\Decider\Api\Order
     */
    private $apiOrderLayer;

    /**
     * @var \Magento\Quote\Model\QuoteFactory
     */
    private $quoteFactory;

    public function __construct(
        \Riskified\Decider\Api\Log $logger,
        \Riskified\Decider\Api\Order $orderApi,
        \Magento\Quote\Model\QuoteFactory $quoteFactory
    ) {
        $this->logger = $logger;
        $this->apiOrderLayer = $orderApi;
        $this->quoteFactory = $quoteFactory;
    }

    /**
     * Function set quote status as denied and send it to Riskified.
     * 
     * @param \Magento\Framework\Event\Observer $observer
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $quoteData = $observer->getData();
        $quoteId = $quoteData[0]['quote_id'];
        $quote = $this->quoteFactory->create()->load($quoteId);
        //need to add same logic for quote as order has
//        if(!is_null($quote)){
//            $this->apiOrderLayer->post(
//                $quote,
//                Api::ACTION_CHECKOUT_DENIED
//            );
//        }

        return $this;
    }
}
