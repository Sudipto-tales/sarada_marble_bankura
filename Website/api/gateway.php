<?php

require_once __DIR__ . '/../core/RouteProvider.php';

class ApiGatewayProvider extends RouteProvider
{
    public static function routes(): array
    {
        return [
            ... (DEVELOPER_ENABLED ? ['api/developer/metrics' => ['Developer', 'metrics']] : []),
            // Add API routes here, for example:
            // Register controllers in config/classes.php. New routes can use method maps:
            // 'api/v1/products/{id}' => ['GET' => ['StorefrontProductController', 'show']],
        ];
    }
}

return ApiGatewayProvider::routes();
