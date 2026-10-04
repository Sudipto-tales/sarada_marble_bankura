<?php
// Run: php tests/DeveloperAccessTest.php. Uses only an in-memory database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/core/DeveloperAccess.php';
define('DEVELOPER_PERMISSION', 'developer.view');
define('DEVELOPER_ROLES', ['developer', 'admin']);
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
DeveloperAccess::session();
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

try {
    DeveloperAccess::provision('invalid', 'short');
    throw new RuntimeException('Invalid account was accepted');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'valid email'), 'Provisioning validates inputs');
}
DeveloperAccess::provision('console@example.test', 'Test-Only-Console-Password');
$row = $pdo->query('SELECT * FROM developer_accounts')->fetch(PDO::FETCH_ASSOC);
check($row['password_hash'] !== 'Test-Only-Console-Password', 'Password is hashed');
check(password_verify('Test-Only-Console-Password', $row['password_hash']), 'Password hash verifies');
check(DeveloperAccess::identity() === null, 'Anonymous sessions are denied');
$_POST = ['csrf' => 'wrong'];
check(!DeveloperAccess::csrf(), 'Invalid CSRF is rejected');
$_POST = ['csrf' => $_SESSION['developer_csrf']];
check(DeveloperAccess::csrf(), 'Valid CSRF is accepted');
check(DeveloperAccess::login('console@example.test', 'Test-Only-Console-Password') === '', 'Valid account signs in');
check(DeveloperAccess::identity()['email'] === 'console@example.test', 'Signed-in identity resolves');

$pdo->exec('UPDATE developer_accounts SET enabled = 0');
check(DeveloperAccess::identity() === null, 'Disabled accounts lose current-session access');
$pdo->exec("UPDATE developer_accounts SET enabled = 1, role = 'customer'");
check(DeveloperAccess::identity() === null, 'Unauthorized role loses access');
$pdo->exec("UPDATE developer_accounts SET role = 'developer', permissions = '[]'");
check(DeveloperAccess::identity() === null, 'Missing base permission denies access');
$permissions = array_values(array_unique(array_merge([DEVELOPER_PERMISSION], array_column(DeveloperAccess::PAGES, 2))));
$pdo->prepare('UPDATE developer_accounts SET permissions = ?')->execute([json_encode($permissions)]);
$_SESSION['developer_seen'] = time() - 1801;
check(DeveloperAccess::identity() === null && !isset($_SESSION['developer_id']), 'Expired session is revoked');

$pdo->exec('DELETE FROM developer_login_attempts');
for ($attempt = 0; $attempt < 5; $attempt++) {
    check(str_contains(DeveloperAccess::login('console@example.test', 'wrong'), 'Invalid credentials'), 'Failed credentials are generic');
}
check(str_contains(DeveloperAccess::login('console@example.test', 'Test-Only-Console-Password'), 'Too many attempts'), 'Throttle applies across attempts');
check(!isset($_SESSION['developer_id']), 'Failed and throttled login never creates identity');
session_write_close();
echo "Developer access: {$checks} checks passed.\n";
