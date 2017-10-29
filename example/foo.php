<?php
require __DIR__.'/../vendor/autoload.php';

use Amp\Loop;
use Fiber\Helper as f;

Loop::run(function () {
    $f = new Fiber(function () {
        $http = \Fiber\Http\Client::create(['base_uri' => 'http://myip.ipip.net']);
        $response = $http->request('GET', '/');
        echo $response->getBody();
    });

    f\run($f);
});
