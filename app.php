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
        $headers = lv\find($client, "\r\n\r\n");
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

        $body = lv\read($client, $length);

        if (substr($method, 0, 10) == 'GET /sleep') {
            lv\sleep(9000);
        } elseif (substr($method, 0, 8) == 'GET /dig') {
            $ips = lv\dig("www.baidu.com");
            $body = json_encode($ips);
        }

        lv\write($client, "HTTP/1.0 200 OK\r\nContent-Type:application/json\r\nContent-Length: ");
        lv\write($client, strlen($body));
        lv\write($client, "\r\n\r\n");
        lv\write($client, $body);

        socket_close($client);
    });

    $ret = $fiber->resume($client);

    lv\run($fiber, $ret);
});

Loop::run();
