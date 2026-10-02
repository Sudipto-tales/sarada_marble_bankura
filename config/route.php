<?php
require_once __DIR__ . '/bootstarp.php';
require_once __BASEDIR__ . '/app/bridge/Welcome.php';
require_once __DIR__ . '/../core/RouteManager.php';

RouteManager::dispatch($routes);
