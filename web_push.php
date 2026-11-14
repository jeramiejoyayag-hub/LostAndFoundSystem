<?php
function web_push_ensure_subscriptions_table($conn) {
    return (bool)$conn->query("
        CREATE TABLE IF NOT EXISTS web_push_subscriptions (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            endpoint_hash CHAR(64) NOT NULL,
            endpoint TEXT NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth_token VARCHAR(100) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_web_push_endpoint_hash (endpoint_hash),
            INDEX idx_web_push_student (student_id)
        )
    ");
}

function web_push_key_file_path() {
    $document_root = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $private_directory = $document_root !== ''
        ? dirname(rtrim($document_root, '\\/'))
        : dirname(__DIR__, 2);
    $project_path = realpath(__DIR__) ?: __DIR__;
    $project_hash = substr(hash('sha256', $project_path), 0, 16);

    return $private_directory . DIRECTORY_SEPARATOR . 'lost_found_vapid_' . $project_hash . '.json';
}

function web_push_base64url_encode($value) {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function web_push_base64url_decode($value) {
    if (!is_string($value) || $value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
        return false;
    }

    $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    return $decoded === false ? false : $decoded;
}

function web_push_openssl_config_path() {
    $configured_path = getenv('OPENSSL_CONF');
    if ($configured_path && is_file($configured_path)) {
        return $configured_path;
    }

    $ini_file = php_ini_loaded_file();
    $binary_directory = $ini_file !== false
        ? dirname($ini_file)
        : (defined('PHP_BINDIR') ? PHP_BINDIR : '');
    $candidates = [
        $binary_directory . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'openssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
        $binary_directory . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
    ];
    foreach ($candidates as $candidate) {
        if ($binary_directory !== '' && is_file($candidate)) {
            return $candidate;
        }
    }

    return false;
}

function web_push_generate_vapid_keys() {
    $export_options = [];
    $config_path = web_push_openssl_config_path();
    $options = [
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ];
    if ($config_path !== false) {
        $options['config'] = $config_path;
        $export_options['config'] = $config_path;
    }
    $key = openssl_pkey_new($options);
    if (!$key || !openssl_pkey_export($key, $private_key, null, $export_options)) {
        return false;
    }

    $details = openssl_pkey_get_details($key);
    if (!$details || empty($details['ec']['x']) || empty($details['ec']['y'])) {
        return false;
    }

    $public_key = "\x04" . $details['ec']['x'] . $details['ec']['y'];
    if (strlen($public_key) !== 65) {
        return false;
    }

    return [
        'publicKey' => web_push_base64url_encode($public_key),
        'privateKey' => $private_key,
    ];
}

function web_push_vapid_keys($conn = null) {
    $env_public_key = getenv('LOST_FOUND_VAPID_PUBLIC_KEY');
    $env_private_key = getenv('LOST_FOUND_VAPID_PRIVATE_KEY_B64');
    if ($env_public_key !== false || $env_private_key !== false) {
        $private_key = $env_private_key !== false ? base64_decode($env_private_key, true) : false;
        if (!$env_public_key || $private_key === false || $private_key === '') {
            error_log("Both LOST_FOUND_VAPID_PUBLIC_KEY and LOST_FOUND_VAPID_PRIVATE_KEY_B64 must be configured.");
            return false;
        }
        $keys = [
            'publicKey' => $env_public_key,
            'privateKey' => $private_key,
        ];
    } else {
        $path = web_push_key_file_path();
        $handle = @fopen($path, 'c+');
        if (!$handle) {
            error_log("Could not open the private Web Push key file: " . $path);
            return false;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            error_log("Could not lock the private Web Push key file.");
            return false;
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        if ($contents === false) {
            flock($handle, LOCK_UN);
            fclose($handle);
            error_log("Could not read the private Web Push key file.");
            return false;
        }

        if (trim($contents) === '') {
            $keys = web_push_generate_vapid_keys();
            if ($keys === false) {
                flock($handle, LOCK_UN);
                fclose($handle);
                error_log("Could not generate Web Push VAPID keys.");
                return false;
            }

            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, json_encode($keys)) === false || !fflush($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
                error_log("Could not save the private Web Push key file.");
                return false;
            }
            @chmod($path, 0600);
        } else {
            $keys = json_decode($contents, true);
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    if (!is_array($keys) || empty($keys['publicKey']) || empty($keys['privateKey'])) {
        error_log("The private Web Push key configuration is invalid.");
        return false;
    }

    $public_bytes = web_push_base64url_decode($keys['publicKey']);
    $private_key = openssl_pkey_get_private($keys['privateKey']);
    $private_details = $private_key ? openssl_pkey_get_details($private_key) : false;
    if ($public_bytes === false || strlen($public_bytes) !== 65 || !$private_details ||
        empty($private_details['ec']['x']) || empty($private_details['ec']['y'])) {
        error_log("The private Web Push key configuration contains invalid key material.");
        return false;
    }
    $derived_public = "\x04" . $private_details['ec']['x'] . $private_details['ec']['y'];
    if (!hash_equals($derived_public, $public_bytes)) {
        error_log("The public and private Web Push keys do not match.");
        return false;
    }

    $keys['subject'] = getenv('LOST_FOUND_VAPID_SUBJECT');
    if (!$keys['subject'] && $conn) {
        $admin = $conn->query("SELECT email FROM users WHERE role = 'admin' OR usertype = 'admin' ORDER BY id ASC LIMIT 1");
        if ($admin && $admin->num_rows > 0) {
            $admin_email = $admin->fetch_assoc()['email'] ?? '';
            if (filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
                $keys['subject'] = 'mailto:' . $admin_email;
            }
        }
        if (!$keys['subject']) {
            error_log("Set LOST_FOUND_VAPID_SUBJECT or provide a valid administrator email for Web Push.");
            return false;
        }
    }
    if (!$keys['subject']) {
        $keys['subject'] = 'mailto:admin@example.com';
    }
    if (!preg_match('/^(mailto:[^\s]+|https:\/\/[^\s]+)$/', $keys['subject'])) {
        error_log("LOST_FOUND_VAPID_SUBJECT must be a mailto: or https: URI.");
        return false;
    }

    return $keys;
}



function web_push_der_signature_to_raw($signature) {
    $length = strlen($signature);
    if ($length < 8 || ord($signature[0]) !== 0x30) {
        return false;
    }

    $offset = 1;
    $sequence_length = ord($signature[$offset++]);
    if (($sequence_length & 0x80) !== 0) {
        $length_bytes = $sequence_length & 0x7f;
        if ($length_bytes < 1 || $length_bytes > 2 || $offset + $length_bytes > $length) {
            return false;
        }
        $sequence_length = 0;
        for ($i = 0; $i < $length_bytes; $i++) {
            $sequence_length = ($sequence_length << 8) | ord($signature[$offset++]);
        }
    }
    if ($sequence_length !== $length - $offset || ord($signature[$offset++]) !== 0x02) {
        return false;
    }

    $r_length = ord($signature[$offset++]);
    $r = substr($signature, $offset, $r_length);
    $offset += $r_length;
    if ($r_length < 1 || $offset + 2 > $length || ord($signature[$offset++]) !== 0x02) {
        return false;
    }
    $s_length = ord($signature[$offset++]);
    $s = substr($signature, $offset, $s_length);
    if ($s_length < 1 || $offset + $s_length !== $length) {
        return false;
    }

    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");
    if (strlen($r) > 32 || strlen($s) > 32) {
        return false;
    }

    return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
}

function web_push_vapid_token($endpoint, $keys) {
    $parts = parse_url($endpoint);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }

    $audience = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
    if (!empty($parts['port']) && $parts['port'] !== 443) {
        $audience .= ':' . intval($parts['port']);
    }
    $header = web_push_base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = web_push_base64url_encode(json_encode([
        'aud' => $audience,
        'exp' => time() + 43200,
        'sub' => $keys['subject'],
    ]));
    $unsigned_token = $header . '.' . $claims;
    $signature = '';
    if (!openssl_sign($unsigned_token, $signature, $keys['privateKey'], OPENSSL_ALGO_SHA256)) {
        return false;
    }
    $raw_signature = web_push_der_signature_to_raw($signature);

    return $raw_signature === false ? false : $unsigned_token . '.' . web_push_base64url_encode($raw_signature);
}

function web_push_allowed_endpoint($endpoint) {
    if (strlen($endpoint) > 2048) {
        return false;
    }
    $parts = parse_url($endpoint);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) ||
        isset($parts['user']) || isset($parts['pass']) ||
        (isset($parts['port']) && intval($parts['port']) !== 443)) {
        return false;
    }

    $host = strtolower($parts['host']);
    if ($host === 'fcm.googleapis.com' || $host === 'web.push.apple.com') {
        return true;
    }
    return $host === 'push.services.mozilla.com' || $host === 'updates.push.services.mozilla.com';
}

function web_push_raw_public_key_pem($raw_public_key) {
    if (strlen($raw_public_key) !== 65 || $raw_public_key[0] !== "\x04") {
        return false;
    }

    $der_prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($der_prefix . $raw_public_key), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

function web_push_encrypt_payload($payload, $subscription, $application_public_key) {
    $receiver_public_key = web_push_base64url_decode($subscription['p256dh']);
    $auth_secret = web_push_base64url_decode($subscription['auth_token']);
    $application_public_key_bytes = web_push_base64url_decode($application_public_key);
    if ($receiver_public_key === false || strlen($receiver_public_key) !== 65 ||
        $auth_secret === false || strlen($auth_secret) !== 16 ||
        $application_public_key_bytes === false || strlen($application_public_key_bytes) !== 65) {
        return false;
    }

    $receiver_pem = web_push_raw_public_key_pem($receiver_public_key);
    $ephemeral_options = [
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ];
    $config_path = web_push_openssl_config_path();
    if ($config_path !== false) {
        $ephemeral_options['config'] = $config_path;
    }
    $ephemeral_key = openssl_pkey_new($ephemeral_options);
    if (!$receiver_pem || !$ephemeral_key) {
        return false;
    }
    $receiver_key = openssl_pkey_get_public($receiver_pem);
    $shared_secret = $receiver_key ? openssl_pkey_derive($receiver_key, $ephemeral_key, 32) : false;
    $ephemeral_details = openssl_pkey_get_details($ephemeral_key);
    if ($shared_secret === false || !$ephemeral_details ||
        empty($ephemeral_details['ec']['x']) || empty($ephemeral_details['ec']['y'])) {
        return false;
    }

    $ephemeral_public_key = "\x04" . $ephemeral_details['ec']['x'] . $ephemeral_details['ec']['y'];
    $input_key_material = hash_hkdf(
        'sha256',
        $shared_secret,
        32,
        "WebPush: info\x00" . $receiver_public_key . $ephemeral_public_key,
        $auth_secret
    );
    $salt = random_bytes(16);
    $content_key = hash_hkdf('sha256', $input_key_material, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $input_key_material, 12, "Content-Encoding: nonce\x00", $salt);
    $tag = '';
    $ciphertext = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $content_key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ciphertext === false || strlen($ciphertext) + strlen($tag) > 4096) {
        return false;
    }

    return $salt . pack('N', 4096) . chr(strlen($ephemeral_public_key)) . $ephemeral_public_key . $ciphertext . $tag;
}

function web_push_send_to_subscription($conn, $subscription, $title, $body, $url, $tag) {
    if (!function_exists('curl_init')) {
        error_log("The PHP cURL extension is required for Web Push.");
        return false;
    }
    if (!web_push_allowed_endpoint($subscription['endpoint'])) {
        error_log("A stored Web Push endpoint is invalid or uses an unsupported push service.");
        return false;
    }

    $keys = web_push_vapid_keys($conn);
    if ($keys === false) {
        return false;
    }
    $jwt = web_push_vapid_token($subscription['endpoint'], $keys);
    $payload = json_encode([
        'title' => $title,
        'body' => $body,
        'url' => $url,
        'tag' => $tag,
        'icon' => 'logo.jpg',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $encrypted_payload = $payload !== false
        ? web_push_encrypt_payload($payload, $subscription, $keys['publicKey'])
        : false;
    if ($jwt === false || $encrypted_payload === false) {
        error_log("Could not prepare an encrypted Web Push notification.");
        return false;
    }

    $curl = curl_init($subscription['endpoint']);
    if (!$curl) {
        error_log("Could not initialize a Web Push request.");
        return false;
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $encrypted_payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: vapid t=' . $jwt . ', k=' . $keys['publicKey'],
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'TTL: 86400',
            'Content-Length: ' . strlen($encrypted_payload),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    curl_exec($curl);
    $status = intval(curl_getinfo($curl, CURLINFO_HTTP_CODE));
    $curl_error = curl_error($curl);
    curl_close($curl);

    if ($status >= 200 && $status < 300) {
        return true;
    }
    if ($curl_error !== '') {
        error_log("Web Push request failed: " . $curl_error);
    } else {
        error_log("Web Push service returned HTTP " . $status . ".");
    }
    return $status;
}

function web_push_notify_student($conn, $student_id, $title, $body, $url, $tag) {
    if (!web_push_ensure_subscriptions_table($conn)) {
        error_log("Could not prepare the Web Push subscriptions table: " . $conn->error);
        return false;
    }

    $stmt = $conn->prepare("SELECT id, endpoint_hash, endpoint, p256dh, auth_token FROM web_push_subscriptions WHERE student_id = ?");
    if (!$stmt) {
        error_log("Could not prepare Web Push subscription lookup: " . $conn->error);
        return false;
    }
    $stmt->bind_param("i", $student_id);
    if (!$stmt->execute()) {
        error_log("Could not load Web Push subscriptions: " . $stmt->error);
        $stmt->close();
        return false;
    }
    $result = $stmt->get_result();
    $subscriptions = [];
    while ($row = $result->fetch_assoc()) {
        $subscriptions[] = $row;
    }
    $stmt->close();

    $sent = 0;
    foreach ($subscriptions as $subscription) {
        $response = web_push_send_to_subscription($conn, $subscription, $title, $body, $url, $tag);
        if ($response === true) {
            $sent++;
        } elseif ($response === 404 || $response === 410) {
            $delete = $conn->prepare("DELETE FROM web_push_subscriptions WHERE id = ?");
            if ($delete) {
                $subscription_id = intval($subscription['id']);
                $delete->bind_param("i", $subscription_id);
                if (!$delete->execute()) {
                    error_log("Could not remove an expired Web Push subscription: " . $delete->error);
                }
                $delete->close();
            } else {
                error_log("Could not prepare expired Web Push subscription cleanup: " . $conn->error);
            }
        }
    }

    return $sent;
}

function web_push_notify_all_students($conn, $title, $body, $url, $tag) {
    if (!web_push_ensure_subscriptions_table($conn)) {
        error_log("Could not prepare Web Push subscriptions for the new-item notification: " . $conn->error);
        return false;
    }

    $result = $conn->query("SELECT DISTINCT student_id FROM web_push_subscriptions");
    if (!$result) {
        error_log("Could not load subscribed students for the new-item notification: " . $conn->error);
        return false;
    }

    $student_ids = [];
    while ($row = $result->fetch_assoc()) {
        $student_ids[] = intval($row['student_id']);
    }
    $result->free();

    $sent = 0;
    foreach ($student_ids as $student_id) {
        $delivered = web_push_notify_student($conn, $student_id, $title, $body, $url, $tag);
        if ($delivered === false) {
            return false;
        }
        $sent += $delivered;
    }

    return $sent;
}
