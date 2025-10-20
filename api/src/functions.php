<?php
    function enforce_rate_limit($redis, $ip, $max = 10, $window = 60) {
        $key = "rate:$ip";
        $count = $redis->get($key);
        if ($count !== false && intval($count) >= $max) {
            http_response_code(429);
            echo json_encode(["error" => "rate limit exceeded"]);
            exit;
        }
        $redis->multi()->incr($key)->expire($key, $window)->exec();
    }

    function log_event($type, $details = []) {
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

    function is_valid_card($card) {
        return isset($card['name'], $card['pan'], $card['exp'], $card['cvv']) &&
            preg_match('/^\d{16}$/', $card['pan']) &&
            preg_match('/^\d{2}\/\d{2}$/', $card['exp']) &&
            preg_match('/^\d{3,4}$/', $card['cvv']);
    }

        function json_input() {
        $raw = file_get_contents('php://input');
        if (!$raw) return [];
        $j = json_decode($raw, true);
        return is_array($j) ? $j : [];
    }

    function pick($src, $key, $default=null) {
        return isset($src[$key]) && $src[$key] !== '' ? $src[$key] : $default;
    }

    function encrypt_client(array $payload) {
        global $flw_encryption_key;
        $enc = openssl_encrypt(json_encode($payload), 'DES-EDE3', $flw_encryption_key, OPENSSL_RAW_DATA);
        if ($enc === false) return [null, 'openssl_encrypt failed'];
        return [base64_encode($enc), null];
    }

    function http_post_json($url, $body) {
        global $flw_secret;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$flw_secret}",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $r = curl_exec($ch);
        if ($r === false) return ["status"=>"error","message"=>curl_error($ch)];
        curl_close($ch);
        $dec = json_decode($r, true);
        return is_array($dec) ? $dec : ["status"=>"error","message"=>"invalid json","raw"=>$r];
    }

    function http_get($url) {
        global $flw_secret;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$flw_secret}"]);
        $r = curl_exec($ch);
        if ($r === false) return ["status"=>"error","message"=>curl_error($ch)];
        curl_close($ch);
        $dec = json_decode($r, true);
        return is_array($dec) ? $dec : ["status"=>"error","message"=>"invalid json","raw"=>$r];
    }

    function base_payload($in) {
        return [
            "card_number" => pick($in,"card_number"),
            "cvv" => pick($in,"cvv"),
            "expiry_month" => pick($in,"expiry_month"),
            "expiry_year" => pick($in,"expiry_year"),
            "currency" => pick($in,"currency","NGN"),
            "amount" => pick($in,"amount","100.00"),
            "email" => pick($in,"email"),
            "fullname" => pick($in,"fullname"),
            "phone_number" => pick($in,"phone_number"),
            "tx_ref" => pick($in,"tx_ref","MC-".uniqid()),
            "redirect_url" => pick($in,"redirect_url","https://yourdomain.com/callback")
        ];
    }

    function attach_authorization(&$payload, $in) {
        $pin = pick($in,"pin");
        $city = pick($in,"city");
        $address = pick($in,"address");
        $state = pick($in,"state");
        $country = pick($in,"country");
        $zipcode = pick($in,"zipcode");
        if ($pin) {
            $payload["authorization"] = ["mode"=>"pin","pin"=>$pin];
            return "pin";
        }
        if ($city || $address || $state || $country || $zipcode) {
            $payload["authorization"] = [
                "mode"=>"avs_noauth",
                "city"=>$city,
                "address"=>$address,
                "state"=>$state,
                "country"=>$country,
                "zipcode"=>$zipcode
            ];
            return "avs_noauth";
        }
        return null;
    }

    function validate_required($p) {
        $required = ["card_number","cvv","expiry_month","expiry_year","amount","currency","email","fullname"];
        $missing = [];
        foreach ($required as $k) if (!$p[$k]) $missing[] = $k;
        return $missing;
    }

    function charge_card($payload) {
        global $flw_secret;
        list($client, $err) = encrypt_client($payload);
        if ($err) return ["status"=>"error","message"=>"encryption_failed"];
        return http_post_json("https://api.flutterwave.com/v3/charges?type=card", ["client"=>$client], $flw_secret);
    }

    function validate_otp($flw_ref, $otp) {
        global $flw_secret;
        return http_post_json("https://api.flutterwave.com/v3/validate-charge", ["otp"=>$otp, "flw_ref"=>$flw_ref, "type"=>"card"], $flw_secret);
    }

    function verify_tx($tx_id) {
        global $flw_secret;
        return http_get("https://api.flutterwave.com/v3/transactions/{$tx_id}/verify", $flw_secret);
    }