<?php
namespace Fiber\Http;

class Client
{
    public static function create(array $options = []): \GuzzleHttp\ClientInterface
    {
        $handler = new GuzzleHandler;
        $options['handler'] = \GuzzleHttp\HandlerStack::create($handler);

        return new \GuzzleHttp\Client($options);
    }
}
