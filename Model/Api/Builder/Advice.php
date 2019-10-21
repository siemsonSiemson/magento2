<?php
namespace Riskified\Decider\Model\Api\Builder;

use Riskified\Decider\Model\Api\Request\Advice as AdviceRequest;
use \Magento\Checkout\Model\Session;
use \Magento\Framework\Serialize\Serializer\Json;
use \Magento\Quote\Api\CartRepositoryInterface;
use \Magento\Quote\Model\QuoteIdMaskFactory;

class Advice {
    /**
     * @var AdviceRequest
     */
    private $adviceRequestModel;
    /**
     * @var Session
     */
    private $checkoutSession;
    /**
     * @var
     */
    private $json;
    /**
     * @var Json
     */
    private $serializer;

    /**
     * @var CartRepositoryInterface
     */
    protected $cartRepository;

    /**
     * Advice constructor.
     * @param QuoteIdMaskFactory $quoteIdMaskFactory
     * @param CartRepositoryInterface $cartRepository
     * @param AdviceRequest $requestAdvice
     * @param Session $checkoutSession
     * @param Json $serializer
     */
    public function __construct(
        QuoteIdMaskFactory $quoteIdMaskFactory,
        CartRepositoryInterface $cartRepository,
        AdviceRequest $requestAdvice,
        Session $checkoutSession,
        Json $serializer
    ){
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->adviceRequestModel = $requestAdvice;
        $this->checkoutSession = $checkoutSession;
        $this->cartRepository = $cartRepository;
        $this->serializer = $serializer;
    }

    /**Magento\Quote\Model\Quote\Interceptor
     * @param $params
     * @return $this
     */
    public function build($params)
    {
        $quoteId = $params['quote_id'];

        if(isset($params['gateway'])){
            $gateway = $params['gateway'];
        }else{
            $gateway = '';
        }

        if (!is_numeric($quoteId)) {
            $quoteIdMask = $this->quoteIdMaskFactory->create()->load($quoteId, 'masked_id');
            $cart = $this->cartRepository->getActive($quoteIdMask->getQuoteId());
        } else {
            $cart = $this->cartRepository->getActive($quoteId);
        }

        $currencyObject = $cart->getCurrency();
        $customerObject = $cart->getCustomer();
        $paymentObject = $cart->getPayment();

        if($gateway == "braintree_paypal"){
            $this->json = $this->serializer->serialize(
                [
                    "checkout" => [
                    "id" => $cart->getId(),
                    "email" => $customerObject->getEmail(),
                    "currency" => $currencyObject->getQuoteCurrencyCode(),
                    "total_price" => $cart->getGrandTotal(),
                    "payment_details" => [
                        [
                            "payer_email" => $params['email'],
                            'payer_status' => 'verified',
                            'payer_address_status' => 'unconfirmed',
                            'protection_eligibility' => 'Eligible',
                        ]
                    ],
                    "_type" => 'paypal',
                    "gateway" => $paymentObject->getMethod(),
                    ]
                ]
            );
        }else{
            $this->json = $this->serializer->serialize(
                [
                    "checkout" => [
                    "id" => $cart->getId(),
                    "email" => $customerObject->getEmail(),
                    "currency" => $currencyObject->getQuoteCurrencyCode(),
                    "total_price" => $cart->getGrandTotal(),
                    "payment_details" => [
                        [
                            "avs_result_code" => "Y",
                            "credit_card_bin" => "492044",
                            "credit_card_company" => "Visa",
                            "credit_card_number" => "4111111111111111",
                            "cvv_result_code" => "M"
                        ]
                    ],
                    "_type" => 'credit_card',
                    "gateway" => $paymentObject->getMethod(),
                    ]
                ]
            );
        }

        return $this;
    }
    /**
     * @return mixed
     * @throws \Riskified\OrderWebhook\Exception\CurlException
     * @throws \Riskified\OrderWebhook\Exception\UnsuccessfulActionException
     */
    public function request()
    {
        
        return $this->adviceRequestModel->call($this->json);
    }
}