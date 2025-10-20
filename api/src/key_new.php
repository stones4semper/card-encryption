<?php
    require_once __DIR__."/conn.php";

    $ip = $_SERVER["REMOTE_ADDR"];
    enforce_rate_limit($redis, $ip);

    $ttl = 180;
    $kp = sodium_crypto_box_keypair();
    $pub = sodium_crypto_box_publickey($kp);
    $sec = sodium_crypto_box_secretkey($kp);
    $key_id = bin2hex(random_bytes(16));
    $exp = time() + $ttl;

    $redisKey = "key:$key_id";
    $value = base64_encode($sec) . ":" . base64_encode($pub) . ":" . $exp;
    $redis->setex($redisKey, $ttl, $value);

    log_event("key_created", ["key_id" => $key_id]);

    echo json_encode([
        "key_id" => $key_id,
        "public_key_b64" => sodium_bin2base64($pub, SODIUM_BASE64_VARIANT_ORIGINAL),
        "expires_at" => $exp
    ]);
