<?php
namespace Riskified\Decider\Api\Builder;

use Riskified\Decider\Api\Request\Advice as AdviceRequest;
use \Magento\Checkout\Model\Session;
use \Magento\Framework\Serialize\Serializer\Json;
use \Magento\Quote\Api\CartRepositoryInterface;

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
    protected $quoteRepository;

    /**
     * Advice constructor.
     * @param AdviceRequest $requestAdvice
     * @param Session $checkoutSession
     * @param CartRepositoryInterface $quoteRepository
     * @param Json $serializer
     */
    public function __construct(
        AdviceRequest $requestAdvice,
        Session $checkoutSession,
        CartRepositoryInterface $quoteRepository,
        Json $serializer
    ){
        $this->adviceRequestModel = $requestAdvice;
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->serializer = $serializer;
    }

    /**Magento\Quote\Model\Quote\Interceptor
     * @param $params
     * @return $this
     */
    public function build($params)
    {
        $quoteId = $params['quote_id'];
        $quoteObject = $this->quoteRepository->get($quoteId);
        $totals = $quoteObject->getTotals();
        $grandTotal = $totals['grand_total'];
        $currencyObject = $quoteObject->getCurrency();
        $customerObject = $quoteObject->getCustomer();
        $paymentObject = $quoteObject->getPayment();

        $this->json = $this->serializer->serialize(
            ["checkout" => [
                "id" => $quoteObject->getId(),
                "currency" => $currencyObject->getQuoteCurrencyCode(),
                "total_price" => $quoteObject->getGrandTotal(),
                "payment_details" => [
                    [
                        "avs_result_code" => "Y",
                        "credit_card_bin" => "123456",
                        "credit_card_company" => "Visa",
                        "credit_card_number" => "4111111111111111",
                        "cvv_result_code" => "M"
                    ]
                ],
                "_type" => 'credit_card',
                "gateway" => $paymentObject->getMethod()
            ]
            ]
        );

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