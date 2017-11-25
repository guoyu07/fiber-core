<?php
require __DIR__.'/../vendor/autoload.php';

use Fiber\Helper as f;

f\once(function () {
    $ips = f\dig('baidu.com');
    var_dump($ips);
});
