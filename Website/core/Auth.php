<?php

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/RequestSecurity.php';

/** Commerce identities only. All operations share the bootstrapped PDO. */
final class Auth
{
    private const COOKIE = 'remember_me';
    private const TTL = 2592000;
    private const FAILURE = ['status' => false, 'message' => 'Invalid credentials or access unavailable.'];
    private const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

    public function __construct() { RequestSecurity::session(); }

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

    private static function email(string $email): string
    {
        $email = strtolower(trim($email));
        return strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private static function emailPredicate(): string
    {
        return self::connection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'email = ? COLLATE NOCASE' : 'email = ?';
    }

    private static function enabled(array $user, bool $verified = true): bool
    {
        $locked = $user['locked_until'] ?? null;
        return (int) $user['status'] === 1 && (!$verified || (int) $user['email_verify'] === 1)
            && (!$locked || $locked <= gmdate('Y-m-d H:i:s'));
    }

    public static function identity(): ?array
    {
        RequestSecurity::session();
        $id = $_SESSION['user_id'] ?? null;
        if (!$id && !self::validateRememberToken()) return null;
        $id = $_SESSION['user_id'] ?? null;
        if (!is_int($id) || $id < 1) { self::clearIdentity(); return null; }
        $user = self::query('SELECT id, email, name, status, email_verify, locked_until FROM users_tbl WHERE id = ?', [$id])->fetch(PDO::FETCH_ASSOC);
        if (!$user || !self::enabled($user)) {
            self::revokeRememberTokens($id);
            self::clearIdentity();
            self::cookie('', time() - 3600);
            return null;
        }
        // Current DB state, never a session role/email snapshot, is the authority.
        return $user;
    }

    public static function isAuthenticated(): bool { return self::identity() !== null; }
    public static function isEmailVerified(): bool { return self::isAuthenticated(); }

    /** Persistent atomic counters bound attempts across sessions by email and direct client IP. */
    private static function allowAttempt(string $purpose, string $email): bool
    {
        $now = time();
        $keys = [hash('sha256', $purpose . ':ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'local')),
            hash('sha256', $purpose . ':email:' . $email)];
        $sqlite = self::connection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
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
            self::query($sql, [$key, $now, $now - 900, $now - 900]);
            if ((int) self::query('SELECT attempts FROM auth_login_limits WHERE bucket_key = ?', [$key])->fetchColumn() > 5) {
                return false;
            }
        }
        $allowed = true;
        foreach ($keys as $key) {
            $allowed = (int) self::query('SELECT attempts FROM auth_login_limits WHERE bucket_key = ?', [$key])->fetchColumn() <= 5 && $allowed;
        }
        // Indexed, bounded cleanup; never an unbounded per-login DELETE.
        $old = self::query('SELECT id FROM auth_login_limits WHERE window_start < ? ORDER BY window_start, id LIMIT 100', [$now - 86400])->fetchAll(PDO::FETCH_COLUMN);
        foreach ($old as $id) self::query('DELETE FROM auth_login_limits WHERE id = ? AND window_start < ?', [$id, $now - 86400]);
        return $allowed;
    }

    public static function login(string $email, string $password, bool $remember = false): array
    {
        RequestSecurity::requireMutation();
        $email = self::email($email);
        if (!self::allowAttempt('login', $email)) {
            return ['status' => false, 'message' => 'Too many attempts. Try again in 15 minutes.', 'code' => 'throttled'];
        }
        $user = self::query('SELECT * FROM users_tbl WHERE ' . self::emailPredicate(), [$email])->fetch(PDO::FETCH_ASSOC);
        $valid = strlen($password) <= 1024 && password_verify($password, $user['password'] ?? self::DUMMY_HASH);
        if (!$valid || !$user || !self::enabled($user)) return self::FAILURE;
        // Complete persistence before exposing the authenticated session/cookie.
        self::query('UPDATE users_tbl SET login_time = ?, failed_attempts = 0 WHERE id = ?', [gmdate('Y-m-d H:i:s'), $user['id']]);
        if ($remember) self::setRememberToken((int) $user['id']);
        else self::revokeCookieToken();
        self::createSession((int) $user['id']);
        return ['status' => true, 'message' => 'Login successful'];
    }

    private static function createSession(int $id): void
    {
        if (!session_regenerate_id(true)) throw new RuntimeException('Session is unavailable.');
        self::clearIdentity();
        $_SESSION['user_id'] = $id;
        RequestSecurity::rotateCsrf();
    }

    private static function clearIdentity(): void
    {
        foreach (['user_id', 'user_email', 'user_name', 'user_role', 'email_verified', 'logged_in', 'last_activity'] as $key) {
            unset($_SESSION[$key]);
        }
    }

    private static function cookie(string $value, int $expires): void
    {
        if (!setcookie(self::COOKIE, $value, RequestSecurity::cookieOptions($expires))) {
            throw new RuntimeException('Authentication cookie is unavailable.');
        }
        // Keep repeated identity checks in one request consistent with a rotation.
        if ($value === '') unset($_COOKIE[self::COOKIE]); else $_COOKIE[self::COOKIE] = $value;
    }

    private static function setRememberToken(int $id): void
    {
        self::revokeCookieToken();
        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        $expires = time() + self::TTL;
        self::query('INSERT INTO auth_remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, ?)',
            [$id, $selector, hash('sha256', $validator), $expires]);
        self::cookie($selector . ':' . $validator, $expires);
    }

    private static function cookieParts(): ?array
    {
        $value = $_COOKIE[self::COOKIE] ?? null;
        if (!is_string($value) || !preg_match('/\A([a-f0-9]{32}):([a-f0-9]{64})\z/', $value, $parts)) return null;
        return [$parts[1], $parts[2]];
    }

    private static function revokeCookieToken(): void
    {
        $parts = self::cookieParts();
        if ($parts) self::query('UPDATE auth_remember_tokens SET revoked_at = ? WHERE selector = ? AND validator_hash = ? AND revoked_at IS NULL',
            [time(), $parts[0], hash('sha256', $parts[1])]);
        self::cookie('', time() - 3600);
    }

    public static function revokeRememberTokens(int $id): void
    {
        self::query('UPDATE auth_remember_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [time(), $id]);
    }

    private static function validateRememberToken(): bool
    {
        $parts = self::cookieParts();
        if (!$parts) {
            if (isset($_COOKIE[self::COOKIE])) self::cookie('', time() - 3600);
            return false;
        }
        [$selector, $validator] = $parts;
        $token = self::query('SELECT * FROM auth_remember_tokens WHERE selector = ?', [$selector])->fetch(PDO::FETCH_ASSOC);
        if (!$token || $token['revoked_at'] !== null || (int) $token['expires_at'] <= time()
            || !hash_equals($token['validator_hash'], hash('sha256', $validator))) {
            self::cookie('', time() - 3600);
            return false;
        }
        $user = self::query('SELECT * FROM users_tbl WHERE id = ?', [$token['user_id']])->fetch(PDO::FETCH_ASSOC);
        if (!$user || !self::enabled($user)) {
            self::revokeRememberTokens((int) $token['user_id']);
            self::cookie('', time() - 3600);
            return false;
        }
        $next = bin2hex(random_bytes(32));
        // Compare-and-swap: concurrent/replayed credentials cannot both restore sessions.
        $changed = self::query('UPDATE auth_remember_tokens SET validator_hash = ? WHERE id = ?
            AND validator_hash = ? AND expires_at > ? AND revoked_at IS NULL',
            [hash('sha256', $next), $token['id'], $token['validator_hash'], time()])->rowCount();
        if ($changed !== 1) { self::cookie('', time() - 3600); return false; }
        self::createSession((int) $user['id']);
        self::cookie($selector . ':' . $next, (int) $token['expires_at']);
        return true;
    }

    /** No redirect by default: HTML controllers choose their fixed local redirect. */
    public static function logout(?string $redirectPath = null): void
    {
        RequestSecurity::requireMutation();
        $id = $_SESSION['user_id'] ?? null;
        if (is_int($id) && $id > 0) self::revokeRememberTokens($id);
        self::revokeCookieToken();
        self::clearIdentity();
        session_regenerate_id(true);
        RequestSecurity::rotateCsrf();
        if ($redirectPath !== null) {
            if (!preg_match('~\A[a-z0-9/-]*\z~', $redirectPath)) throw new InvalidArgumentException('Invalid redirect path.');
            header('Location: ' . base_url($redirectPath), true, 303);
            exit;
        }
    }

    private static function registrationAvailable(): bool
    {
        if(!filter_var(env('AUTH_REGISTRATION_ENABLED',false),FILTER_VALIDATE_BOOLEAN))return false;
        if(env('AUTH_VERIFICATION_TRANSPORT','disabled')==='queue') {
            return env('MAIL_TRANSPORT','disabled')==='smtp'
                ||(env('APP_ENV','production')!=='production'&&env('MAIL_TRANSPORT','disabled')==='test');
        }
        return env('APP_ENV','production')!=='production'&&env('AUTH_VERIFICATION_TRANSPORT','disabled')==='test';
    }

    public static function register(string $firstname, string $lastname, string $name, string $email, string $password, ?string $designation = null): array
    {
        RequestSecurity::requireMutation();
        if (!self::registrationAvailable()) return ['status' => false, 'message' => 'Registration is unavailable.', 'code' => 'unavailable'];
        $email = self::email($email);
        $firstname = trim($firstname); $lastname = trim($lastname); $name = trim($name);
        if (!$email || !self::validName($firstname, 190) || !self::validName($lastname, 190) || !self::validName($name, 255)
            || strlen($password) < 12 || strlen($password) > 72) {
            return ['status' => false, 'message' => 'Use valid names, email and a password of 12 to 72 bytes.', 'code' => 'invalid_input'];
        }
        if (!self::allowAttempt('verification', $email)) return ['status' => false, 'message' => 'Too many attempts.', 'code' => 'throttled'];
        $pdo = self::connection();
        if ($pdo->inTransaction()) throw new RuntimeException('Registration requires its own transaction.');
        $proof = null;
        try {
            // Legacy local test delivery stages before locks; queue mode stages no files.
            $proof = self::verification($email);
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->beginTransaction();
            // Client-supplied designation/role is never authority or persisted by signup.
            self::query("INSERT INTO users_tbl (firstname, lastname, name, email, password, role, email_verify, status)
                VALUES (?, ?, ?, ?, ?, 'user', 0, 1)", [$firstname, $lastname, $name, $email, $hash]);
            self::persistVerification((int) $pdo->lastInsertId(), $proof);
            $pdo->commit();
            return ['status' => true, 'message' => 'Registration successful. Check your verification email.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($proof && $proof['path']) @unlink($proof['path']);
            return ['status' => false, 'message' => 'Registration could not be completed.'];
        }
    }

    private static function validName(string $value, int $limit): bool
    {
        return $value !== '' && strlen($value) <= $limit && preg_match('//u', $value)
            && !preg_match('/[\x00-\x1f\x7f]/', $value);
    }

    /** Hashed, single-use, server-expiring proof; raw token exists only in private test mail. */
    private static function verification(string $email): array
    {
        $token = bin2hex(random_bytes(32));
        $url = rtrim((string) env('APP_URL', ''), '/');
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new RuntimeException('Verification URL is unavailable.');
        }
        if(env('AUTH_VERIFICATION_TRANSPORT','disabled')==='queue') {
            // An unguessable placeholder is replaced by the worker's in-memory proof.
            // Raw verification tokens never enter queue payloads or durable SQL.
            return ['path'=>null,'token_hash'=>hash('sha256',$token),'expires_at'=>CommerceDatabase::clock(self::connection())+3600];
        }
        $path = PrivateStorage::directory('test-mail') . '/' . bin2hex(random_bytes(16)) . '.json';
        $oldMask = umask(0077);
        try {
            $message = json_encode(['to' => $email, 'verification_url' => $url . '/auth/verify_email?token=' . $token], JSON_THROW_ON_ERROR);
            if (file_put_contents($path, $message, LOCK_EX) !== strlen($message)) {
                @unlink($path);
                throw new RuntimeException('Test transport is unavailable.');
            }
        } finally { umask($oldMask); }
        return ['path' => $path, 'token_hash' => hash('sha256', $token), 'expires_at' => time() + 3600];
    }

    private static function persistVerification(int $id, array $proof): void
    {
        self::query('DELETE FROM auth_verification_tokens WHERE user_id = ?', [$id]);
        self::query('INSERT INTO auth_verification_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)',
            [$id, $proof['token_hash'], $proof['expires_at']]);
        if(env('AUTH_VERIFICATION_TRANSPORT','disabled')==='queue') {
            (new QueueService(self::connection()))->verification((int)self::connection()->lastInsertId());
        }
    }

    /** Verification links are an explicit GET exception: possession of a single-use proof authorizes it. */
    public static function verifyEmail($userId, $token): array
    {
        if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            return ['status' => false, 'message' => 'Invalid or expired verification link.'];
        }
        // New links contain just the proof. Legacy callers may still supply an ID,
        // which must match the token owner. The token never conveys staff grants.
        if ($userId === null) {
            $userId = self::query('SELECT user_id FROM auth_verification_tokens WHERE token_hash = ?', [hash('sha256', $token)])->fetchColumn();
        }
        if ((!is_int($userId) && !is_string($userId)) || !preg_match('/\A[1-9][0-9]{0,9}\z/', (string) $userId)) {
            return ['status' => false, 'message' => 'Invalid or expired verification link.'];
        }
        $pdo = self::connection();
        if ($pdo->inTransaction()) throw new RuntimeException('Verification requires its own transaction.');
        try {
            $pdo->beginTransaction();
            // Lock the user before token rows; a resend uses the same lock order.
            $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $user = self::query('SELECT * FROM users_tbl WHERE id = ?' . $suffix, [$userId])->fetch(PDO::FETCH_ASSOC);
            if (!$user || !self::enabled($user, false) || (int) $user['email_verify'] === 1) {
                $pdo->rollBack();
                return ['status' => false, 'message' => 'Invalid or expired verification link.'];
            }
            $removed = self::query('DELETE FROM auth_verification_tokens WHERE user_id = ? AND token_hash = ? AND expires_at > ?',
                [$userId, hash('sha256', $token), time()])->rowCount();
            if ($removed !== 1) { $pdo->rollBack(); return ['status' => false, 'message' => 'Invalid or expired verification link.']; }
            self::query('UPDATE users_tbl SET email_verify = 1 WHERE id = ?', [$userId]);
            $pdo->commit();
            return ['status' => true, 'message' => 'Email successfully verified'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['status' => false, 'message' => 'Verification is unavailable.'];
        }
    }

    public static function resendVerificationEmail(string $email): array
    {
        RequestSecurity::requireMutation();
        if (!self::registrationAvailable()) return ['status' => false, 'message' => 'Verification delivery is unavailable.', 'code' => 'unavailable'];
        $email = self::email($email);
        if (!self::allowAttempt('verification', $email)) return ['status' => false, 'message' => 'Too many attempts.', 'code' => 'throttled'];
        $pdo = self::connection();
        if ($pdo->inTransaction()) throw new RuntimeException('Verification requires its own transaction.');
        $proof = null;
        try {
            // Read/stage before locking, then recheck eligibility in the transaction.
            $candidate = self::query('SELECT * FROM users_tbl WHERE ' . self::emailPredicate(), [$email])->fetch(PDO::FETCH_ASSOC);
            if ($candidate && self::enabled($candidate, false) && !(int) $candidate['email_verify']) {
                $proof = self::verification($email);
            }
            $pdo->beginTransaction();
            $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $user = self::query('SELECT * FROM users_tbl WHERE ' . self::emailPredicate() . $suffix, [$email])->fetch(PDO::FETCH_ASSOC);
            $eligible = $user && self::enabled($user, false) && !(int) $user['email_verify'];
            if ($proof && $eligible) self::persistVerification((int) $user['id'], $proof);
            $pdo->commit();
            if ($proof && $proof['path'] && !$eligible) @unlink($proof['path']);
            return ['status' => true, 'message' => 'If the account is eligible, a verification email was requested.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($proof && $proof['path']) @unlink($proof['path']);
            return ['status' => false, 'message' => 'Verification delivery is unavailable.'];
        }
    }
}
