<?php

namespace Riskified\Decider\Model\Gateway\Request;

use Magento\Braintree\Gateway\Config\Config;
use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Magento\Braintree\Gateway\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Helper\Formatter;
use Riskified\Decider\Model\Api\Log as Logger;

class ThreeDSecureDataBuilder implements BuilderInterface
{
    use Formatter;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $session;

    /**
     * @var \Magento\Braintree\Gateway\Config\Config
     */
    private $config;

    /**
     * @var \Magento\Braintree\Gateway\SubjectReader
     */
    private $subjectReader;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * ThreeDSecureDataBuilder constructor.
     * @param Config $config
     * @param SubjectReader $subjectReader
     * @param \Magento\Checkout\Model\Session $session
     * @param Logger $logger
     */
    public function __construct(
        \Magento\Braintree\Gateway\Config\Config $config,
        \Magento\Braintree\Gateway\SubjectReader $subjectReader,
        \Magento\Checkout\Model\Session $session,
        Logger $logger
    ){
        $this->session = $session;
        $this->config = $config;
        $this->subjectReader = $subjectReader;
        $this->logger = $logger;
    }

    /**
     * Function checks against Riskified-Advise-Api condition. In case of refuse-response 3DSecure safety will be enabled.
     * @param array $buildSubject
     * @return array|null
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function build(array $buildSubject)
    {
        $result = [];
        $paymentDO = $this->subjectReader->readPayment($buildSubject);
        $amount = $this->formatPrice($this->subjectReader->readAmount($buildSubject));
        $adviceCallStatus = $this->session->getAdviceCallStatus();
        $this->logger->log('Riskified Advise Call backend validation starts.');

        if($adviceCallStatus !== true){
            $result['options'][Config::CODE_3DSECURE] = ['required' => true];
            $this->logger->log('Riskified Advise refuse response received - 3D secure is added.');

            return $result;
        }else{
            if ($this->is3DSecureEnabled($paymentDO->getOrder(), $amount)) {
                $result['options'][Config::CODE_3DSECURE] = ['required' => true];
            }

            return $result;
        }
    }

    /**
     * @param OrderAdapterInterface $order
     * @param $amount
     * @return bool
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function is3DSecureEnabled(OrderAdapterInterface $order, $amount)
    {
        $storeId = $order->getStoreId();
        if (!$this->config->isVerify3DSecure($storeId)
            || $amount < $this->config->getThresholdAmount($storeId)
        ) {
            return false;
        }

        $billingAddress = $order->getBillingAddress();
        $specificCounties = $this->config->get3DSecureSpecificCountries($storeId);
        if (!empty($specificCounties) && !in_array($billingAddress->getCountryId(), $specificCounties)) {
            return false;
        }

        return true;
    }
}