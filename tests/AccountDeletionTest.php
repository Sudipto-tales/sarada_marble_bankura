<?php
// php tests/AccountDeletionTest.php [--mysql]. Only random disposable databases/storage.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/config/env.php';
require_once dirname(__DIR__) . '/config/migrate.php';
require_once dirname(__DIR__) . '/core/RequestSecurity.php';
require_once dirname(__DIR__) . '/core/PrivateStorage.php';
require_once dirname(__DIR__) . '/core/Mailer.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/core/CustomerOtp.php';
require_once dirname(__DIR__) . '/app/Services/AccountDeletionService.php';

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
function mutation(): void { $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['csrf' => RequestSecurity::csrfToken()]; }
function count_rows(string $sql, array $values = []): int { global $pdo; $stmt = $pdo->prepare($sql); $stmt->execute($values); return (int) $stmt->fetchColumn(); }

$mysql = in_array('--mysql', $argv, true);
$temp = sys_get_temp_dir() . '/vayu-delete-test-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
$_ENV['APP_ENV'] = 'development';
$_ENV['APP_URL'] = 'http://localhost/shop';
$_ENV['PRIVATE_STORAGE_PATH'] = $temp . '/private';
$_ENV['MAIL_TRANSPORT'] = 'disabled';
$_ENV['SESSION_SECURE_COOKIE'] = 'false';
$_ENV['TRUSTED_PROXIES'] = '';
$_ENV['AUTH_OTP_ENABLED'] = 'true';
$_ENV['AUTH_OTP_CHANNEL'] = 'test';
$_ENV['AUTH_OTP_TTL_MINUTES'] = '10';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'delete-test-agent';
$_SERVER['REQUEST_METHOD'] = 'POST';

$schema = 'vayu_delete_test_' . bin2hex(random_bytes(8));
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
        $pdo = new PDO('sqlite:' . $temp . '/delete.sqlite', null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $version = 'SQLite ' . $pdo->query('SELECT sqlite_version()')->fetchColumn();
    }

    (new MigrationRunner($pdo, dirname(__DIR__) . '/database/migrations', static function ($line) {}))->run();

    // A verified customer with a password, plus the personal state deletion must clear.
    $hash = password_hash('Disposable-Customer-Password', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users_tbl (firstname, lastname, name, email, password, role, email_verify, status) VALUES ('Vera','Verified','Vera Verified',?,?,'user',1,1)")
        ->execute(['vera@example.test', $hash]);
    $vera = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO addresses (user_id, label, recipient, phone, line1, line2, city, state, postal_code, country_code, is_default, enabled, revision)
        VALUES (?, 'Home', 'Vera Verified', '9999999999', '1 Stone Rd', '', 'Bankura', 'West Bengal', '722101', 'IN', 1, 1, 1)")->execute([$vera]);
    // Seed a wishlist row without a real product (FK cascades to products); deleting it never violates the FK.
    if (!$mysql) $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->prepare('INSERT INTO wishlists (user_id, product_id, created_at) VALUES (?, 1, ?)')->execute([$vera, time()]);
    if (!$mysql) $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->prepare("INSERT INTO carts (user_id, guest_token_hash, owner_key, status, revision, expires_at, created_at) VALUES (?, NULL, ?, 'active', 1, ?, ?)")
        ->execute([$vera, 'user:' . $vera, time() + 3600, time()]);
    $cartId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO orders (order_number, user_id, cart_id, status, revision, currency, subtotal_minor, discount_minor, shipping_minor, tax_minor, total_minor, address_snapshot, calculation_policy, tax_bps, payment_mode, payment_status, created_at, updated_at)
        VALUES ('MS-TEST-0001', ?, ?, 'placed', 1, 'INR', 10000, 0, 0, 1800, 11800, ?, 'standard', 1800, 'cod', 'pending', ?, ?)")
        ->execute([$vera, $cartId, json_encode(['recipient' => 'Vera Verified', 'city' => 'Bankura']), time(), time()]);

    RequestSecurity::session();
    CustomerOtp::trustCurrentDevice($vera);

    // --- The 'delete' OTP purpose round-trips and requires a valid code -----------
    check(CustomerOtp::request($vera, 'delete', 'vera@example.test'), 'Account-deletion OTP is issued.');
    $code = latestOtp('vera@example.test', 'delete');
    $wrong = $code === '000000' ? '111111' : '000000';
    check(CustomerOtp::verify($vera, 'delete', $wrong) === false, 'A wrong deletion code is rejected.');
    check(CustomerOtp::request($vera, 'delete', 'vera@example.test'), 'A fresh deletion OTP is issued after a wrong guess.');
    check(CustomerOtp::verify($vera, 'delete', latestOtp('vera@example.test', 'delete')) === true, 'A valid deletion code verifies.');

    // Pre-conditions exist so the post-delete zero counts are meaningful.
    check(count_rows('SELECT COUNT(*) FROM addresses WHERE user_id = ?', [$vera]) === 1, 'The address exists before deletion.');
    check(count_rows('SELECT COUNT(*) FROM wishlists WHERE user_id = ?', [$vera]) === 1, 'The wishlist row exists before deletion.');
    check(count_rows('SELECT COUNT(*) FROM auth_trusted_devices WHERE user_id = ?', [$vera]) === 1, 'The trusted device exists before deletion.');

    // --- Soft-delete scrubs PII and personal state but keeps the order ledger -----
    (new AccountDeletionService($pdo))->delete($vera);
    $row = $pdo->prepare('SELECT status, email, name, firstname, lastname, password FROM users_tbl WHERE id = ?');
    $row->execute([$vera]); $user = $row->fetch();
    check((int) $user['status'] === 0, 'The account is disabled (status 0).');
    check($user['email'] === 'deleted+' . $vera . '@deleted.invalid', 'The email is freed for re-registration.');
    check($user['name'] === 'Deleted user' && $user['firstname'] === 'Deleted' && $user['lastname'] === 'User', 'Profile name PII is scrubbed.');
    check(password_verify('Disposable-Customer-Password', $user['password']) === false, 'The original password no longer works.');
    check(count_rows('SELECT COUNT(*) FROM addresses WHERE user_id = ?', [$vera]) === 0, 'Addresses are removed.');
    check(count_rows('SELECT COUNT(*) FROM wishlists WHERE user_id = ?', [$vera]) === 0, 'Wishlist rows are removed.');
    check(count_rows('SELECT COUNT(*) FROM auth_trusted_devices WHERE user_id = ?', [$vera]) === 0, 'Trusted devices are removed.');
    check($pdo->query("SELECT status FROM carts WHERE id = $cartId")->fetchColumn() === 'abandoned', 'The active cart is abandoned.');
    $order = $pdo->query("SELECT user_id, total_minor FROM orders WHERE order_number = 'MS-TEST-0001'")->fetch();
    check($order !== false && (int) $order['user_id'] === $vera && (int) $order['total_minor'] === 11800, 'The order ledger is unchanged.');

    // --- A deleted account cannot sign in, and re-deletion is a no-op -------------
    mutation();
    check(Auth::verifyPassword('vera@example.test', 'Disposable-Customer-Password')['user'] === null, 'A deleted account cannot sign in.');
    (new AccountDeletionService($pdo))->delete($vera);
    check($user['email'] === 'deleted+' . $vera . '@deleted.invalid'
        && count_rows('SELECT COUNT(*) FROM addresses WHERE user_id = ?', [$vera]) === 0, 'Repeating deletion is a safe no-op.');

    echo 'AccountDeletionTest: ' . $checks . " checks passed on $version\n";
} finally {
    if (isset($pdo) && $pdo instanceof PDO) $pdo = null;
    if ($admin instanceof PDO) { $admin->exec('DROP DATABASE IF EXISTS ' . MigrationSchema::identifier($schema)); }
    if (is_dir($temp)) removeTree($temp);
}
