<?php

require_once __DIR__ . '/../core/RouteProvider.php';

class ApiGatewayProvider extends RouteProvider
{
    public static function routes(): array
    {
        return [
            ... (DEVELOPER_ENABLED ? ['api/developer/metrics' => ['Developer', 'metrics']] : []),
            // Add API routes here, for example:
            // 'api/status' => ['ApiController', 'status'],
        ];
    }
}

return ApiGatewayProvider::routes();
