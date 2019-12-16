<?php

namespace Riskified\Decider\Api\Request;

use Riskified\OrderWebhook\Transport\CurlTransport;
use Riskified\Common\Signature\HttpDataSignature;

/**
 * Class Advice
 * @package Riskified\Decider\Api\Request
 */
class Advice extends CurlTransport {

    /**
     * Advice constructor.
     */
    public function __construct()
    {
        parent::__construct(new HttpDataSignature(), null);
    }

    /**
     * @param $order object Order to send
     * @return mixed
     * @throws \Riskified\OrderWebhook\Exception\UnsuccessfulActionException
     * @throws \Riskified\OrderWebhook\Exception\CurlException
     */
    public function call($json)
    {
        return $this->send_json_request($json, 'advise');
    }
}