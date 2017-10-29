<?php

namespace Fiber\Mysql;

class Exception extends \Exception
{
    private $state;

    public function getState() : string
    {
        return $state;
    }

    public function __construct(string $msg, int $code = 0, string $state = '')
    {
        parent::__construct($msg, $code);
        $this->state = $state;
    }
}
