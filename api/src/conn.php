<?php
    require_once dirname(__DIR__, 2)."/env.php";

    date_default_timezone_set('Africa/Lagos');
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    $dsn = "mysql:host=localhost; dbname=$db_name";
    $conn = new PDO($dsn, $db_user, $db_pass, []);
    if (!$conn) die('DB Connection Failed');

    require dirname(__DIR__, 1).'/vendor/autoload.php';

    require __DIR__.'/set-header.php';

    if (class_exists('\Redis')) {
        $redis = new \Redis();
        $redis->connect('127.0.0.1', 6379);
    } else $redis = new Predis\Client(['host'=>'127.0.0.1','port'=>6379]);
    
    require __DIR__.'/functions.php';
