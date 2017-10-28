<?php
require __DIR__.'/vendor/autoload.php';

use Amp\Loop;
use Fiber\Helper as f;

gc_disable();

$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);
socket_set_nonblock($server);
socket_bind($server, '0.0.0.0', 8080);
socket_listen($server, 256);

function biz($client)
{
    $headers = f\find($client, "\r\n\r\n");
    if (!$headers) {
        return socket_close($client);
    }

    $headers = explode("\r\n", $headers);
    $method = array_shift($headers);
    $headers = array_map(function ($header) {
        return preg_split('/:\s+/', $header);
    }, $headers);

    $length = 0;
    foreach ($headers as list($name, $value)) {
        echo $name, ": ", $value, "\n";
        if ($name === 'Content-Length') {
            $length = (int) $value;
        }
    }

    $body = f\read($client, $length);

    if (substr($method, 0, 10) == 'GET /sleep') {
        f\sleep(9000);
    } elseif (substr($method, 0, 8) == 'GET /dig') {
        $ips = f\dig("www.baidu.com");
        $body = json_encode($ips);
    } elseif (substr($method, 0, 9) == 'GET /head') {
        $http = \Fiber\Http\Client::create(['base_uri' => 'http://myip.ipip.net']);
        $response = $http->request('GET', '/');
        $body = $response->getBody();
    }

    f\write($client, "HTTP/1.0 200 OK\r\nContent-Type:application/json\r\nContent-Length: ");
    f\write($client, strlen($body));
    f\write($client, "\r\n\r\n");
    f\write($client, $body);

    socket_close($client);
}

Loop::onReadable($server, function ($id, $server) {
    $client = socket_accept($server);
    socket_set_nonblock($client);

    $fiber = new Fiber(function ($client) {
        try {
            biz($client);
        } catch (Throwable $t) {
        }
    });

    f\run($fiber, $client);
});

Loop::run();
