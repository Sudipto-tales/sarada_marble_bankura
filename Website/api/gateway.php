<?php

require_once __DIR__ . '/../core/RouteProvider.php';

class ApiGatewayProvider extends RouteProvider
{
    public static function routes(): array
    {
        return [
            ... (DEVELOPER_ENABLED ? ['api/developer/metrics' => ['Developer', 'metrics']] : []),
            ... (DEVELOPER_ENABLED ? [
                'api/developer/knowledge' => ['GET'=>['Developer','knowledge']],
                'api/developer/report' => ['GET'=>['Developer','report']],
            ] : []),
            // Storefront API routes
            'api/webhooks/razorpay' => ['POST' => ['Payment', 'webhook']],
            'api/v1/products' => ['GET' => ['StorefrontProductController', 'listing']],
            'api/v1/products/{slug}' => ['GET' => ['StorefrontProductController', 'detail']],
            // Owned customer cart/address/order API (cookie session; CSRF on mutations).
            'api/v1/cart' => ['GET' => ['StorefrontApiController', 'cart'], 'POST' => ['StorefrontApiController', 'cartChange']],
            'api/v1/addresses' => ['GET' => ['StorefrontApiController', 'addresses'], 'POST' => ['StorefrontApiController', 'addressCreate']],
            'api/v1/orders' => ['GET' => ['StorefrontApiController', 'orders'], 'POST' => ['StorefrontApiController', 'orderCreate']],
            'api/v1/orders/{id}' => ['GET' => ['StorefrontApiController', 'order']],
            'api/v1/events' => ['POST' => ['StorefrontApiController', 'events']],
            // Staff API (exact grant per endpoint; CSRF + operation key on mutations).
            'api/admin/products' => ['GET' => ['AdminApiController', 'products'], 'POST' => ['AdminApiController', 'productSave']],
            'api/admin/inventory' => ['POST' => ['AdminApiController', 'inventoryAdjust']],
            'api/admin/orders/{id}/status' => ['POST' => ['AdminApiController', 'orderStatus']],
        ];
    }
}

return ApiGatewayProvider::routes();
