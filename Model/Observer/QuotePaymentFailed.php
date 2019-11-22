<?php

namespace Riskified\Decider\Model\Observer;

use Magento\Checkout\Model\Session;
use Riskified\Decider\Model\Api\Api;
use Magento\Sales\Model\OrderFactory;
use Riskified\Decider\Model\Api\Log as LogApi;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Riskified\Decider\Model\Api\Order as OrderApi;

class QuotePaymentFailed implements ObserverInterface
{
    /**
     * @var LogApi
     */
    private $logger;

    /**
     * @var OrderApi
     */
    private $apiOrderLayer;

    /**
     * @var ManagerInterface
     */
    private $messageManager;

    /**
     * @var OrderFactory
     */
    private $orderFactory;

    /**
     * QuotePaymentFailed constructor.
     * @param LogApi $logger
     * @param OrderApi $orderApi
     * @param OrderFactory $orderFactory
     * @param ManagerInterface $messageManager
     */
    public function __construct(
        LogApi $logger,
        OrderApi $orderApi,
        OrderFactory $orderFactory,
        ManagerInterface $messageManager
    ) {
        $this->logger = $logger;
        $this->apiOrderLayer = $orderApi;
        $this->orderFactory = $orderFactory;
        $this->messageManager = $messageManager;
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     * @return $this|void
     * @throws \Riskified\OrderWebhook\Exception\CurlException
     * @throws \Riskified\OrderWebhook\Exception\MalformedJsonException
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $quote = $observer->getData('quote');
        $quoteFactory = $this->orderFactory->create();
        $order = $quoteFactory->loadByAttribute('quote_id', $quote->getEntityId());
        $this->apiOrderLayer->post(
            $order,
            Api::ACTION_CHECKOUT_DENIED
        );

        return $this;
    }
}