<?php

require_once __DIR__ . '/../core/RouteProvider.php';

class ViewRouteProvider extends RouteProvider
{
    public static function routes(): array
    {
        return [
            'default' => ['Welcome', 'index'],
            'hello' => ['Welcome', 'hello'],
            ... (DEVELOPER_ENABLED ? [
                'developer' => ['Developer', 'index'],
                'developer/login' => ['Developer', 'login'],
                'developer/logout' => ['Developer', 'logout'],
                ...array_fill_keys(array_map(fn($page) => 'developer/' . $page,
                    ['overview', 'apis', 'server', 'workflows', 'incidents', 'activity', 'access', 'settings']), ['Developer', 'index']),
            ] : []),
            // Storefront routes
            'shop' => ['GET' => ['StorefrontProduct', 'listing']],
            'category/{slug}' => ['GET' => ['StorefrontProduct', 'listing']],
            'product/{slug}' => ['GET' => ['StorefrontProduct', 'detail']],
            'cart' => ['GET' => ['Cart', 'index'], 'POST' => ['Cart', 'change']],
            'checkout' => ['GET' => ['Cart', 'checkout'], 'POST' => ['Cart', 'placeOrder']],
            'order/{id}' => ['GET' => ['Cart', 'order']],
            'account' => ['GET' => ['CustomerAccount', 'index'], 'POST' => ['CustomerAccount', 'login']],
            'account/logout' => ['POST' => ['CustomerAccount', 'logout']],
            'account/addresses' => ['POST' => ['CustomerAccount', 'address']],
            // Admin routes
            'admin' => ['Admin', 'index'],
            'admin/products' => ['Admin', 'products'],
            'admin/product' => ['Admin', 'productForm'],
        ];
    }
}

return ViewRouteProvider::routes();
