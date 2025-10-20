<?php
require_once __DIR__.'/api/src/env.php';
header('Content-Type: application/json');

function json_input() {
	$raw = file_get_contents('php://input');
	if (!$raw) return [];
	$j = json_decode($raw, true);
	return is_array($j) ? $j : [];
}

function pick($src, $key, $default=null) {
	return isset($src[$key]) && $src[$key] !== '' ? $src[$key] : $default;
}

function encrypt_client(string $encryptionKey, array $payload) {
	$enc = openssl_encrypt(json_encode($payload), 'DES-EDE3', $encryptionKey, OPENSSL_RAW_DATA);
	if ($enc === false) return [null, 'openssl_encrypt failed'];
	return [base64_encode($enc), null];
}

function http_post_json($url, $body, $secret) {
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, [
		"Authorization: Bearer {$secret}",
		"Content-Type: application/json"
	]);
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
	$r = curl_exec($ch);
	if ($r === false) return ["status"=>"error","message"=>curl_error($ch)];
	curl_close($ch);
	$dec = json_decode($r, true);
	return is_array($dec) ? $dec : ["status"=>"error","message"=>"invalid json","raw"=>$r];
}

function http_get($url, $secret) {
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$secret}"]);
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

function charge_card($secret, $encryptionKey, $payload) {
	list($client, $err) = encrypt_client($encryptionKey, $payload);
	if ($err) return ["status"=>"error","message"=>"encryption_failed"];
	return http_post_json("https://api.flutterwave.com/v3/charges?type=card", ["client"=>$client], $secret);
}

function validate_otp($secret, $flw_ref, $otp) {
	return http_post_json("https://api.flutterwave.com/v3/validate-charge", ["otp"=>$otp, "flw_ref"=>$flw_ref, "type"=>"card"], $secret);
}

function verify_tx($secret, $tx_id) {
	return http_get("https://api.flutterwave.com/v3/transactions/{$tx_id}/verify", $secret);
}

$in = array_merge($_GET, $_POST, json_input());

$secret = $flw_secret;		
$encryptionKey = $flw_encryption_key;

if (!$secret || !$encryptionKey) { echo json_encode(["status"=>"error","message"=>"missing keys"]); exit; }

$action = pick($in,"action","charge");

if ($action === "charge") {
	$payload = base_payload($in);
	$missing = validate_required($payload);
	if ($missing) { echo json_encode(["status"=>"error","message"=>"missing_fields","fields"=>$missing]); exit; }

	$auth_mode_sent = attach_authorization($payload, $in);
	$res = charge_card($secret, $encryptionKey, $payload);

	$data = $res["data"] ?? null;
	$metaAuth = $res["meta"]["authorization"] ?? ($data["meta"]["authorization"] ?? null);
	$mode = $data["authorization"]["mode"] ?? ($metaAuth["mode"] ?? null);
	$redirect = $data["meta"]["authorization"]["redirect"] ?? ($metaAuth["redirect"] ?? null);
	$flw_ref = $data["flw_ref"] ?? null;
	$tx_id = $data["id"] ?? null;
	$resp_status = $data["status"] ?? ($res["status"] ?? null);

	if ($mode === "redirect" || $mode === "3DS") {
		echo json_encode(["status"=>"pending_redirect","redirect"=>$redirect,"tx_id"=>$tx_id,"flw_ref"=>$flw_ref,"raw"=>$res]); exit;
	}
	if ($mode === "pin" && !$auth_mode_sent) {
		echo json_encode(["status"=>"need_pin","fields"=>["pin"],"tx_id"=>$tx_id,"flw_ref"=>$flw_ref,"raw"=>$res]); exit;
	}
	if ($mode === "avs_noauth" && $auth_mode_sent !== "avs_noauth") {
		echo json_encode(["status"=>"need_address","fields"=>["city","address","state","country","zipcode"],"tx_id"=>$tx_id,"flw_ref"=>$flw_ref,"raw"=>$res]); exit;
	}
	if (strtolower((string)$resp_status) === "successful") {
		$v = $tx_id ? verify_tx($secret, $tx_id) : null;
		echo json_encode(["status"=>"success","charge"=>$res,"verify"=>$v]); exit;
	}
	if ($flw_ref && pick($in,"otp")) {
		$val = validate_otp($secret, $flw_ref, pick($in,"otp"));
		$val_tx_id = ($val["data"]["id"] ?? $tx_id);
		$v = $val_tx_id ? verify_tx($secret, $val_tx_id) : $val;
		echo json_encode(["status"=>"validated","validate"=>$val,"verify"=>$v]); exit;
	}
	echo json_encode(["status"=>"pending","next_mode"=>$mode ?: "unknown","tx_id"=>$tx_id,"flw_ref"=>$flw_ref,"raw"=>$res]); exit;
}

if ($action === "validate") {
	$otp = pick($in,"otp"); $flw_ref = pick($in,"flw_ref");
	if (!$otp || !$flw_ref) { echo json_encode(["status"=>"error","message"=>"otp and flw_ref required"]); exit; }
	$val = validate_otp($secret, $flw_ref, $otp);
	$tx_id = $val["data"]["id"] ?? null;
	$v = $tx_id ? verify_tx($secret, $tx_id) : null;
	echo json_encode(["status"=>"validated","validate"=>$val,"verify"=>$v]); exit;
}

if ($action === "verify") {
	$tx_id = pick($in,"tx_id");
	if (!$tx_id) { echo json_encode(["status"=>"error","message"=>"tx_id required"]); exit; }
	echo json_encode(verify_tx($secret, (int)$tx_id)); exit;
}

echo json_encode(["status"=>"error","message"=>"unknown action"]);



