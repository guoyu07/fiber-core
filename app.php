<?php
require __DIR__.'/vendor/autoload.php';

use Amp\Loop;

gc_disable();

$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);
socket_set_nonblock($server);
socket_bind($server, '0.0.0.0', 8080);
socket_listen($server, 256);

function request($client)
{
    return lv\read($client, 1024);
}

function response($client, $data)
{
    $data = trim($data);

    lv\write($client, "HTTP/1.0 200 OK\r\nContent-Length: 5\r\n\r\nhello");

    socket_close($client);
}

Loop::onReadable($server, function ($id, $server) {
    $client = socket_accept($server);
    socket_set_nonblock($client);

    $fiber = new Fiber(function ($client) {
        $data = request($client);

        if (substr($data, 0, 10) == 'GET /sleep') {
            lv\sleep(9000);
        } elseif (substr($data, 0, 8) == 'GET /dig') {
            $ips = lv\dig("www.baidu.com");
            var_dump($ips);
        }

        response($client, $data);
    });

    $ret = $fiber->resume($client);

    lv\run($fiber, $ret);
});

Loop::run();
