<?php

namespace Riskified\Decider\Gateway\Request;

use Magento\Braintree\Gateway\Config\Config;
use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Magento\Braintree\Gateway\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Helper\Formatter;

class ThreeDSecureDataBuilder implements BuilderInterface
{
    use Formatter;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $_session;

    /**
     * @var \Magento\Braintree\Gateway\Config\Config
     */
    private $_config;

    /**
     * @var \Magento\Braintree\Gateway\SubjectReader
     */
    private $_subjectReader;

    public function __construct(
        \Magento\Braintree\Gateway\Config\Config $_config,
        \Magento\Braintree\Gateway\SubjectReader $_subjectReader,
        \Magento\Checkout\Model\Session $_session
    ){
        $this->_session = $_session;
        $this->_config = $_config;
        $this->_subjectReader = $_subjectReader;
    }

    /**
     * @inheritdoc
     */
    public function build(array $buildSubject)
    {
        $result = [];

        $paymentDO = $this->_subjectReader->readPayment($buildSubject);
        $amount = $this->formatPrice($this->_subjectReader->readAmount($buildSubject));
        $adviceCallStatus = $this->_session->getAdviceCallStatus();

        if($adviceCallStatus != "true"){
            $result['options'][Config::CODE_3DSECURE] = ['required' => true];

            return $result;
        }

        if ($this->is3DSecureEnabled($paymentDO->getOrder(), $amount)) {
            $result['options'][Config::CODE_3DSECURE] = ['required' => true];
        }

        return $result;
    }

    /**
     * Check if 3d secure is enabled
     * @param OrderAdapterInterface $order
     * @param float $amount
     * @return bool
     */
    private function is3DSecureEnabled(OrderAdapterInterface $order, $amount)
    {
        $storeId = $order->getStoreId();
        if (!$this->_config->isVerify3DSecure($storeId)
            || $amount < $this->_config->getThresholdAmount($storeId)
        ) {
            return false;
        }

        $billingAddress = $order->getBillingAddress();
        $specificCounties = $this->_config->get3DSecureSpecificCountries($storeId);
        if (!empty($specificCounties) && !in_array($billingAddress->getCountryId(), $specificCounties)) {
            return false;
        }

        return true;
    }
}