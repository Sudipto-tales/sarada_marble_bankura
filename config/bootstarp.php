<?php

// Load Composer autoload and dotenv if available
$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;

    if (class_exists('Dotenv\\Dotenv')) {
        $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
        $dotenv->safeLoad();
    }
}

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/framework.php';
require_once __DIR__ . '/db.php';

// Load all core files dynamically
foreach (glob(__BASEDIR__ . '/core/*.php') as $filename) {
    require_once $filename;
}

// Function to load view files dynamically
function load_view($path, $data = []) {
    $base_dir = dirname(__DIR__); 
    $file_path = $base_dir . '/' . $path;
    if (file_exists($file_path)) {
        extract($data);
        require $file_path;
    } else {
        echo "Error: View '{$path}' not found!";
    }
}

function base_url($path = '') {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $base_url = $protocol . $_SERVER['HTTP_HOST'] . str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
    
    return rtrim($base_url, '/') . '/' . ltrim($path, '/');
}
?>
