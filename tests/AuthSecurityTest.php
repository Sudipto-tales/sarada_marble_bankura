<?php
// php tests/AuthSecurityTest.php [--mysql]. Only random disposable databases/storage.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/config/env.php';
require dirname(__DIR__) . '/config/migrate.php';
require dirname(__DIR__) . '/core/Auth.php';
require dirname(__DIR__) . '/core/Mailer.php';
if (in_array('--auth-child', $argv, true)) {
    require dirname(__DIR__) . '/config/db.php';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $input = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
    RequestSecurity::session();
    file_put_contents(getenv('AUTH_CHILD_READY'), 'ready');
    $deadline = microtime(true) + 8;
    while (!is_file(getenv('AUTH_CHILD_BARRIER'))) {
        if (microtime(true) >= $deadline) throw new RuntimeException('Child barrier timed out.');
        usleep(10000);
    }
    if ($input['mode'] === 'remember') {
        $_COOKIE['remember_me'] = $input['cookie'];
        $accepted = Auth::isAuthenticated();
    } else {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['csrf' => RequestSecurity::csrfToken()];
        $accepted = Auth::login('customer@example.test', 'Disposable-Customer-Password')['status'];
    }
    session_write_close();
    echo json_encode(['accepted' => $accepted]);
    exit;
}
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function rejects(callable $call, int $status, string $message): void
{
    try { $call(); } catch (RequestRejected $e) { check($e->status === $status, $message); return; }
    throw new RuntimeException($message);
}
function fails(callable $call, string $message): void
{
    try { $call(); } catch (Throwable $e) { check(true, $message); return; }
    throw new RuntimeException($message);
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
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    $_POST = ['csrf' => RequestSecurity::csrfToken()];
}
function resetAttempts(): void { global $pdo; $pdo->exec('DELETE FROM auth_login_limits'); mutation(); }
function competing(array $input, int $count, array $databaseEnvironment, string $temp): int
{
    $children = [];
    $barrier = $temp . '/barrier-' . bin2hex(random_bytes(8));
    try {
        for ($n = 0; $n < $count; $n++) {
            $ready = $barrier . '-ready-' . $n;
            $env = array_merge(getenv(), $databaseEnvironment, [
                'APP_ENV' => 'development', 'PRIVATE_STORAGE_PATH' => $temp . '/private',
                'AUTH_CHILD_READY' => $ready, 'AUTH_CHILD_BARRIER' => $barrier]);
            $process = proc_open([PHP_BINARY, __FILE__, '--auth-child'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env);
            if (!is_resource($process)) throw new RuntimeException('Unable to start child.');
            fwrite($pipes[0], json_encode($input)); fclose($pipes[0]);
            $children[] = [$process, $pipes, $ready];
        }
        $deadline = microtime(true) + 8;
        do {
            $allReady = true;
            foreach ($children as [$process, $pipes, $ready]) $allReady = is_file($ready) && $allReady;
            if ($allReady) break;
            if (microtime(true) >= $deadline) throw new RuntimeException('Children did not reach barrier.');
            usleep(10000);
        } while (true);
        file_put_contents($barrier, 'go');
        $accepted = 0;
        foreach ($children as [$process, $pipes, $ready]) {
            $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
            check(proc_close($process) === 0 && $err === '', 'Concurrent auth process completed safely.');
            $accepted += json_decode($out, true, 8, JSON_THROW_ON_ERROR)['accepted'] ? 1 : 0;
        }
        $children = [];
        return $accepted;
    } finally {
        foreach ($children as [$process, $pipes, $ready]) {
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        }
    }
}
function latestVerification(string $email): array
{
    global $pdo;
    $matches = [];
    foreach (glob(PrivateStorage::directory('test-mail') . '/*.json') as $file) {
        $mail = json_decode(file_get_contents($file), true);
        if ($mail['to'] === $email) {
            parse_str(parse_url($mail['verification_url'], PHP_URL_QUERY), $query);
            $lookup = $pdo->prepare('SELECT id FROM users_tbl WHERE email = ?');
            $lookup->execute([$email]);
            $query['id'] = $lookup->fetchColumn();
            $matches[] = [$query, $file];
        }
    }
    if (!$matches) throw new RuntimeException('Missing test mail.');
    return end($matches);
}
$mysql = in_array('--mysql', $argv, true);
$temp = sys_get_temp_dir() . '/vayu-auth-test-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
$_ENV['APP_ENV'] = 'development';
$_ENV['PRIVATE_STORAGE_PATH'] = $temp . '/private';
$_ENV['APP_URL'] = 'http://localhost/shop';
$_ENV['MAIL_TRANSPORT'] = 'disabled';
$_ENV['AUTH_REGISTRATION_ENABLED'] = 'false';
$_ENV['SESSION_SECURE_COOKIE'] = 'false';
$_ENV['TRUSTED_PROXIES'] = '';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'POST';
$admin = null;
$schema = 'vayu_auth_test_' . bin2hex(random_bytes(8));
$pdo = null;
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
        $databaseEnvironment = ['DB_TYPE' => 'mysql', 'DB_HOST' => $host, 'DB_PORT' => $port,
            'DB_USERNAME' => $user, 'DB_PASSWORD' => $password, 'DB_DATABASE' => $schema];
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
    } else {
        $pdo = new PDO('sqlite:' . $temp . '/auth.sqlite', null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $databaseEnvironment = ['DB_TYPE' => 'sqlite', 'DB_DATABASE' => $temp . '/auth.sqlite'];
        $version = $pdo->query('SELECT sqlite_version()')->fetchColumn();
    }
    require dirname(__DIR__) . '/database/migrations/UsersTable.php';
    require dirname(__DIR__) . '/database/migrations/0002_auth_protections.php';
    (new UsersTable($pdo))->up();
    $hash = password_hash('Disposable-Customer-Password', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users_tbl (firstname, lastname, name, email, password, email_verify, role, remember_token, verify_token)
        VALUES ('Existing', 'User', 'Existing User', ?, ?, 1, 'admin', 'legacy-remember-proof', 'legacy-verification-proof')")
        ->execute(['customer@example.test', $hash]);
    $id = (int) $pdo->lastInsertId();
    $upgrade = new AuthProtections($pdo);
    $upgrade->up(); $upgrade->verify();
    $row = $pdo->query('SELECT * FROM users_tbl')->fetch();
    check((int) $row['id'] === $id && $row['password'] === $hash, 'Token upgrade preserves user identity/hash.');
    check($row['remember_token'] === null && $row['verify_token'] === null, 'Legacy raw credentials are revoked.');
    $upgrade->up(); $upgrade->verify();
    fails(fn() => $pdo->prepare("INSERT INTO users_tbl (firstname, lastname, name, email, password)
        VALUES ('Case', 'Collision', 'Case Collision', ?, ?)")->execute(['CUSTOMER@EXAMPLE.TEST', $hash]), 'Case variants cannot create duplicate identities.');
    check((new MigrationRunner($pdo, dirname(__DIR__) . '/database/migrations', static function ($line) {}))->run() === count(glob(dirname(__DIR__) . '/database/migrations/*.php')),
        'Upgrade recovers compatible existing tables and records all migrations.');
    check((new MigrationRunner($pdo, dirname(__DIR__) . '/database/migrations', static function ($line) {}))->run() === 0,
        'Recorded upgrade is a no-op.');
    $pdo->exec('DROP INDEX idx_auth_remember_expiry' . ($mysql ? ' ON auth_remember_tokens' : ''));
    $upgrade->up(); $upgrade->verify();
    check(isset(MigrationSchema::indexes($pdo, 'auth_remember_tokens')['idx_auth_remember_expiry']), 'Interrupted upgrade repairs a missing index.');
    $pdo->exec('ALTER TABLE auth_login_limits RENAME TO auth_login_limits_saved');
    $identityDdl = $mysql ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $engineDdl = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $pdo->exec("CREATE TABLE auth_login_limits (id $identityDdl, bucket_key VARCHAR(10) NOT NULL,
        window_start BIGINT NOT NULL, attempts INT NOT NULL)$engineDdl");
    fails(fn() => $upgrade->up(), 'Upgrade rejects an incompatible pre-existing authentication table.');
    $pdo->exec('DROP TABLE auth_login_limits');
    $pdo->exec('ALTER TABLE auth_login_limits_saved RENAME TO auth_login_limits');
    $upgrade->verify();
    fails(fn() => $pdo->exec("INSERT INTO auth_remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (99999, 'missing', 'hash', 1)"), 'Token ownership FK rejects orphan.');

    RequestSecurity::session();
    check(ini_get('session.use_strict_mode') === '1' && ini_get('session.use_only_cookies') === '1', 'Strict cookie-only sessions.');
    $params = session_get_cookie_params();
    check($params['httponly'] && $params['samesite'] === 'Lax' && $params['domain'] === '', 'Host-only HttpOnly SameSite cookies.');
    check(str_starts_with(session_save_path(), $temp) && (fileperms(session_save_path()) & 0777) === 0700, 'Sessions use restricted private storage.');
    $_ENV['PRIVATE_STORAGE_PATH'] = dirname(__DIR__) . '/assets/rejected-private';
    fails(fn() => PrivateStorage::directory('cache'), 'Public storage rejected before creation.');
    check(!is_dir($_ENV['PRIVATE_STORAGE_PATH']), 'Rejected storage does not create public directories.');
    symlink(dirname(__DIR__), $temp . '/public-link');
    $_ENV['PRIVATE_STORAGE_PATH'] = $temp . '/public-link/private';
    fails(fn() => PrivateStorage::directory('cache'), 'Symlink into document root rejected.');
    $_ENV['PRIVATE_STORAGE_PATH'] = $temp . '/private';

    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    check(!RequestSecurity::secureCookies(), 'Untrusted proxy header cannot choose HTTPS.');
    $_ENV['TRUSTED_PROXIES'] = '127.0.0.1';
    check(RequestSecurity::secureCookies(), 'Explicit trusted HTTPS proxy accepted.');
    $_ENV['TRUSTED_PROXIES'] = '';
    unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
    $_ENV['APP_ENV'] = 'production';
    check(RequestSecurity::secureCookies(), 'Production always requires Secure cookies.');
    $_ENV['APP_ENV'] = 'development';
    $_POST = [];
    rejects(fn() => Auth::login('customer@example.test', 'Disposable-Customer-Password'), 403, 'Missing login CSRF rejected.');
    $_POST = ['csrf' => 'wrong'];
    rejects(fn() => Auth::login('customer@example.test', 'Disposable-Customer-Password'), 403, 'Wrong login CSRF rejected.');
    $_POST = ['csrf' => RequestSecurity::csrfToken()];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    rejects(fn() => Auth::login('customer@example.test', 'Disposable-Customer-Password'), 405, 'GET login cannot mutate.');
    mutation();
    $pdo->exec("UPDATE users_tbl SET email = 'CUSTOMER@EXAMPLE.TEST'");
    check(Auth::login('customer@example.test', 'Disposable-Customer-Password')['status'], 'Legacy mixed-case stored email remains accessible.');
    $pdo->exec("UPDATE users_tbl SET email = 'customer@example.test'");
    unset($_SESSION['user_id']);
    resetAttempts();
    $before = session_id();
    $oldCsrf = $_POST['csrf'];
    $_SESSION['developer_id'] = 77; $_SESSION['developer_csrf'] = 'separate-developer-proof';
    check(Auth::login('  CUSTOMER@EXAMPLE.TEST ', 'Disposable-Customer-Password', true)['status'], 'Normalized email/password login succeeds.');
    check(session_id() !== $before && RequestSecurity::csrfToken() !== $oldCsrf, 'Successful login regenerates session ID and CSRF.');
    check($_SESSION['user_id'] === $id && !isset($_SESSION['user_role']) && !isset($_SESSION['user_email']), 'Session stores identity reference without legacy role/email authority.');
    check($_SESSION['developer_id'] === 77 && $_SESSION['developer_csrf'] === 'separate-developer-proof', 'Customer login preserves separate Developer keys.');
    check(Auth::identity()['email'] === 'customer@example.test', 'Identity reads current database status.');
    $raw = $_COOKIE['remember_me'];
    [$selector, $validator] = explode(':', $raw);
    $token = $pdo->query('SELECT * FROM auth_remember_tokens')->fetch();
    check($token['validator_hash'] === hash('sha256', $validator) && !str_contains(json_encode($token), $validator), 'Only hashed validator is persisted.');
    check((int) $token['expires_at'] <= time() + 2592000 && (int) $token['expires_at'] > time(), 'Remember proof has bounded server expiry.');
    $upgrade->up();
    check($pdo->query('SELECT validator_hash FROM auth_remember_tokens')->fetchColumn() === $token['validator_hash'], 'Upgrade recovery does not revoke new hashed records.');
    unset($_SESSION['user_id']);
    check(Auth::isAuthenticated() && $_COOKIE['remember_me'] !== $raw, 'Remember proof restores session and rotates.');
    $rotated = $_COOKIE['remember_me'];
    check((int) $pdo->query('SELECT expires_at FROM auth_remember_tokens')->fetchColumn() === (int) $token['expires_at'], 'Rotation does not extend absolute expiry.');
    unset($_SESSION['user_id']); $_COOKIE['remember_me'] = $raw;
    check(!Auth::isAuthenticated(), 'Replayed pre-rotation remember proof denied.');
    $_COOKIE['remember_me'] = $rotated;
    check(Auth::isAuthenticated(), 'Current rotated proof still valid after stale replay.');
    $pdo->exec('UPDATE users_tbl SET status = 0');
    check(!Auth::isAuthenticated() && !isset($_SESSION['user_id']), 'Disabled account immediately loses active-session access.');
    check($pdo->query('SELECT revoked_at FROM auth_remember_tokens')->fetchColumn() !== null, 'Disabled identity revokes remember records.');
    $pdo->exec('UPDATE users_tbl SET status = 1');
    unset($_SESSION['user_id']); $_COOKIE['remember_me'] = $rotated;
    check(!Auth::isAuthenticated(), 'Revoked cookie cannot regain access after re-enable.');
    resetAttempts();
    $pdo->prepare('UPDATE users_tbl SET locked_until = ?')->execute([gmdate('Y-m-d H:i:s', time() + 900)]);
    check(!Auth::login('customer@example.test', 'Disposable-Customer-Password')['status'], 'Locked account denied.');
    $lockedFailure = Auth::login('customer@example.test', 'Disposable-Customer-Password');
    $pdo->exec('UPDATE users_tbl SET locked_until = NULL, email_verify = 0');
    check(Auth::login('customer@example.test', 'Disposable-Customer-Password') === $lockedFailure, 'Unverified and locked failures are generic.');
    check(Auth::login('unknown@example.test', 'Disposable-Customer-Password') === $lockedFailure, 'Unknown-account failure does not expose existence.');
    $pdo->exec('UPDATE users_tbl SET email_verify = 1');
    resetAttempts();
    for ($n = 0; $n < 5; $n++) check(!Auth::login('customer@example.test', 'wrong')['status'], 'Failed login is denied.');
    $savedCsrf = RequestSecurity::csrfToken();
    session_regenerate_id(true); $_SESSION = []; mutation();
    check(Auth::login('customer@example.test', 'Disposable-Customer-Password')['code'] === 'throttled', 'Throttle persists across sessions.');
    check((int) $pdo->query('SELECT MAX(attempts) FROM auth_login_limits')->fetchColumn() === 6, 'Throttle counters are bounded.');
    $_POST = ['csrf' => $savedCsrf];
    rejects(fn() => Auth::login('customer@example.test', 'Disposable-Customer-Password'), 403, 'Another session CSRF proof denied.');
    resetAttempts();
    check(Auth::login('customer@example.test', 'Disposable-Customer-Password', true)['status'], 'Remember login after reset succeeds.');
    check(competing(['mode' => 'remember', 'cookie' => $_COOKIE['remember_me']], 2, $databaseEnvironment, $temp) === 1,
        'Two processes racing the same remember proof restore exactly one session.');
    resetAttempts();
    check(competing(['mode' => 'login'], 8, $databaseEnvironment, $temp) <= 5,
        'Concurrent login attempts cannot bypass the five-attempt window.');
    check((int) $pdo->query('SELECT MAX(attempts) FROM auth_login_limits')->fetchColumn() === 6,
        'Concurrent persistent throttle retains a bounded saturation counter.');
    resetAttempts();
    check(Auth::login('customer@example.test', 'Disposable-Customer-Password', true)['status'], 'Fresh proof after race succeeds.');
    unset($_SESSION['user_id']);
    $pdo->exec('UPDATE auth_remember_tokens SET expires_at = 1');
    check(!Auth::isAuthenticated(), 'Server-expired remember cookie denied.');
    $_COOKIE['remember_me'] = 'legacy-remember-proof';
    check(!Auth::isAuthenticated(), 'Legacy raw remember cookie denied.');
    resetAttempts();
    check(Auth::login('customer@example.test', 'Disposable-Customer-Password', true)['status'], 'Login before logout succeeds.');
    $logoutCookie = $_COOKIE['remember_me'];
    $_SESSION['developer_id'] = 77; $_SESSION['developer_csrf'] = 'independent';
    mutation();
    Auth::logout();
    check(!Auth::isAuthenticated() && !isset($_COOKIE['remember_me']), 'Logout clears commerce identity/cookie.');
    check($_SESSION['developer_id'] === 77 && $_SESSION['developer_csrf'] === 'independent', 'Logout preserves Developer session.');
    $_COOKIE['remember_me'] = $logoutCookie;
    check(!Auth::isAuthenticated(), 'Logout revokes saved remember proof.');
    mutation();
    check(Auth::register('New', 'User', 'New User', 'new@example.test', 'Disposable-New-Password')['code'] === 'unavailable', 'Signup disabled before queue integration.');
    $_ENV['AUTH_REGISTRATION_ENABLED'] = 'true'; $_ENV['AUTH_VERIFICATION_TRANSPORT'] = 'test';
    $_ENV['APP_ENV'] = 'production';
    check(Auth::register('New', 'User', 'New User', 'new@example.test', 'Disposable-New-Password')['code'] === 'unavailable', 'Test transport cannot enable production signup.');
    $_ENV['APP_ENV'] = 'development';
    resetAttempts();
    $beforeUsers = (int) $pdo->query('SELECT COUNT(*) FROM users_tbl')->fetchColumn();
    $_ENV['APP_URL'] = 'invalid-url';
    $failure = Auth::register('Rollback', 'User', 'Rollback User', 'rollback@example.test', 'Disposable-Rollback-Password');
    check(!$failure['status'] && !str_contains($failure['message'], 'URL'), 'Registration delivery setup error is sanitized.');
    check((int) $pdo->query('SELECT COUNT(*) FROM users_tbl')->fetchColumn() === $beforeUsers
        && (int) $pdo->query('SELECT COUNT(*) FROM auth_verification_tokens')->fetchColumn() === 0, 'Delivery setup failure leaves neither identity nor token.');
    $_ENV['APP_URL'] = 'http://localhost/shop';
    $beforeMail = count(glob(PrivateStorage::directory('test-mail') . '/*.json'));
    $pdo->exec($mysql
        ? "CREATE TRIGGER auth_verification_failure BEFORE INSERT ON auth_verification_tokens FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected-private-db-failure'"
        : "CREATE TRIGGER auth_verification_failure BEFORE INSERT ON auth_verification_tokens BEGIN SELECT RAISE(ABORT, 'injected-private-db-failure'); END");
    $dbFailure = Auth::register('Rollback', 'User', 'Rollback User', 'db-rollback@example.test', 'Disposable-Rollback-Password');
    $pdo->exec('DROP TRIGGER auth_verification_failure');
    check(!$dbFailure['status'] && !str_contains($dbFailure['message'], 'injected'), 'Registration token-write failure is sanitized.');
    check((int) $pdo->query('SELECT COUNT(*) FROM users_tbl')->fetchColumn() === $beforeUsers
        && (int) $pdo->query('SELECT COUNT(*) FROM auth_verification_tokens')->fetchColumn() === 0
        && count(glob(PrivateStorage::directory('test-mail') . '/*.json')) === $beforeMail,
        'Failed token insert rolls back user and removes pre-staged private test mail.');
    $_POST['role'] = 'admin';
    check(Auth::register('New', 'User', 'New User', ' NEW@EXAMPLE.TEST ', 'Disposable-New-Password', 'admin')['status'], 'Explicit local test signup creates account.');
    $newUser = $pdo->query("SELECT * FROM users_tbl WHERE email = 'new@example.test'")->fetch();
    check($newUser['role'] === 'user' && $newUser['designation'] === null && !(int) $newUser['email_verify'], 'Signup ignores client role/designation escalation.');
    [$proof, $mailFile] = latestVerification('new@example.test');
    $stored = $pdo->query('SELECT * FROM auth_verification_tokens')->fetch();
    check($stored['token_hash'] === hash('sha256', $proof['token']) && (int) $stored['expires_at'] <= time() + 3600, 'Verification is hashed with one-hour expiry.');
    check((fileperms($mailFile) & 0777) === 0600, 'Test mail with proof has private file permissions.');
    check(!Auth::verifyEmail($newUser['id'], 'legacy-verification-proof')['status'], 'Legacy verification proof denied.');
    check(!Auth::verifyEmail([], $proof['token'])['status'], 'Malformed verification ID rejected without a type warning.');
    check(!Auth::verifyEmail($id, $proof['token'])['status'], 'Verification proof cannot verify another user.');
    check(Auth::verifyEmail(null, $proof['token'])['status'], 'New proof-only link resolves its owner and verifies email.');
    check(!Auth::verifyEmail($proof['id'], $proof['token'])['status'], 'Consumed verification proof cannot replay.');
    mutation();
    check(Auth::register('Expiry', 'User', 'Expiry User', 'expiry@example.test', 'Disposable-Expiry-Password')['status'], 'Expiry test account created.');
    [$expired] = latestVerification('expiry@example.test');
    $pdo->exec('UPDATE auth_verification_tokens SET expires_at = 1');
    check(!Auth::verifyEmail($expired['id'], $expired['token'])['status'], 'Expired verification proof denied.');
    $pdo->exec('DELETE FROM auth_login_limits');
    check(Auth::resendVerificationEmail('expiry@example.test')['status'], 'Eligible local resend succeeds.');
    $newHash = $pdo->query('SELECT token_hash FROM auth_verification_tokens')->fetchColumn();
    check($newHash !== hash('sha256', $expired['token']), 'Resend rotates verification proof.');
    check(!Auth::verifyEmail($expired['id'], $expired['token'])['status'], 'Prior resend proof denied.');
    check(Auth::resendVerificationEmail('unknown@example.test')['message'] === Auth::resendVerificationEmail('expiry@example.test')['message'], 'Resend does not expose account existence.');
    check(!Mailer::send('someone@example.test', 'Test', 'Disabled transport'), 'Default mail transport performs no delivery.');
    $_ENV['MAIL_TRANSPORT'] = 'smtp';
    try { Mailer::send('someone@example.test', 'Test', 'Unavailable transport'); throw new LogicException('Transport must fail.'); }
    catch (RuntimeException $e) { check($e->getMessage() === 'Email delivery is unavailable.', 'Mail exceptions expose no provider details.'); }
    $_ENV['MAIL_TRANSPORT'] = 'disabled';
    check(RequestSecurity::decodeJson('{"quantity":1}') === ['quantity' => 1], 'JSON object parsing works.');
    rejects(fn() => RequestSecurity::decodeJson('[]'), 400, 'Top-level arrays rejected.');
    rejects(fn() => RequestSecurity::decodeJson('{bad}'), 400, 'Malformed JSON rejected.');
    rejects(fn() => RequestSecurity::decodeJson(str_repeat('x', 20), 10), 413, 'Oversized JSON rejected.');
    session_write_close();
    echo 'Auth security (' . ($mysql ? 'MySQL driver / ' : 'SQLite / ') . $version . "): $checks checks passed.\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $pdo = null;
    if ($admin) $admin->exec('DROP DATABASE ' . MigrationSchema::identifier($schema));
    removeTree($temp);
}
