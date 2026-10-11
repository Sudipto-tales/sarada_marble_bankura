<?php

/** HTTP boundary for commerce; legacy and Developer controllers remain independent. */
abstract class StorefrontController extends BaseController
{
    protected PDO $pdo;
    public function __construct()
    {
        $this->pdo = $GLOBALS['pdo'];
        if (session_status() !== PHP_SESSION_ACTIVE) session_cache_limiter('');
    }

    protected function handle(callable $action, bool $api = false): void
    {
        header('Cache-Control: private, no-store');
        header('Content-Type: ' . ($api ? 'application/json' : 'text/html') . '; charset=utf-8');
        try { $action(); }
        catch (Throwable $error) {
            $status = $error instanceof RequestRejected ? $error->status : 503;
            $code = $error instanceof RequestRejected ? $error->errorCode : 'service_unavailable';
            $message = $error instanceof RequestRejected ? $error->getMessage() : 'The store is temporarily unavailable. Please retry.';
            http_response_code($status);
            if ($api) echo json_encode(['error' => ['code' => $code, 'message' => $message], 'request_id' => bin2hex(random_bytes(16))], JSON_THROW_ON_ERROR);
            else $this->respond('app/page/storefront/error.php', compact('message', 'status'));
        }
    }
    protected function redirect(string $path): void { header('Location: ' . self::url($path), true, 303); }
    public static function url(string $path = ''): string
    {
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/.');
        return $base . '/' . ltrim($path, '/');
    }
    public static function escape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    public static function money($minor): string
    {
        $minor = (int)$minor;
        return 'INR ' . intdiv($minor, 100) . '.' . str_pad((string)($minor % 100), 2, '0', STR_PAD_LEFT);
    }
    public static function quantity($milli): string
    {
        return rtrim(rtrim(intdiv((int)$milli, 1000) . '.' . str_pad((string)((int)$milli % 1000), 3, '0', STR_PAD_LEFT), '0'), '.');
    }
    /** Preserve database IDs and minor-unit money in JSON clients. */
    protected static function jsonValues(array $values): array
    {
        foreach ($values as $key => &$value) {
            if (is_array($value)) $value = self::jsonValues($value);
            elseif ($value !== null && is_string($key) && ($key === 'id' || str_ends_with($key, '_id') || str_ends_with($key, '_minor'))) $value = (string)$value;
        }
        return $values;
    }
}
