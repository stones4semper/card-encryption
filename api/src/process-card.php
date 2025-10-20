<?php
    require_once __DIR__.'/conn.php';

    $in = array_merge($_GET, $_POST, json_input());

    $action = pick($in,"action", "charge");

    if ($action === "charge") {
        $payload = base_payload($in);
        $missing = validate_required($payload);
        if ($missing) { 
            echo json_encode(["status"=>"error","message"=>"missing_fields","fields"=>$missing]); 
            exit; 
        }

        $auth_mode_sent = attach_authorization($payload, $in);
        $res = charge_card($payload);

        $data = $res["data"] ?? null;
        $metaAuth = $res["meta"]["authorization"] ?? ($data["meta"]["authorization"] ?? null);
        $mode = $data["authorization"]["mode"] ?? ($metaAuth["mode"] ?? null);
        $redirect = $data["meta"]["authorization"]["redirect"] ?? ($metaAuth["redirect"] ?? null);
        $flw_ref = $data["flw_ref"] ?? null;
        $tx_id = $data["id"] ?? null;
        $resp_status = $data["status"] ?? ($res["status"] ?? null);

        if ($mode === "redirect" || $mode === "3DS") {
            echo json_encode(["status"=>"pending_redirect","redirect"=>$redirect,"tx_id"=>$tx_id,"flw_ref"=>$flw_ref,"raw"=>$res]); 
            exit;
        }
        if ($mode === "pin" && !$auth_mode_sent) {
            echo json_encode(["status"=>"need_pin","fields"=>["pin"],"tx_id"=>$tx_id,"flw_ref"=>$flw_ref,"raw"=>$res]); 
            exit;
        }
        if ($mode === "avs_noauth" && $auth_mode_sent !== "avs_noauth") {
            echo json_encode(["status"=>"need_address","fields"=>["city","address","state","country","zipcode"],"tx_id"=>$tx_id,"flw_ref"=>$flw_ref,"raw"=>$res]); 
            exit;
        }
        if (strtolower((string)$resp_status) === "successful") {
            $v = $tx_id ? verify_tx($tx_id) : null;
            echo json_encode(["status"=>"success","charge"=>$res,"verify"=>$v]); 
            exit;
        }
        if ($flw_ref && pick($in,"otp")) {
            $val = validate_otp($flw_ref, pick($in,"otp"));
            $val_tx_id = ($val["data"]["id"] ?? $tx_id);
            $v = $val_tx_id ? verify_tx($val_tx_id) : $val;
            echo json_encode(["status"=>"validated","validate"=>$val,"verify"=>$v]); 
            exit;
        }
        echo json_encode(["status"=>"pending","next_mode"=>$mode ?: "unknown","tx_id"=>$tx_id,"flw_ref"=>$flw_ref,"raw"=>$res]); 
        exit;
    }

    if ($action === "validate") {
        $otp = pick($in,"otp"); $flw_ref = pick($in,"flw_ref");
        if (!$otp || !$flw_ref) { 
            echo json_encode(["status"=>"error","message"=>"otp and flw_ref required"]); 
            exit; 
        }
        $val = validate_otp($flw_ref, $otp);
        $tx_id = $val["data"]["id"] ?? null;
        $v = $tx_id ? verify_tx($tx_id) : null;
        echo json_encode(["status"=>"validated","validate"=>$val,"verify"=>$v]); 
        exit;
    }

    if ($action === "verify") {
        $tx_id = pick($in,"tx_id");
        if (!$tx_id) { 
            echo json_encode(["status"=>"error","message"=>"tx_id required"]); 
            exit; 
        }
        echo json_encode(verify_tx((int)$tx_id)); 
        exit;
    }

    echo json_encode(["status"=>"error","message"=>"unknown action"]);