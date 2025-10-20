<?php
    require_once __DIR__."/conn.php";

    $ip = $_SERVER["REMOTE_ADDR"];
    enforceRateLimit($redis, $ip);

    $in = json_decode(file_get_contents("php://input"), true);
    $key_id = $in["key_id"] ?? "";
    $payload = $in["payload"] ?? "";

    if (empty($key_id) || empty($payload)) {
        http_response_code(400);
        echo json_encode(["error" => "missing parameters"]);
        exit;
    }

    $redisKey = "key:$key_id";
    $val = false;

    if (method_exists($redis, "getDel")) {
        $val = $redis->getDel($redisKey);
    } else {
        try {
            $script = "
                local v = redis.call('GET', KEYS[1])
                if v then redis.call('DEL', KEYS[1]) end
                return v
            ";
            $val = $redis->eval($script, 1, $redisKey);
        } catch (Exception $e) {
            $val = $redis->get($redisKey);
            if ($val) $redis->del($redisKey);
        }
    }

    if (!$val) {
        http_response_code(400);
        echo json_encode(["error" => "expired or invalid key"]);
        exit;
    }

    list($sec_b64, $pub_b64, $exp) = explode(":", $val);
    if (intval($exp) < time()) {
        http_response_code(400);
        echo json_encode(["error" => "expired"]);
        exit;
    }

    $sec = sodium_base642bin($sec_b64, SODIUM_BASE64_VARIANT_ORIGINAL);
    $pub = sodium_base642bin($pub_b64, SODIUM_BASE64_VARIANT_ORIGINAL);
    $kp = sodium_crypto_box_keypair_from_secretkey_and_publickey($sec, $pub);

    $cipher = sodium_base642bin($payload, SODIUM_BASE64_VARIANT_ORIGINAL);
    $plain = sodium_crypto_box_seal_open($cipher, $kp);

    if ($plain === false) {
        http_response_code(400);
        echo json_encode(["error" => "decrypt"]);
        exit;
    }

    $req = json_decode($plain, true);
    if (!isValidCard($req["card"] ?? [])) {
        http_response_code(400);
        echo json_encode(["error" => "invalid card data"]);
        exit;
    }

    logEvent("decryption_success", ["key_id" => $key_id]);

    echo json_encode(["ok" => true]);
