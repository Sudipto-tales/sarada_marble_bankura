<?php

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/PrivateStorage.php';

/**
 * Customer second factor: 6-digit email OTP, trusted devices and device PINs.
 * Shares the bootstrapped PDO like Auth. Codes and PINs are only ever stored hashed.
 */
final class CustomerOtp
{
    private const PURPOSES = ['register', 'login', 'reset', 'delete', 'twostep', 'profile'];
    private const DEVICE_COOKIE = 'trusted_device';
    private const REQUEST_WINDOW = 3600; // max 5 requests/guesses per hour per email+purpose
    private const GUESS_LIMIT = 5;        // wrong-code guesses before a code is locked

    private static function connection(): PDO
    {
        global $pdo;
        if (!$pdo instanceof PDO) throw new RuntimeException('Authentication storage is unavailable.');
        return $pdo;
    }
    private static function query(string $sql, array $values = []): PDOStatement
    {
        $query = self::connection()->prepare($sql);
        $query->execute($values);
        return $query;
    }

    /** Email is the primary channel; SMS/WhatsApp stay optional and are a no-op until configured. */
    public static function enabled(): bool
    {
        if (!filter_var(env('AUTH_OTP_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) return false;
        if (env('MAIL_TRANSPORT', 'disabled') === 'smtp') return true;
        // Offline development writes codes to private test-mail files instead of sending.
        return env('APP_ENV', 'production') !== 'production'
            && in_array(env('AUTH_OTP_CHANNEL', 'test'), ['test', 'email'], true);
    }

    /** Whether a verified login must pass an OTP step (trusted devices still skip it). */
    public static function loginRequired(): bool
    {
        return self::enabled() && filter_var(env('AUTH_LOGIN_OTP', false), FILTER_VALIDATE_BOOLEAN);
    }

    private static function ttl(): int
    {
        return max(5, min(10, (int) env('AUTH_OTP_TTL_MINUTES', 10))) * 60;
    }

    /**
     * Persistent atomic counter bounding requests/guesses to 5 per hour by email+purpose and by IP.
     * Mirrors Auth::allowAttempt but with the OTP window; it shares the auth_login_limits table.
     */
    private static function allowRequest(string $purpose, string $email): bool
    {
        $now = time();
        $keys = [hash('sha256', 'otp:' . $purpose . ':ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'local')),
            hash('sha256', 'otp:' . $purpose . ':email:' . $email)];
        $sqlite = self::connection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $allowed = true;
        foreach ($keys as $key) {
            $sql = $sqlite
                ? 'INSERT INTO auth_login_limits (bucket_key, window_start, attempts) VALUES (?, ?, 1)
                    ON CONFLICT(bucket_key) DO UPDATE SET
                    attempts = CASE WHEN window_start <= ? THEN 1 WHEN attempts < 6 THEN attempts + 1 ELSE 6 END,
                    window_start = CASE WHEN window_start <= ? THEN excluded.window_start ELSE window_start END'
                : 'INSERT INTO auth_login_limits (bucket_key, window_start, attempts) VALUES (?, ?, 1)
                    ON DUPLICATE KEY UPDATE
                    attempts = CASE WHEN window_start <= ? THEN 1 WHEN attempts < 6 THEN attempts + 1 ELSE 6 END,
                    window_start = CASE WHEN window_start <= ? THEN VALUES(window_start) ELSE window_start END';
            self::query($sql, [$key, $now, $now - self::REQUEST_WINDOW, $now - self::REQUEST_WINDOW]);
            if ((int) self::query('SELECT attempts FROM auth_login_limits WHERE bucket_key = ?', [$key])->fetchColumn() > self::GUESS_LIMIT) {
                $allowed = false;
            }
        }
        return $allowed;
    }

    /**
     * Generate, hash-store (replacing any prior code) and deliver a 6-digit code. False when rate-limited.
     * Channel defaults to email; whatsapp/sms require the admin-stored credentials and the user's phone.
     */
    public static function request(int $userId, string $purpose, string $email, string $channel = 'email', ?string $phone = null): bool
    {
        if (!in_array($purpose, self::PURPOSES, true)) throw new InvalidArgumentException('Invalid OTP purpose.');
        if ($userId < 1) throw new InvalidArgumentException('Invalid OTP owner.');
        if (!self::enabled()) throw new RuntimeException('OTP delivery is unavailable.');
        if (!self::allowRequest($purpose, $email)) return false;
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = time() + self::ttl();
        self::query('DELETE FROM auth_otp_codes WHERE user_id = ? AND purpose = ?', [$userId, $purpose]);
        self::query('INSERT INTO auth_otp_codes (user_id, purpose, code_hash, expires_at, attempts, created_at)
            VALUES (?, ?, ?, ?, 0, ?)', [$userId, $purpose, hash('sha256', $purpose . ':' . $code), $expires, time()]);
        self::deliver($email, $purpose, $code, $expires, $channel, $phone);
        self::cleanup();
        return true;
    }

    private static function deliver(string $email, string $purpose, string $code, int $expires, string $channel = 'email', ?string $phone = null): void
    {
        $action = ['register' => 'verify your account', 'login' => 'sign in', 'reset' => 'reset your password',
            'delete' => 'confirm account deletion', 'twostep' => 'turn on two-step verification',
            'profile' => 'confirm your new contact details'][$purpose];
        if ($channel === 'whatsapp' || $channel === 'sms') {
            $creds = OtpChannels::credentials(self::connection(), $channel);
            if ($creds === null || !is_string($phone) || $phone === '') throw new RuntimeException('OTP delivery is unavailable.');
            $to = self::msisdn($phone);
            $channel === 'whatsapp' ? self::sendWhatsapp($to, $code, $creds) : self::sendSms($to, $code, $creds);
            return;
        }
        $subject = 'Your Maa Sarada verification code';
        $body = 'Your Maa Sarada code to ' . $action . ' is ' . $code
            . "\nIt expires at " . gmdate('Y-m-d H:i:s', $expires) . ' UTC. Never share this code with anyone.';
        if (env('MAIL_TRANSPORT', 'disabled') === 'smtp' || env('AUTH_OTP_CHANNEL', 'test') === 'email') {
            if (!Mailer::send($email, $subject, $body)) throw new RuntimeException('OTP delivery is unavailable.');
            return;
        }
        // Offline test transport: the code stays in private storage, never sent over the network.
        $path = PrivateStorage::directory('test-mail') . '/' . bin2hex(random_bytes(16)) . '.json';
        $old = umask(0077);
        try {
            $message = json_encode(['to' => $email, 'otp' => $code, 'purpose' => $purpose, 'subject' => $subject], JSON_THROW_ON_ERROR);
            if (file_put_contents($path, $message, LOCK_EX) !== strlen($message)) {
                @unlink($path);
                throw new RuntimeException('OTP transport is unavailable.');
            }
        } finally { umask($old); }
    }

    /** Digits-only MSISDN for provider APIs (country code, no plus/spaces). */
    private static function msisdn(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '' || strlen($digits) > 15) throw new RuntimeException('OTP delivery is unavailable.');
        return $digits;
    }

    /** Meta WhatsApp Cloud API authentication template: the code fills the body and copy-code button. */
    private static function sendWhatsapp(string $phone, string $code, array $creds): void
    {
        $payload = ['messaging_product' => 'whatsapp', 'to' => $phone, 'type' => 'template',
            'template' => ['name' => $creds['template'], 'language' => ['code' => 'en_US'],
                'components' => [
                    ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                    ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]],
                ]]];
        self::httpPost('https://graph.facebook.com/v21.0/' . rawurlencode($creds['phone_id']) . '/messages',
            ['Authorization: Bearer ' . $creds['token'], 'Content-Type: application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR), 'whatsapp');
    }

    /** MSG91 flow API: the code is sent as both the reserved `otp` and `var1` so either template maps it. */
    private static function sendSms(string $phone, string $code, array $creds): void
    {
        $payload = ['template_id' => $creds['template'], 'short_url' => '0',
            'recipients' => [['mobiles' => $phone, 'otp' => $code, 'var1' => $code]]];
        if ($creds['sender'] !== '') $payload['sender'] = $creds['sender'];
        self::httpPost('https://control.msg91.com/api/v5/flow/',
            ['authkey: ' . $creds['authkey'], 'Content-Type: application/json', 'Accept: application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR), 'sms');
    }

    /** Bounded HTTPS POST; logs only classifiers because provider responses may carry secrets/PII. */
    private static function httpPost(string $url, array $headers, string $body, string $label): void
    {
        if (!function_exists('curl_init')) throw new RuntimeException('OTP transport is unavailable.');
        $curl = curl_init($url);
        try {
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => $headers]);
            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if (!is_string($response) || $status < 200 || $status >= 300) {
                error_log('OTP ' . $label . ' transport unavailable: HTTP ' . $status . ', curl ' . curl_errno($curl));
                throw new RuntimeException('OTP delivery is unavailable.');
            }
        } finally { curl_close($curl); }
    }

    /** Single-use verification with a bounded guess count. Consumes the code on success. */
    public static function verify(int $userId, string $purpose, string $code): bool
    {
        if (!in_array($purpose, self::PURPOSES, true) || !preg_match('/\A[0-9]{6}\z/', $code)) return false;
        $pdo = self::connection();
        if ($pdo->inTransaction()) throw new RuntimeException('OTP verification requires its own transaction.');
        try {
            $pdo->beginTransaction();
            $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $row = self::query('SELECT * FROM auth_otp_codes WHERE user_id = ? AND purpose = ?' . $suffix, [$userId, $purpose])->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int) $row['expires_at'] <= time() || (int) $row['attempts'] >= self::GUESS_LIMIT) {
                if ($row) self::query('DELETE FROM auth_otp_codes WHERE id = ?', [$row['id']]);
                $pdo->commit();
                return false;
            }
            if (!hash_equals($row['code_hash'], hash('sha256', $purpose . ':' . $code))) {
                self::query('UPDATE auth_otp_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
                $pdo->commit();
                return false;
            }
            self::query('DELETE FROM auth_otp_codes WHERE id = ?', [$row['id']]);
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function cleanup(): void
    {
        $old = self::query('SELECT id FROM auth_otp_codes WHERE expires_at < ? ORDER BY expires_at, id LIMIT 100', [time() - 86400])->fetchAll(PDO::FETCH_COLUMN);
        foreach ($old as $id) self::query('DELETE FROM auth_otp_codes WHERE id = ?', [$id]);
    }

    // --- Trusted devices and PINs -------------------------------------------------

    private static function deviceTtl(): int
    {
        return max(30, min(90, (int) env('AUTH_TRUSTED_DEVICE_DAYS', 60))) * 86400;
    }
    /** Coarse device binding; a changed browser/language simply re-triggers the OTP step. */
    private static function fingerprint(): string
    {
        return hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    }
    private static function deviceCookie(string $value, int $expires): void
    {
        setcookie(self::DEVICE_COOKIE, $value, RequestSecurity::cookieOptions($expires));
        if ($value === '') unset($_COOKIE[self::DEVICE_COOKIE]); else $_COOKIE[self::DEVICE_COOKIE] = $value;
    }

    public static function trustCurrentDevice(int $userId): void
    {
        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        $expires = time() + self::deviceTtl();
        self::query('INSERT INTO auth_trusted_devices (user_id, selector, validator_hash, fingerprint_hash, pin_hash, expires_at, revoked_at, created_at, last_used_at)
            VALUES (?, ?, ?, ?, NULL, ?, NULL, ?, ?)', [$userId, $selector, hash('sha256', $validator), self::fingerprint(), $expires, time(), time()]);
        self::deviceCookie($selector . ':' . $validator, $expires);
    }

    /** Resolve the current browser's valid trusted-device row, or null. */
    private static function currentDevice(): ?array
    {
        $value = $_COOKIE[self::DEVICE_COOKIE] ?? null;
        if (!is_string($value) || !preg_match('/\A([a-f0-9]{32}):([a-f0-9]{64})\z/', $value, $parts)) return null;
        $row = self::query('SELECT * FROM auth_trusted_devices WHERE selector = ?', [$parts[1]])->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['revoked_at'] !== null || (int) $row['expires_at'] <= time()
            || !hash_equals($row['validator_hash'], hash('sha256', $parts[2]))
            || !hash_equals($row['fingerprint_hash'], self::fingerprint())) return null;
        return $row;
    }

    public static function trusted(int $userId): bool
    {
        $device = self::currentDevice();
        return $device !== null && (int) $device['user_id'] === $userId;
    }

    public static function touch(int $deviceId): void
    {
        self::query('UPDATE auth_trusted_devices SET last_used_at = ? WHERE id = ?', [time(), $deviceId]);
    }

    public static function revokeAll(int $userId): int
    {
        return self::query('UPDATE auth_trusted_devices SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [time(), $userId])->rowCount();
    }

    public static function clearDeviceCookie(): void
    {
        self::deviceCookie('', time() - 3600);
    }

    public static function deviceListing(int $userId): array
    {
        return self::query('SELECT id, created_at, last_used_at, expires_at, pin_hash FROM auth_trusted_devices
            WHERE user_id = ? AND revoked_at IS NULL AND expires_at > ? ORDER BY last_used_at DESC', [$userId, time()])->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Set a 4-6 digit PIN on the current trusted device only. */
    public static function setPin(int $userId, string $pin): bool
    {
        if (!preg_match('/\A[0-9]{4,6}\z/', $pin)) return false;
        $device = self::currentDevice();
        if (!$device || (int) $device['user_id'] !== $userId) return false;
        self::query('UPDATE auth_trusted_devices SET pin_hash = ? WHERE id = ?', [password_hash($pin, PASSWORD_DEFAULT), $device['id']]);
        return true;
    }

    public static function pinAvailable(int $userId): bool
    {
        $device = self::currentDevice();
        return $device !== null && (int) $device['user_id'] === $userId && $device['pin_hash'] !== null;
    }

    public static function currentDevicePinAvailable(): bool
    {
        $device = self::currentDevice();
        return $device !== null && $device['pin_hash'] !== null;
    }

    /** Verify a PIN against the current device. Returns the owner id on success, else null. */
    public static function verifyPin(string $pin): ?int
    {
        if (!preg_match('/\A[0-9]{4,6}\z/', $pin)) return null;
        $device = self::currentDevice();
        if (!$device || $device['pin_hash'] === null) return null;
        $email = (string) self::query('SELECT email FROM users_tbl WHERE id = ?', [$device['user_id']])->fetchColumn();
        // shortcut: PIN guesses reuse the per-hour request limiter (its own 'pin' bucket); a stolen
        // device cookie plus brute force is bounded to 5/hour. Add a per-device lock if PIN use grows.
        if (!self::allowRequest('pin', $email)) return null;
        if (!password_verify($pin, $device['pin_hash'])) return null;
        self::touch((int) $device['id']);
        return (int) $device['user_id'];
    }
}
