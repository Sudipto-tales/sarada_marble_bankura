<?php
require_once __DIR__ . '/bootstarp.php';
require_once __BASEDIR__ . '/app/bridge/Welcome.php';
if (DEVELOPER_ENABLED) require_once __BASEDIR__ . '/app/bridge/Developer.php';
require_once __DIR__ . '/../core/RouteManager.php';

RouteManager::dispatch($routes);
