<?php
$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD');
$password = $password === false ? '' : $password;
$database = getenv('DB_NAME') ?: 'lost_and_found';
$port = filter_var(getenv('DB_PORT') ?: '3306', FILTER_VALIDATE_INT);
$port = $port === false ? 3306 : $port;
$ssl_enabled = filter_var(getenv('DB_SSL') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$ssl_ca = getenv('DB_SSL_CA') ?: '';

mysqli_report(MYSQLI_REPORT_OFF);
$conn = mysqli_init();
if (!$conn) {
    error_log("Could not initialize the MySQL connection.");
    http_response_code(500);
    exit("Database service is temporarily unavailable.");
}
$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
if ($ssl_enabled) {
    if ($ssl_ca === '' && is_file('/etc/ssl/certs/ca-certificates.crt')) {
        $ssl_ca = '/etc/ssl/certs/ca-certificates.crt';
    }
    if ($ssl_ca === '' || !$conn->ssl_set(null, null, $ssl_ca, null, null)) {
        error_log("MySQL SSL is enabled but a usable CA certificate could not be configured.");
        http_response_code(500);
        exit("Database service is temporarily unavailable.");
    }
}

$client_flags = $ssl_enabled ? MYSQLI_CLIENT_SSL : 0;
if (!@$conn->real_connect($host, $user, $password, $database, $port, null, $client_flags)) {
    error_log("MySQL connection failed: " . $conn->connect_error);
    http_response_code(500);
    exit("Database service is temporarily unavailable.");
}
$conn->set_charset('utf8mb4');
?>