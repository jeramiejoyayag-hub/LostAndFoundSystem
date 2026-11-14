<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/web_push.php';
$keys = web_push_generate_vapid_keys();
if ($keys === false) {
    fwrite(STDERR, "Could not generate Web Push VAPID keys.\n");
    exit(1);
}

echo "LOST_FOUND_VAPID_PUBLIC_KEY=" . $keys['publicKey'] . PHP_EOL;
echo "LOST_FOUND_VAPID_PRIVATE_KEY_B64=" . base64_encode($keys['privateKey']) . PHP_EOL;
