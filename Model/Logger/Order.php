<?php

namespace Riskified\Decider\Model\Logger;

class Order extends \Monolog\Logger
{
    /**
     * @inheritdoc
     */
    public function __construct($name, array $handlers = [], array $processors = [])
    {
        parent::__construct($name, $handlers, $processors);
    }
}
