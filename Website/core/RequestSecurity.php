<?php

require_once __DIR__ . '/PrivateStorage.php';

final class RequestRejected extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}

/** Shared cookie policy; commerce and Developer keep independent identities and CSRF keys. */
final class RequestSecurity
{
    public static function secureCookies(): bool
    {
        if (filter_var(env('SESSION_SECURE_COOKIE', false), FILTER_VALIDATE_BOOLEAN)
            || env('APP_ENV', 'production') === 'production') return true;
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
        $proxies = array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))));
        return in_array($_SERVER['REMOTE_ADDR'] ?? '', $proxies, true)
            && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public static function cookieOptions(int $expires = 0): array
    {
        // Root scope also supports subdirectory installs; cookies remain host-only.
        return ['expires' => $expires, 'path' => '/', 'secure' => self::secureCookies(),
            'httponly' => true, 'samesite' => 'Lax'];
    }

    public static function session(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (headers_sent()) throw new RuntimeException('Session must start before output.');
            session_save_path(PrivateStorage::directory('sessions'));
            if (!session_start(['use_strict_mode' => 1, 'use_only_cookies' => 1, 'use_trans_sid' => 0,
                'cookie_path' => '/', 'cookie_domain' => '', 'cookie_httponly' => 1,
                'cookie_samesite' => 'Lax', 'cookie_secure' => self::secureCookies()])) {
                throw new RuntimeException('Session is unavailable.');
            }
        }
        if (!is_string($_SESSION['commerce_csrf'] ?? null)) self::rotateCsrf();
    }

    public static function rotateCsrf(): void
    {
        $_SESSION['commerce_csrf'] = bin2hex(random_bytes(32));
    }

    public static function csrfToken(): string
    {
        self::session();
        return $_SESSION['commerce_csrf'];
    }

    public static function validCsrf(): bool
    {
        self::session();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? null;
        return is_string($token) && hash_equals($_SESSION['commerce_csrf'], $token);
    }

    public static function requireMutation(): void
    {
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new RequestRejected(405, 'method_not_allowed', 'A mutation method is required.');
        }
        if (!self::validCsrf()) throw new RequestRejected(403, 'invalid_csrf', 'Invalid session token.');
    }

    public static function jsonBody(int $limit = 16384): array
    {
        if ($limit < 1 || $limit > 65536) throw new InvalidArgumentException('Invalid request limit.');
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
            throw new RequestRejected(415, 'unsupported_media_type', 'JSON content is required.');
        }
        $stream = fopen('php://input', 'rb');
        $body = stream_get_contents($stream, $limit + 1);
        fclose($stream);
        return self::decodeJson($body, $limit);
    }

    public static function decodeJson(string $body, int $limit = 16384): array
    {
        if (strlen($body) > $limit) throw new RequestRejected(413, 'body_too_large', 'Request is too large.');
        try {
            $object = json_decode($body, false, 16, JSON_THROW_ON_ERROR);
            if (!$object instanceof stdClass) throw new JsonException();
            return (array) $object;
        } catch (JsonException $e) {
            throw new RequestRejected(400, 'invalid_json', 'A valid JSON object is required.');
        }
    }
}
