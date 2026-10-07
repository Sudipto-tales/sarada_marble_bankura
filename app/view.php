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
            '' => ['StorefrontProduct', 'listing'],
            'category/{slug}' => ['StorefrontProduct', 'listing'],
            'product/{slug}' => ['StorefrontProduct', 'detail'],
            'cart' => ['Cart', 'index'],
            'checkout' => ['Cart', 'checkout'],
            // Admin routes
            'admin' => ['Admin', 'index'],
            'admin/products' => ['Admin', 'products'],
            'admin/product' => ['Admin', 'productForm'],
        ];
    }
}

return ViewRouteProvider::routes();
