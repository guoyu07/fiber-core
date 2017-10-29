<?php
require __DIR__.'/../vendor/autoload.php';

use Amp\Loop;
use Fiber\Helper as f;

gc_disable();

$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);
socket_set_nonblock($server);
socket_bind($server, '0.0.0.0', 8080);
socket_listen($server, 256);

function mysql()
{
    $config = new \Fiber\Mysql\Config();
    $config->user = 'root';
    $config->pass = 'hjkl';
    $config->db   = 'test';
    $config->host = '127.0.0.1';
    $config->port = 3306;

    $db = new \Fiber\Mysql\Connection($config);
    $rows = $db->query('select * from books limit 2');

    return json_encode($rows, JSON_UNESCAPED_UNICODE);
}

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

    if (stripos($method, 'GET /sleep') === 0) {
        f\sleep(9000);
    } elseif (stripos($method, 'GET /db') === 0) {
        $body = mysql();
    } elseif (stripos($method, 'GET /dig') === 0) {
        $ips = f\dig("www.baidu.com");
        $body = json_encode($ips);
    } elseif (stripos($method, 'GET /head') === 0) {
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
        } catch (\Throwable $t) {
            echo $t->getMessage(),' ',$t->getFile(),' ',$t->getLine(), "\n",
                $t->getTraceAsString(), "\n";
        }
    });

    f\run($fiber, $client);
});

Loop::run();
