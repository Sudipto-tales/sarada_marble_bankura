<?php

require_once __DIR__ . '/../core/RouteProvider.php';

class ApiGatewayProvider extends RouteProvider
{
    public static function routes(): array
    {
        return [
            ... (DEVELOPER_ENABLED ? ['api/developer/metrics' => ['Developer', 'metrics']] : []),
            // Storefront API routes
            'api/v1/products' => ['GET' => ['StorefrontProductController', 'listing']],
            'api/v1/products/{slug}' => ['GET' => ['StorefrontProductController', 'detail']],
        ];
    }
}

return ApiGatewayProvider::routes();
