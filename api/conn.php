<?php
    require_once __DIR__."/env.php";
    date_default_timezone_set('Africa/Lagos');
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    $dsn = "mysql:host=localhost; dbname=$db_name";
    $conn = new PDO($dsn, $db_user, $db_pass, []);
    if (!$conn) die('DB Connection Failed');
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        header("HTTP/1.1 200 OK");
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
        header("Access-Control-Max-Age: 86400");
        header("Content-Length: 0");
        header("Content-Type: text/plain");
        exit(0);
    }

    header("Content-Type: application/json");
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

    if (class_exists('\Redis')) {
        $redis = new \Redis();
        $redis->connect('127.0.0.1', 6379);
    } else {
        require __DIR__.'/vendor/autoload.php';
        $redis = new Predis\Client(['host'=>'127.0.0.1','port'=>6379]);
    }

    // Rate limiting
    function enforceRateLimit($redis, $ip, $max = 10, $window = 60) {
        $key = "rate:$ip";
        $count = $redis->get($key);
        if ($count !== false && intval($count) >= $max) {
            http_response_code(429);
            echo json_encode(["error" => "rate limit exceeded"]);
            exit;
        }
        $redis->multi()->incr($key)->expire($key, $window)->exec();
    }

    // Logging
    function logEvent($type, $details = []) {
        global $conn;
        
        $ts_raw = date('c');
        $ts = date('Y-m-d H:i:s', strtotime($ts_raw));
        $ip = $_SERVER['REMOTE_ADDR'];
        $details_json = json_encode($details);

        $stmt = $conn->prepare("INSERT INTO events_logs (ts, type, ip, details) VALUES (:ts, :type, :ip, :details)");
        $stmt->bindParam(':ts', $ts);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':ip', $ip);
        $stmt->bindParam(':details', $details_json);
        $stmt->execute();
    }

    // Card validation
    function isValidCard($card) {
        return isset($card['name'], $card['pan'], $card['exp'], $card['cvv']) &&
            preg_match('/^\d{16}$/', $card['pan']) &&
            preg_match('/^\d{2}\/\d{2}$/', $card['exp']) &&
            preg_match('/^\d{3,4}$/', $card['cvv']);
    }
