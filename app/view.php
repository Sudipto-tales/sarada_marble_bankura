<?php

require_once __DIR__ . '/../core/RouteProvider.php';

class ViewRouteProvider extends RouteProvider
{
    public static function routes(): array
    {
        return [
            'default' => ['Welcome', 'index'],
            'hello' => ['Welcome', 'hello'],
        ];
    }
}

return ViewRouteProvider::routes();
