<?php

namespace Riskified\Decider\Model\Observer\Order;

use Magento\Framework\Event\ObserverInterface;
use Riskified\Decider\Model\Api\Api;

class OrderPaymentFailed implements ObserverInterface
{
    private $logger;
    private $apiOrderLayer;
    private $apiConfig;
    private $apiOrderConfig;
    /**
     * UpdateOrderState constructor.
     *
     * @param \Riskified\Decider\Api\Log $logger
     * @param \Riskified\Decider\Api\Config $config
     * @param \Riskified\Decider\Api\Order\Config $apiOrderConfig
     * @param \Riskified\Decider\Api\Order $orderApi
     */
    public function __construct(
        \Riskified\Decider\Model\Api\Log $logger,
        \Riskified\Decider\Model\Api\Config $config,
        \Riskified\Decider\Model\Api\Order\Config $apiOrderConfig,
        \Riskified\Decider\Model\Api\Order $orderApi
    ) {
        $this->logger = $logger;
        $this->apiOrderConfig = $apiOrderConfig;
        $this->apiOrderLayer = $orderApi;
        $this->apiConfig = $config;
    }
    /**
     * Observer handler
     * @param \Magento\Framework\Event\Observer $observer
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        $this->apiOrderLayer->post(
            $order,
            Api::ACTION_CHECKOUT_DENIED
        );
        return $this;
    }
}