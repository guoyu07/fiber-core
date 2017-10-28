<?php

namespace Fiber\Mysql;

class Config
{
    /* <IP-string>(:<port>) */
    public $host;
    public $port;
    public $user;
    public $pass;
    public $db;

    /* charset id @see 14.1.4 in mysql manual */
    public $binCharset = 45; // utf8mb4_general_ci
    public $charset = "utf8mb4";
    public $collate = "utf8mb4_general_ci";
}
