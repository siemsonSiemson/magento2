<?php
namespace Riskified\Decider\Api\Builder;

use Riskified\Decider\Api\Request\Advice as AdviceRequest;
use \Magento\Checkout\Model\Session;
use \Magento\Framework\Serialize\Serializer\Json;

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
     * Advice constructor.
     * @param AdviceRequest $requestAdvice
     * @param Session $checkoutSession
     * @param Json $serializer
     */
    public function __construct(
        AdviceRequest $requestAdvice,
        Session $checkoutSession,
        Json $serializer
    ){
        $this->adviceRequestModel = $requestAdvice;
        $this->checkoutSession = $checkoutSession;
        $this->serializer = $serializer;
    }
    /**
     * @return $this
     */
    public function build($params)
    {
        if(empty($params)){
            $this->json = $this->serializer->serialize(
                ["checkout" => [
                    "id" => '2234',
                    "currency" => "USD",
                    "total_price" => 319.00,
                    "payment_details" => [
                        [
                            "authorization_id"=> "d3j555kdjgnngkkf3_1",
                            "payer_email"=> "customer1@service-mail.com",
                            "payer_status"=> "verified",
                            "payer_address_status"=> "unconfirmed",
                            "protection_eligibility"=> "Eligible",
                            "payment_status"=> "completed",
                            "pending_reason"=> "None",
                        ]
                    ],
                    "_type" => "paypal",
                    "gateway" => "paypal"
                ]
                ]
            );
        }else{

            $this->json = $this->serializer->serialize(
                ["checkout" => [
                    "id" => $params['id'],
                    "currency" => "USD",
                    "total_price" => 319.00,
                    "payment_details" => [
                        [
                            "authorization_id"=> "d3j555kdjgnngkkf3_1",
                            "payer_email"=> "customer1@service-mail.com",
                            "payer_status"=> "verified",
                            "payer_address_status"=> "unconfirmed",
                            "protection_eligibility"=> "Eligible",
                            "payment_status"=> "completed",
                            "pending_reason"=> "None",
                        ]
                    ],
                    "_type" => "paypal",
                    "gateway" => "paypal"
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