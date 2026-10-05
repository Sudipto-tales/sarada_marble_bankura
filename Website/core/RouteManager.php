<?php

final class RouteManager
{
    public static function dispatch(array $routes): void
    {
        $compiled = self::compile($routes);
        try {
            $route = self::resolveRoute();
        } catch (InvalidArgumentException $error) {
            $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '';
            $query = is_string($_GET['route'] ?? null) ? $_GET['route'] : '';
            self::error(400, 'invalid_path', 'Invalid request path.', str_contains($uri, '/api/') || str_starts_with(ltrim($query, '/'), 'api/'));
            return;
        }
        $api = $route === 'api' || str_starts_with($route, 'api/');
        $match = $compiled['exact'][$route] ?? null;
        $parameters = [];
        if ($match === null) {
            foreach ($compiled['templates'] as $template) {
                if (!preg_match($template['pattern'], $route, $captures)) continue;
                $valid = true;
                foreach ($template['parameters'] as $position => $type) {
                    $value = $captures[$position + 1];
                    if ($type === 'slug' && strlen($value) > 120) { $valid = false; break; }
                    if ($type === 'id') {
                        if (strlen($value) > strlen((string) PHP_INT_MAX)
                            || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
                            $valid = false;
                            break;
                        }
                        $value = (int) $value;
                    }
                    $parameters[] = $value;
                }
                if (!$valid) { $parameters = []; continue; }
                $match = $template;
                break;
            }
        }
        if ($match === null) { self::error(404, 'not_found', 'Page not found.', $api); return; }
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $handler = $match['legacy'] ? $match['handlers'] : ($match['handlers'][$requestMethod] ?? null);
        if ($handler === null) {
            header('Allow: ' . implode(', ', array_keys($match['handlers'])));
            self::error(405, 'method_not_allowed', 'Method not allowed.', $api);
            return;
        }
        [$class, $method] = $handler;
        if (!class_exists($class) || !is_callable([$controller = new $class(), $method])) {
            self::error(500, 'server_error', 'Request handler unavailable.', $api);
            return;
        }
        $controller->$method(...$parameters);
    }

    /** Validate registration independently of any user-selected path. */
    public static function compile(array $routes): array
    {
        $exact = [];
        $templates = [];
        foreach ($routes as $path => $definition) {
            if (!is_string($path) || $path === '' || trim($path, '/') !== $path || strlen($path) > 2048) {
                throw new LogicException('Invalid route key.');
            }
            $legacy = is_array($definition) && array_is_list($definition);
            if ($legacy) {
                self::validateHandler($definition);
            } else {
                if (!is_array($definition) || !$definition) throw new LogicException('Empty method map.');
                foreach ($definition as $method => $handler) {
                    if (!in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
                        throw new LogicException('Invalid registered HTTP method.');
                    }
                    self::validateHandler($handler);
                }
            }
            $entry = ['legacy' => $legacy, 'handlers' => $definition, 'parameters' => [], 'segments' => []];
            $parts = [];
            foreach (explode('/', $path) as $segment) {
                if (preg_match('/\A\{(id|slug)\}\z/', $segment, $parameter)) {
                    if (in_array($parameter[1], $entry['parameters'], true)) throw new LogicException('Repeated route parameter.');
                    $entry['parameters'][] = $parameter[1];
                    $entry['segments'][] = ['type' => $parameter[1]];
                    $parts[] = $parameter[1] === 'id' ? '([1-9][0-9]{0,18})' : '([a-z0-9]+(?:-[a-z0-9]+)*)';
                } else {
                    if (!preg_match('/\A[A-Za-z0-9_~-]+\z/', $segment)) throw new LogicException('Invalid literal route segment.');
                    $entry['segments'][] = ['literal' => $segment];
                    $parts[] = preg_quote($segment, '~');
                }
            }
            $entry['pattern'] = '~\A' . implode('/', $parts) . '\z~D';
            if (!$entry['parameters']) { $exact[$path] = $entry; continue; }
            foreach ($templates as $previous) {
                if (self::overlap($previous['segments'], $entry['segments'])) {
                    throw new LogicException('Ambiguous route templates.');
                }
            }
            $templates[] = $entry;
        }
        return compact('exact', 'templates');
    }

    private static function validateHandler($handler): void
    {
        if (!is_array($handler) || !array_is_list($handler) || count($handler) !== 2
            || !is_string($handler[0]) || !is_string($handler[1])
            || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $handler[0])
            || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $handler[1])) {
            throw new LogicException('Invalid route handler.');
        }
    }

    private static function overlap(array $a, array $b): bool
    {
        if (count($a) !== count($b)) return false;
        foreach ($a as $i => $segment) {
            $other = $b[$i];
            if (isset($segment['literal'], $other['literal'])) {
                if ($segment['literal'] !== $other['literal']) return false;
            } elseif (isset($segment['literal']) || isset($other['literal'])) {
                $literal = $segment['literal'] ?? $other['literal'];
                $type = $segment['type'] ?? $other['type'];
                if (!preg_match($type === 'id' ? '/\A[1-9][0-9]{0,18}\z/' : '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $literal)) return false;
            }
            // id and slug captures overlap on positive integer strings.
        }
        return true;
    }

    public static function resolveRoute(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        if (!is_string($uri) || strlen($uri) > 8192 || preg_match('/[\x00-\x20\x7f]/', $uri)) {
            throw new InvalidArgumentException('Invalid URI.');
        }
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || strlen($path) > 2048
            || preg_match('/%(?![0-9a-f]{2})|%(?:2f|5c|00)/i', $path)) throw new InvalidArgumentException('Invalid path encoding.');
        $path = rawurldecode($path);
        self::validatePath($path);
        if (array_key_exists('route', $_GET)) {
            // PHP already decoded query values once; never double-decode them.
            $path = $_GET['route'];
            if (!is_string($path)) throw new InvalidArgumentException('Invalid query route.');
            self::validatePath($path);
        } else {
            $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
            if (!is_string($script)) throw new InvalidArgumentException('Invalid script path.');
            $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
            if ($base !== '' && $base !== '.' && ($path === $base || str_starts_with($path, $base . '/'))) {
                $path = substr($path, strlen($base));
            }
        }
        $route = trim($path, '/');
        return $route === '' ? 'default' : $route;
    }

    private static function validatePath(string $path): void
    {
        if (strlen($path) > 2048 || preg_match('/[%\\\\\x00-\x20\x7f]/', $path)
            || str_contains(trim($path, '/'), '//')) throw new InvalidArgumentException('Invalid route path.');
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') throw new InvalidArgumentException('Invalid route segment.');
            if (strlen($segment) > 190) throw new InvalidArgumentException('Oversized route segment.');
        }
    }

    private static function error(int $status, string $code, string $message, bool $api): void
    {
        http_response_code($status);
        if ($api) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => ['code' => $code, 'message' => $message], 'request_id' => bin2hex(random_bytes(16))], JSON_THROW_ON_ERROR);
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>' . $status . '</title><h1>'
            . $status . '</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></html>';
    }
}
