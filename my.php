<?php
require __DIR__.'/vendor/autoload.php';

use Amp\Loop;
use Fiber\Helper as f;

Loop::run(function () {
    $f = new Fiber(function () { mysql(); });

    f\run($f);
});

function mysql()
{
    $config = new \Fiber\Mysql\Config();
    $config->user = 'root';
    $config->pass = 'hjkl';
    $config->db   = 'test';
    $config->host = '127.0.0.1';
    $config->port = 3306;

    $db = new \Fiber\Mysql\Connection($config);

    echo $db->query("select * from books order by id desc limit 3");
}
