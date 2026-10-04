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
        ];
    }
}

return ViewRouteProvider::routes();
