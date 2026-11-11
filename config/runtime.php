<?php

// Shared configuration bootstrap: no HTTP dispatch, session or view output.
$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
    if (class_exists('Dotenv\Dotenv')) {
        Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
    }
}
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
