<?php

require_once __DIR__ . '/../core/RouteProvider.php';

class ApiGatewayProvider extends RouteProvider
{
    public static function routes(): array
    {
        return [
            // Add API routes here, for example:
            // 'api/status' => ['ApiController', 'status'],
        ];
    }
}

return ApiGatewayProvider::routes();
