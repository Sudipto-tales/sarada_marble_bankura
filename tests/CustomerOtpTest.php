<?php
// php tests/CustomerOtpTest.php [--mysql]. Only random disposable databases/storage.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/config/env.php';
require_once dirname(__DIR__) . '/config/migrate.php';
require_once dirname(__DIR__) . '/core/RequestSecurity.php';
require_once dirname(__DIR__) . '/core/PrivateStorage.php';
require_once dirname(__DIR__) . '/core/Mailer.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/core/CustomerOtp.php';

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
    $checks++;
}
function removeTree(string $directory): void
{
    foreach (scandir($directory) as $name) {
        if ($name === '.' || $name === '..') continue;
        $file = $directory . '/' . $name;
        if (is_dir($file) && !is_link($file)) removeTree($file); else unlink($file);
    }
    rmdir($directory);
}
function mutation(): void
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['csrf' => RequestSecurity::csrfToken()];
}
/** Read the most recent OTP code delivered to an address for a purpose. */
function latestOtp(string $email, string $purpose): string
{
    $code = null;
    foreach (glob(PrivateStorage::directory('test-mail') . '/*.json') as $file) {
        $mail = json_decode(file_get_contents($file), true);
        if (($mail['to'] ?? null) === $email && ($mail['purpose'] ?? null) === $purpose && isset($mail['otp'])) $code = $mail['otp'];
    }
    if ($code === null) throw new RuntimeException('Missing delivered OTP for ' . $purpose);
    return $code;
}
function resetLimits(): void { global $pdo; $pdo->exec('DELETE FROM auth_login_limits'); }

$mysql = in_array('--mysql', $argv, true);
$temp = sys_get_temp_dir() . '/vayu-otp-test-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
$_ENV['APP_ENV'] = 'development';
$_ENV['APP_URL'] = 'http://localhost/shop';
$_ENV['PRIVATE_STORAGE_PATH'] = $temp . '/private';
$_ENV['MAIL_TRANSPORT'] = 'disabled';
$_ENV['SESSION_SECURE_COOKIE'] = 'false';
$_ENV['TRUSTED_PROXIES'] = '';
$_ENV['AUTH_REGISTRATION_ENABLED'] = 'true';
$_ENV['AUTH_VERIFICATION_TRANSPORT'] = 'otp';
$_ENV['AUTH_OTP_ENABLED'] = 'true';
$_ENV['AUTH_OTP_CHANNEL'] = 'test';
$_ENV['AUTH_OTP_TTL_MINUTES'] = '10';
$_ENV['AUTH_LOGIN_OTP'] = 'true';
$_ENV['AUTH_TRUSTED_DEVICE_DAYS'] = '60';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'otp-test-agent';
$_SERVER['REQUEST_METHOD'] = 'POST';

$schema = 'vayu_otp_test_' . bin2hex(random_bytes(8));
$admin = null;
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
try {
    if ($mysql) {
        $host = getenv('TEST_MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('TEST_MYSQL_PORT') ?: '3306';
        $user = getenv('TEST_MYSQL_USER') ?: 'root';
        $password = getenv('TEST_MYSQL_PASSWORD') ?: '';
        if (preg_match('/[;\x00]/', $host) || !ctype_digit($port)) throw new RuntimeException('Invalid test target.');
        $dsn = "mysql:host=$host;port=$port;charset=utf8mb4";
        $options[PDO::ATTR_EMULATE_PREPARES] = false;
        $admin = new PDO($dsn, $user, $password, $options);
        $admin->exec('CREATE DATABASE ' . MigrationSchema::identifier($schema) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo = new PDO($dsn . ';dbname=' . $schema, $user, $password, $options);
        $version = 'MySQL ' . $pdo->query('SELECT VERSION()')->fetchColumn();
    } else {
        $pdo = new PDO('sqlite:' . $temp . '/otp.sqlite', null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $version = 'SQLite ' . $pdo->query('SELECT sqlite_version()')->fetchColumn();
    }

    $count = (new MigrationRunner($pdo, dirname(__DIR__) . '/database/migrations', static function ($line) {}))->run();
    check($count === count(glob(dirname(__DIR__) . '/database/migrations/*.php')), 'Full migration set applies, including OTP tables.');

    // A verified customer and a pending (unverified) customer.
    $hash = password_hash('Disposable-Customer-Password', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users_tbl (firstname, lastname, name, email, password, role, email_verify, status) VALUES ('Vera','Verified','Vera Verified',?,?,'user',1,1)")
        ->execute(['vera@example.test', $hash]);
    $vera = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO users_tbl (firstname, lastname, name, email, password, role, email_verify, status) VALUES ('Pat','Pending','Pat Pending',?,?,'user',0,1)")
        ->execute(['pat@example.test', $hash]);
    $pat = (int) $pdo->lastInsertId();

    RequestSecurity::session();

    // --- OTP request + delivery + single-use verify -------------------------------
    check(CustomerOtp::enabled() && Auth::registrationAvailable(), 'OTP mode enables registration.');
    check(CustomerOtp::request($vera, 'login', 'vera@example.test'), 'Login OTP is issued.');
    $code = latestOtp('vera@example.test', 'login');
    check(preg_match('/\A[0-9]{6}\z/', $code) === 1, 'Delivered code is six digits.');
    check((int) $pdo->query('SELECT COUNT(*) FROM auth_otp_codes')->fetchColumn() === 1, 'Exactly one code is stored.');
    check($pdo->query('SELECT code_hash FROM auth_otp_codes')->fetchColumn() !== $code, 'Code is stored hashed, not in plaintext.');
    check(CustomerOtp::verify($vera, 'login', $code) === true, 'Correct code verifies.');
    check(CustomerOtp::verify($vera, 'login', $code) === false, 'A verified code is single-use.');

    // --- Wrong-code guesses are bounded -------------------------------------------
    resetLimits();
    CustomerOtp::request($vera, 'login', 'vera@example.test');
    $code = latestOtp('vera@example.test', 'login');
    $wrong = $code === '000000' ? '111111' : '000000';
    for ($i = 0; $i < 5; $i++) check(CustomerOtp::verify($vera, 'login', $wrong) === false, 'Wrong code is rejected.');
    check(CustomerOtp::verify($vera, 'login', $code) === false, 'The correct code is locked after five wrong guesses.');
    check((int) $pdo->query('SELECT COUNT(*) FROM auth_otp_codes')->fetchColumn() === 0, 'A locked code is cleared.');

    // --- Expiry -------------------------------------------------------------------
    resetLimits();
    CustomerOtp::request($vera, 'login', 'vera@example.test');
    $code = latestOtp('vera@example.test', 'login');
    $pdo->prepare('UPDATE auth_otp_codes SET expires_at = ? WHERE user_id = ?')->execute([time() - 1, $vera]);
    check(CustomerOtp::verify($vera, 'login', $code) === false, 'An expired code is rejected.');

    // --- Request rate limit (max 5 per hour) --------------------------------------
    resetLimits();
    $issued = 0;
    for ($i = 0; $i < 6; $i++) if (CustomerOtp::request($vera, 'login', 'vera@example.test')) $issued++;
    check($issued === 5, 'At most five codes are issued per hour.');
    resetLimits();

    // --- Trusted devices ----------------------------------------------------------
    check(CustomerOtp::trusted($vera) === false, 'No device is trusted initially.');
    CustomerOtp::trustCurrentDevice($vera);
    check(CustomerOtp::trusted($vera) === true, 'A trusted device is recognised.');
    check(CustomerOtp::trusted($pat) === false, 'Trust is scoped to its owner.');
    $_SERVER['HTTP_USER_AGENT'] = 'different-agent';
    check(CustomerOtp::trusted($vera) === false, 'A changed fingerprint is not trusted.');
    $_SERVER['HTTP_USER_AGENT'] = 'otp-test-agent';
    check(CustomerOtp::trusted($vera) === true, 'The original device stays trusted.');

    // --- PIN set and verify -------------------------------------------------------
    resetLimits();
    check(CustomerOtp::setPin($vera, '12') === false, 'Short PINs are rejected.');
    check(CustomerOtp::setPin($vera, '4821') === true, 'A 4-6 digit PIN is set on a trusted device.');
    check($pdo->query('SELECT pin_hash FROM auth_trusted_devices')->fetchColumn() !== '4821', 'PIN is stored hashed.');
    check(CustomerOtp::verifyPin('0000') === null, 'A wrong PIN fails.');
    check(CustomerOtp::verifyPin('4821') === $vera, 'The correct PIN returns the owner.');

    // --- Revoke all devices -------------------------------------------------------
    check(CustomerOtp::revokeAll($vera) === 1, 'Revocation reports the affected device.');
    check(CustomerOtp::trusted($vera) === false, 'A revoked device is no longer trusted.');
    check(CustomerOtp::verifyPin('4821') === null, 'A revoked device cannot use its PIN.');

    // --- Registration creates a pending account with no link token ----------------
    resetLimits();
    mutation();
    $result = Auth::register('New', 'Buyer', 'New Buyer', 'new@example.test', 'Disposable-Customer-Password');
    check($result['status'] === true, 'OTP-mode registration succeeds.');
    $newId = (int) $pdo->query("SELECT id FROM users_tbl WHERE email = 'new@example.test'")->fetchColumn();
    check((int) $pdo->query('SELECT email_verify FROM users_tbl WHERE id = ' . $newId)->fetchColumn() === 0, 'A new account is pending until verified.');
    check((int) $pdo->query('SELECT COUNT(*) FROM auth_verification_tokens WHERE user_id = ' . $newId)->fetchColumn() === 0, 'OTP mode stages no link token.');
    mutation();
    check(Auth::verifyPassword('new@example.test', 'Disposable-Customer-Password')['user'] === null, 'A pending account cannot sign in.');
    CustomerOtp::request($newId, 'register', 'new@example.test');
    check(CustomerOtp::verify($newId, 'register', latestOtp('new@example.test', 'register')) === true, 'Registration OTP verifies.');
    Auth::markVerified($newId);
    mutation();
    check(Auth::verifyPassword('new@example.test', 'Disposable-Customer-Password')['user'] !== null, 'A verified account can sign in.');

    // --- Forgot-password OTP reset ------------------------------------------------
    resetLimits();
    CustomerOtp::request($vera, 'reset', 'vera@example.test');
    $pdo->prepare('INSERT INTO auth_remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, ?)')
        ->execute([$vera, str_pad('a', 32, 'a'), hash('sha256', 'x'), time() + 1000]);
    check(CustomerOtp::verify($vera, 'reset', latestOtp('vera@example.test', 'reset')) === true, 'Reset OTP verifies.');
    check(Auth::setPassword($vera, 'Brand-New-Password-2026') === true, 'Password is reset after OTP.');
    check((int) $pdo->query('SELECT COUNT(*) FROM auth_remember_tokens WHERE user_id = ' . $vera . ' AND revoked_at IS NULL')->fetchColumn() === 0, 'Reset revokes remember tokens.');
    mutation();
    check(Auth::verifyPassword('vera@example.test', 'Brand-New-Password-2026')['user'] !== null, 'The new password works.');
    mutation();
    check(Auth::verifyPassword('vera@example.test', 'Disposable-Customer-Password')['user'] === null, 'The old password no longer works.');

    echo 'CustomerOtpTest: ' . $checks . " checks passed on $version\n";
} finally {
    if (isset($pdo) && $pdo instanceof PDO) $pdo = null;
    if ($admin instanceof PDO) { $admin->exec('DROP DATABASE IF EXISTS ' . MigrationSchema::identifier($schema)); }
    if (is_dir($temp)) removeTree($temp);
}
