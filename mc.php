<?php
require __DIR__.'/vendor/autoload.php';

use Amp\Loop;
use Fiber\Helper as f;

Loop::run(function () {
    $f = new Fiber(function () {mc(); });

    f\run($f);
});

function mc()
{
    $db = new \Fiber\Memcache\Connection('127.0.0.1');
    var_dump($db->add('bar', 123));
}
