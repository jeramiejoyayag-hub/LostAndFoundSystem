<?php
// Handles sign-in requests from the home screen.
session_start();
include 'db.php';
include 'google_config.php';
header('Content-Type: application/json');

function out($data) {
    echo json_encode($data);
    exit();
}

function email_uses_allowed_domain($email, $domain) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $at = strrpos($email, '@');
    return $at !== false && strcasecmp(substr($email, $at + 1), $domain) === 0;
}

function authenticated_user_role($user) {
    $role = trim((string)($user['role'] ?? ''));
    $user_type = trim((string)($user['usertype'] ?? ''));
    if (strcasecmp($role, 'admin') === 0 || strcasecmp($user_type, 'admin') === 0) {
        return 'admin';
    }
    return $role !== '' ? $role : ($user_type !== '' ? $user_type : 'student');
}

// Small helper: download a web address (used to check the Google sign-in with Google)
function google_http_get($url, &$error) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $res = curl_exec($ch);
        if ($res === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return false;
        }
        curl_close($ch);
        return $res;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) {
        $error = 'The server could not open the Google web address.';
        return false;
    }
    return $res;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(['success' => false, 'error' => 'Invalid request.']);
}

$action = $_POST['action'] ?? '';

// Where to send a student after logging in (only these two forms are allowed)
$next = $_POST['next'] ?? '';
if (!($next === 'messages' || preg_match('/^item-\d+$/', $next))) {
    $next = 'messages';
}
$student_redirect = 'dashboard.php?open=' . urlencode($next);

// ------------------------------------------------------------ LOGIN
if ($action === 'login') {
    $login_id = trim($_POST['email_or_username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($login_id === '' || $password === '') {
        out(['success' => false, 'error' => 'Please fill in all required fields.']);
    }

    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1");
    if (!$stmt) {
        out(['success' => false, 'error' => 'Something went wrong. Please try again later.']);
    }
    $stmt->bind_param("ss", $login_id, $login_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = ($result && $result->num_rows === 1) ? $result->fetch_assoc() : null;
    $stmt->close();

    $ok = false;
    if ($user) {
        $ok = password_verify($password, $user['password']);

        // Old plain-text accounts: accept once, then upgrade to a secure hash
        if (!$ok && hash_equals($user['password'], $password)) {
            $ok = true;
            $new_hash = password_hash($password, PASSWORD_DEFAULT);
            $up = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            if ($up) {
                $up->bind_param("si", $new_hash, $user['id']);
                $up->execute();
                $up->close();
            }
        }
    }

    if (!$ok) {
        out(['success' => false, 'error' => 'Incorrect username/email or password.']);
    }
    if (!email_uses_allowed_domain($user['email'] ?? '', $GOOGLE_ALLOWED_DOMAIN)) {
        out(['success' => false, 'error' => 'Please use your Google Suite account.']);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];
    $_SESSION['role'] = authenticated_user_role($user);

    out(['success' => true, 'redirect' => ($_SESSION['role'] === 'admin') ? 'admin.php' : $student_redirect]);
}

// ------------------------------------------------------------ LOCAL REGISTRATION DISABLED
if ($action === 'register') {
    out(['success' => false, 'error' => 'Create an account by signing in with your LCUP GSuite account.']);
}

// ------------------------------------------------------------ GOOGLE SIGN-IN
if ($action === 'google') {
    if (empty($GOOGLE_CLIENT_ID)) {
        out(['success' => false, 'error' => 'Google sign-in is not set up yet.']);
    }

    $credential = $_POST['credential'] ?? '';
    if ($credential === '') {
        out(['success' => false, 'error' => 'No Google sign-in was received. Please try again.']);
    }

    // Ask Google to check that the sign-in is real
    $http_error = '';
    $body = google_http_get('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential), $http_error);
    if ($body === false) {
        out(['success' => false, 'error' => 'Could not reach Google to verify your sign-in. ' . $http_error]);
    }
    $info = json_decode($body, true);
    if (!is_array($info) || isset($info['error_description']) || empty($info['email'])) {
        out(['success' => false, 'error' => 'Google sign-in could not be verified. Please try again.']);
    }

    $issuer_ok = in_array($info['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true);
    $audience_ok = hash_equals($GOOGLE_CLIENT_ID, (string)($info['aud'] ?? ''));
    $not_expired = intval($info['exp'] ?? 0) > time();
    $email_verified = (($info['email_verified'] ?? '') === 'true') || (($info['email_verified'] ?? false) === true);
    if (!$issuer_ok || !$audience_ok || !$not_expired || !$email_verified) {
        out(['success' => false, 'error' => 'Google sign-in was rejected. Please try again.']);
    }

    $g_email = strtolower(trim($info['email']));
    $g_name = trim($info['name'] ?? '');
    if ($g_name === '') {
        $g_name = strstr($g_email, '@', true);
    }

    if (!email_uses_allowed_domain($g_email, $GOOGLE_ALLOWED_DOMAIN)) {
        out(['success' => false, 'error' => 'Please use your Google Suite account.']);
    }

    // Find the account with this email, or create a student account
    $find = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    if (!$find) {
        out(['success' => false, 'error' => 'SQL error: ' . $conn->error]);
    }
    $find->bind_param("s", $g_email);
    $find->execute();
    $found = $find->get_result();
    $user = ($found && $found->num_rows === 1) ? $found->fetch_assoc() : null;
    $find->close();

    if (!$user) {
        // Make a unique username from the email, e.g. juan.delacruz
        $base = preg_replace('/[^a-z0-9._-]/i', '', strstr($g_email, '@', true));
        if ($base === '') $base = 'student';
        $username = $base;
        $n = 1;
        $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        while ($chk) {
            $chk->bind_param("s", $username);
            $chk->execute();
            $taken = $chk->get_result();
            if (!$taken || $taken->num_rows === 0) break;
            $username = $base . $n;
            $n++;
            if ($n > 500) break;
        }
        if ($chk) $chk->close();

        // Google accounts get a random password nobody knows (they sign in with Google)
        $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $role = 'student';
        $ins = $conn->prepare("INSERT INTO users (full_name, username, email, password, role) VALUES (?, ?, ?, ?, ?)");
        if (!$ins) {
            out(['success' => false, 'error' => 'SQL error: ' . $conn->error]);
        }
        $ins->bind_param("sssss", $g_name, $username, $g_email, $hash, $role);
        if (!$ins->execute()) {
            out(['success' => false, 'error' => 'Could not create your account: ' . $ins->error]);
        }
        $user = ['id' => $ins->insert_id, 'full_name' => $g_name, 'username' => $username, 'role' => $role];
        $ins->close();
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];
    $_SESSION['role'] = authenticated_user_role($user);

    out(['success' => true, 'redirect' => ($_SESSION['role'] === 'admin') ? 'admin.php' : $student_redirect]);
}

out(['success' => false, 'error' => 'Unknown action: "' . $action . '". Make sure you saved the latest auth_ajax.php.']);