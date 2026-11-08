<?php
// php tests/OtpChannelsTest.php [--mysql]. Only random disposable databases/storage.
// Covers admin channel availability/credentials resolution and the per-user two-step columns.
// Live WhatsApp/MSG91 delivery is network-bound and intentionally not exercised here.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/config/env.php';
require_once dirname(__DIR__) . '/config/migrate.php';
require_once dirname(__DIR__) . '/core/CommerceDatabase.php';
require_once dirname(__DIR__) . '/core/CommerceValues.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/core/OtpChannels.php';

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
/** Upsert a store_settings row directly, standing in for an admin save. */
function setSetting(PDO $pdo, string $key, string $value): void
{
    $sql = 'INSERT INTO store_settings (skey,svalue,updated_at) VALUES (?,?,?)';
    $sql .= $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? ' ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)'
        : ' ON CONFLICT(skey) DO UPDATE SET svalue=excluded.svalue';
    $pdo->prepare($sql)->execute([$key, $value, time()]);
}

$mysql = in_array('--mysql', $argv, true);
$temp = sys_get_temp_dir() . '/vayu-otp-chan-test-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
$_ENV['APP_ENV'] = 'development';
$_ENV['PRIVATE_STORAGE_PATH'] = $temp . '/private';

$schema = 'vayu_otp_chan_test_' . bin2hex(random_bytes(8));
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

    (new MigrationRunner($pdo, dirname(__DIR__) . '/database/migrations', static function ($line) {}))->run();

    // --- Default: only email is available -----------------------------------------
    $available = OtpChannels::available($pdo);
    check($available['email'] === true, 'Email is always available.');
    check($available['whatsapp'] === false && $available['sms'] === false, 'Optional channels are off until configured.');
    check(OtpChannels::credentials($pdo, 'whatsapp') === null, 'No credentials for an unconfigured channel.');

    // --- Enabled but incomplete stays unavailable ---------------------------------
    setSetting($pdo, 'otp_whatsapp_enabled', '1');
    setSetting($pdo, 'otp_whatsapp_phone_id', '123456');
    check(OtpChannels::available($pdo)['whatsapp'] === false, 'Enabled without full credentials is not usable.');

    // --- Fully configured becomes available and resolves credentials --------------
    setSetting($pdo, 'otp_whatsapp_template', 'auth_code');
    setSetting($pdo, 'otp_whatsapp_token', str_repeat('T', 300)); // long token must fit the widened column
    $available = OtpChannels::available($pdo);
    check($available['whatsapp'] === true, 'A fully configured WhatsApp channel is available.');
    $creds = OtpChannels::credentials($pdo, 'whatsapp');
    check($creds !== null && $creds['phone_id'] === '123456' && $creds['template'] === 'auth_code' && strlen($creds['token']) === 300,
        'Credentials resolve including the long access token.');

    // --- Admin view never leaks the secret ----------------------------------------
    $view = OtpChannels::adminView($pdo);
    check($view['whatsapp']['phone_id'] === '123456', 'Admin view exposes non-secret values.');
    check($view['whatsapp']['token'] === true, 'Admin view reports the token as stored, not its value.');
    check($view['sms']['authkey'] === false, 'An unset secret reports as not stored.');

    // --- Per-user two-step columns ------------------------------------------------
    $hash = password_hash('Disposable-Customer-Password', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users_tbl (firstname, lastname, name, email, password, role, email_verify, status) VALUES ('Taylor','Two','Taylor Two',?,?,'user',1,1)")
        ->execute(['taylor@example.test', $hash]);
    $uid = (int) $pdo->lastInsertId();
    $GLOBALS['pdo'] = $pdo; // Auth uses the bootstrapped connection
    Auth::setTwoFactor($uid, true, 'whatsapp', '+919999999999');
    $row = $pdo->query("SELECT otp_enabled, otp_channel, otp_phone FROM users_tbl WHERE id = $uid")->fetch();
    check((int) $row['otp_enabled'] === 1 && $row['otp_channel'] === 'whatsapp' && $row['otp_phone'] === '+919999999999', 'Two-step preference is stored.');
    Auth::setTwoFactor($uid, false);
    $row = $pdo->query("SELECT otp_enabled, otp_phone FROM users_tbl WHERE id = $uid")->fetch();
    check((int) $row['otp_enabled'] === 0 && $row['otp_phone'] === null, 'Disabling two-step clears the flag and phone.');

    echo "OtpChannelsTest: {$checks} checks passed on {$version}.\n";
} finally {
    if (isset($pdo)) { $pdo = null; }
    if ($mysql && $admin !== null) $admin->exec('DROP DATABASE IF EXISTS ' . MigrationSchema::identifier($schema));
    removeTree($temp);
}
