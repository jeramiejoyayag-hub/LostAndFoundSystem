<?php
session_start();
include 'db.php';
include 'web_push.php';
header('Content-Type: application/json; charset=utf-8');

function web_push_response($status, $data) {
    http_response_code($status);
    echo json_encode($data);
    exit();
}

if (!isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? $_SESSION['usertype'] ?? 'student') === 'admin') {
    web_push_response(403, ['success' => false, 'error' => 'Sign in as a student to manage device notifications.']);
}

$student_id = intval($_SESSION['user_id']);
if (!web_push_ensure_subscriptions_table($conn)) {
    error_log("Could not prepare Web Push subscriptions: " . $conn->error);
    web_push_response(500, ['success' => false, 'error' => 'Device notifications are temporarily unavailable.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $keys = web_push_vapid_keys($conn);
    if ($keys === false) {
        web_push_response(503, ['success' => false, 'error' => 'Device notifications are not configured on this server.']);
    }
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM web_push_subscriptions WHERE student_id = ?");
    if (!$stmt) {
        error_log("Could not prepare Web Push status lookup: " . $conn->error);
        web_push_response(500, ['success' => false, 'error' => 'Could not load device notification settings.']);
    }
    $stmt->bind_param("i", $student_id);
    if (!$stmt->execute()) {
        error_log("Could not load Web Push status: " . $stmt->error);
        $stmt->close();
        web_push_response(500, ['success' => false, 'error' => 'Could not load device notification settings.']);
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    web_push_response(200, [
        'success' => true,
        'publicKey' => $keys['publicKey'],
        'subscribed' => intval($row['total']) > 0,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    web_push_response(405, ['success' => false, 'error' => 'Method not allowed.']);
}

$raw_body = file_get_contents('php://input');
if ($raw_body === false || strlen($raw_body) > 8192) {
    web_push_response(400, ['success' => false, 'error' => 'Invalid request.']);
}
$request = json_decode($raw_body, true);
if (!is_array($request) || empty($_SESSION['web_push_csrf']) ||
    !isset($request['csrfToken']) || !is_string($request['csrfToken']) ||
    !hash_equals($_SESSION['web_push_csrf'], $request['csrfToken'])) {
    web_push_response(403, ['success' => false, 'error' => 'Refresh the page and try again.']);
}

$action = $request['action'] ?? '';
if ($action === 'subscribe') {
    $subscription = $request['subscription'] ?? null;
    $endpoint = is_array($subscription) ? ($subscription['endpoint'] ?? '') : '';
    $keys = is_array($subscription) ? ($subscription['keys'] ?? []) : [];
    $p256dh = is_array($keys) ? ($keys['p256dh'] ?? '') : '';
    $auth_token = is_array($keys) ? ($keys['auth'] ?? '') : '';
    $decoded_public_key = web_push_base64url_decode($p256dh);
    $decoded_auth = web_push_base64url_decode($auth_token);
    if (!is_string($endpoint) || !web_push_allowed_endpoint($endpoint) ||
        $decoded_public_key === false || strlen($decoded_public_key) !== 65 || $decoded_public_key[0] !== "\x04" ||
        $decoded_auth === false || strlen($decoded_auth) !== 16) {
        web_push_response(400, ['success' => false, 'error' => 'The browser returned an invalid device subscription.']);
    }

    $endpoint_hash = hash('sha256', $endpoint);
    $stmt = $conn->prepare("
        INSERT INTO web_push_subscriptions (student_id, endpoint_hash, endpoint, p256dh, auth_token)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE student_id = VALUES(student_id), endpoint = VALUES(endpoint),
            p256dh = VALUES(p256dh), auth_token = VALUES(auth_token), updated_at = CURRENT_TIMESTAMP
    ");
    if (!$stmt) {
        error_log("Could not prepare Web Push subscription save: " . $conn->error);
        web_push_response(500, ['success' => false, 'error' => 'Could not save this device. Please try again.']);
    }
    $stmt->bind_param("issss", $student_id, $endpoint_hash, $endpoint, $p256dh, $auth_token);
    if (!$stmt->execute()) {
        error_log("Could not save Web Push subscription: " . $stmt->error);
        $stmt->close();
        web_push_response(500, ['success' => false, 'error' => 'Could not save this device. Please try again.']);
    }
    $stmt->close();
    web_push_response(200, ['success' => true, 'subscribed' => true]);
}

if ($action === 'unsubscribe') {
    $endpoint = $request['endpoint'] ?? '';
    if (!is_string($endpoint) || !web_push_allowed_endpoint($endpoint)) {
        web_push_response(400, ['success' => false, 'error' => 'The browser returned an invalid device subscription.']);
    }
    $endpoint_hash = hash('sha256', $endpoint);
    $stmt = $conn->prepare("DELETE FROM web_push_subscriptions WHERE student_id = ? AND endpoint_hash = ?");
    if (!$stmt) {
        error_log("Could not prepare Web Push subscription removal: " . $conn->error);
        web_push_response(500, ['success' => false, 'error' => 'Could not remove this device. Please try again.']);
    }
    $stmt->bind_param("is", $student_id, $endpoint_hash);
    if (!$stmt->execute()) {
        error_log("Could not remove Web Push subscription: " . $stmt->error);
        $stmt->close();
        web_push_response(500, ['success' => false, 'error' => 'Could not remove this device. Please try again.']);
    }
    $stmt->close();
    web_push_response(200, ['success' => true, 'subscribed' => false]);
}

web_push_response(400, ['success' => false, 'error' => 'Unknown device notification action.']);
