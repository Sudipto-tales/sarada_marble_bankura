<?php
// Application configuration loaded from environment variables when available
$baseDir = dirname(__DIR__); // Get the base directory of the project
define('__BASEDIR__', $baseDir);

define('APP_ENV', env('APP_ENV', 'production'));
define('APP_DEBUG', filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN));

$live_base_url = env('APP_URL', 'https://localhost/Coder-framework');

// Auto-detect environment
if ($_SERVER['HTTP_HOST'] === 'localhost') {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $folder_name = basename(__DIR__);
    $base_url = env('APP_URL', $protocol . $_SERVER['HTTP_HOST'] . "/" . $folder_name);
} else {
    $base_url = $live_base_url;
}

$frontendRoutes = require __DIR__ . '/../app/view.php';
$apiRoutes = require __DIR__ . '/../api/gateway.php';
$routes = array_merge($frontendRoutes, $apiRoutes);

?>
