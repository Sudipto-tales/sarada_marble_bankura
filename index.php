<?php

try {
    require_once __DIR__ . '/config/route.php';
} catch (Throwable $error) {
    // SQL, filesystem and provider exceptions must never reach a public response.
    $rejected = $error instanceof RequestRejected;
    http_response_code($rejected ? $error->status : 503);
    header('Cache-Control: no-store');
    $message = $rejected ? $error->getMessage() : 'Service is temporarily unavailable.';
    $route = $_GET['route'] ?? parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_string($route) && preg_match('~(?:^|/)api(?:/|$)~', $route)) {
        header('Content-Type: application/json');
        echo json_encode(['error' => ['code' => $rejected ? $error->errorCode : 'service_unavailable',
            'message' => $message], 'request_id' => bin2hex(random_bytes(16))]);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }
}
