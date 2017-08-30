<?php
require __DIR__.'/vendor/autoload.php';

use Amp\Loop;

gc_disable();

$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);
socket_set_nonblock($server);
socket_bind($server, '0.0.0.0', 8080);
socket_listen($server, 256);

Loop::onReadable($server, function ($id, $server) {
    $client = socket_accept($server);
    socket_set_nonblock($client);
    socket_setopt($client, SOL_SOCKET, SO_RCVBUF, 1);

    $fiber = new Fiber(function ($client) {
        echo microtime(true), "\n";
        $headers = f\find($client, "\r\n\r\n", 3000);
        echo microtime(true), "\n";
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
        }

        f\write($client, "HTTP/1.0 200 OK\r\nContent-Type:application/json\r\nContent-Length: ");
        f\write($client, strlen($body));
        f\write($client, "\r\n\r\n");
        f\write($client, $body);

        socket_close($client);
    });

    $ret = $fiber->resume($client);

    f\run($fiber, $ret);
});

Loop::run();
