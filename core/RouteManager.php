<?php

class RouteManager
{
    public static function dispatch(array $routes): void
    {
        $route = self::resolveRoute();

        if (isset($routes[$route])) {
            [$class, $method] = $routes[$route];

            if (!class_exists($class)) {
                http_response_code(500);
                die("Server Error: Controller not found ({$class})");
            }

            $controller = new $class();
            if (!method_exists($controller, $method)) {
                http_response_code(500);
                die("Server Error: Controller method not found ({$method})");
            }

            call_user_func([$controller, $method]);
            return;
        }

        http_response_code(404);
        load_view('resources/views/404.php');
    }

    public static function resolveRoute(): string
    {
        if (!empty($_GET['route'])) {
            return trim($_GET['route'], '/');
        }

        $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $scriptName = $_SERVER['SCRIPT_NAME'];
        $basePath = str_replace('\\', '/', dirname($scriptName));

        if ($basePath !== '/' && str_starts_with($requestUri, $basePath)) {
            $requestUri = substr($requestUri, strlen($basePath));
        }

        $route = trim($requestUri, '/');
        return $route === '' ? 'default' : $route;
    }
}
?>