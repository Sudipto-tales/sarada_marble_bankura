<?php

// Add each new, globally unique controller/service name here with its real file.
return [
    'BaseController' => 'core/BaseController.php',
    'RouteManager' => 'core/RouteManager.php',
    'DeveloperAccess' => 'core/DeveloperAccess.php',
    'Auth' => 'core/Auth.php',
    'Mailer' => 'core/Mailer.php',
    'Welcome' => 'app/bridge/Welcome.php',
    ... (DEVELOPER_ENABLED ? ['Developer' => 'app/bridge/Developer.php'] : []),
];
