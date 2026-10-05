<?php
// Disposable resources only. php tests/FoundationTest.php [--mysql]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/config/env.php';
require dirname(__DIR__) . '/config/migrate.php';

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function command(array $arguments, array $environment = [], string $input = ''): array
{
    $process = proc_open(array_merge([PHP_BINARY], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
        dirname(__DIR__), array_merge(getenv(), $environment));
    if (!is_resource($process)) throw new RuntimeException('Unable to start test process.');
    fwrite($pipes[0], $input); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), $out, $err];
}
function fails(callable $operation, string $message): void
{
    try { $operation(); } catch (Throwable $error) { check(true, $message); return; }
    throw new RuntimeException($message);
}
function removeDirectory(string $path): void
{
    foreach (scandir($path) as $name) {
        if ($name === '.' || $name === '..') continue;
        $file = $path . '/' . $name;
        if (is_dir($file) && !is_link($file)) removeDirectory($file); else unlink($file);
    }
    rmdir($path);
}
$mysql = in_array('--mysql', $argv, true);
$temp = sys_get_temp_dir() . '/vayu-foundation-test-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
$admin = null;
$schema = 'vayu_foundation_test_' . bin2hex(random_bytes(8));
$env = ['DB_TYPE' => 'sqlite', 'DB_DATABASE' => $temp . '/main.sqlite'];
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
try {
    if ($mysql) {
        $host = getenv('TEST_MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('TEST_MYSQL_PORT') ?: '3306';
        $user = getenv('TEST_MYSQL_USER') ?: 'root';
        $password = getenv('TEST_MYSQL_PASSWORD') ?: '';
        if (preg_match('/[;\x00]/', $host) || !ctype_digit($port)) throw new RuntimeException('Invalid test host/port.');
        $options[PDO::ATTR_EMULATE_PREPARES] = false;
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        $admin = new PDO($dsn, $user, $password, $options);
        // Never choose an application database: create a new, random test schema.
        $admin->exec('CREATE DATABASE ' . MigrationSchema::identifier($schema) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo = new PDO($dsn . ';dbname=' . $schema, $user, $password, $options);
        $env = ['DB_TYPE' => 'mysql', 'DB_HOST' => $host, 'DB_PORT' => $port,
            'DB_USERNAME' => $user, 'DB_PASSWORD' => $password, 'DB_DATABASE' => $schema];
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
    } else {
        $pdo = new PDO('sqlite:' . $env['DB_DATABASE'], null, null, $options);
        $version = $pdo->query('SELECT sqlite_version()')->fetchColumn();
    }
    $migrations = dirname(__DIR__) . '/database/migrations';
    [$code, $out, $err] = command(['vayu', 'migrate'], $env);
    check($code === 0 && $err === '', 'Fresh CLI migration succeeds without HTTP output: ' . $err);
    check(strpos($out, 'UsersTable') < strpos($out, '0001_users_foundation'), 'Legacy migration runs first.');
    check((int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === 2, 'Both migrations recorded.');
    check((int) $pdo->query('SELECT COUNT(*) FROM users_tbl')->fetchColumn() === 0, 'No demo identities seeded.');
    [$code, $out, $err] = command(['vayu', 'migrate'], $env);
    check($code === 0 && str_contains($out, 'No pending') && $err === '', 'Repeat migration is a no-op.');

    $hash = password_hash('Disposable-Identity-Only', PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO users_tbl (firstname, lastname, name, email, password) VALUES (?, ?, ?, ?, ?)')
        ->execute(['Existing', 'User', 'Existing User', 'existing@example.test', $hash]);
    $before = $pdo->query('SELECT * FROM users_tbl')->fetch();
    $pdo->exec('DROP INDEX idx_migrations_identity' . ($mysql ? ' ON migrations' : ''));
    $pdo->exec("DELETE FROM migrations WHERE migration = '0001_users_foundation'");
    foreach (['idx_users_verify_token', 'idx_users_remember_token'] as $index) {
        $pdo->exec('DROP INDEX ' . MigrationSchema::identifier($index) . ($mysql ? ' ON users_tbl' : ''));
    }
    [$code, $out, $err] = command(['vayu', 'migrate'], $env);
    check($code === 0 && !str_contains($out, 'Running: UsersTable'), 'Recorded legacy migration is not rerun.');
    check($pdo->query('SELECT * FROM users_tbl')->fetch() === $before, 'ID, hash and all user values preserved.');
    check(count(MigrationSchema::indexes($pdo, 'users_tbl')) >= 3, 'Missing indexes repaired forward.');
    check(isset(MigrationSchema::indexes($pdo, 'migrations')['idx_migrations_identity']), 'Legacy ledger gains unique identity index.');

    // Interrupted DDL with a repeat-safe migration, separate from production files.
    $fixture = $temp . '/migrations'; mkdir($fixture);
    foreach (glob($migrations . '/*.php') as $file) symlink($file, $fixture . '/' . basename($file));
    file_put_contents($fixture . '/0002_foundation_recovery.php', <<<'FIXTURE'
<?php
class FoundationRecovery extends Migration {
    public function up() {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS recovery_probe (value INTEGER NOT NULL)');
        if (getenv('FOUNDATION_FAIL') === '1') throw new RuntimeException('Injected test failure.');
        MigrationSchema::ensureIndex($this->pdo, 'recovery_probe', 'idx_recovery_value', ['value']);
    }
    public function verify(): void {
        MigrationSchema::requireColumns($this->pdo, 'recovery_probe', ['value']);
        if (!isset(MigrationSchema::indexes($this->pdo, 'recovery_probe')['idx_recovery_value'])) throw new RuntimeException('Missing index.');
    }
}
FIXTURE
    );
    $runner = new MigrationRunner($pdo, $fixture, static function (string $line): void {});
    putenv('FOUNDATION_FAIL=1');
    fails(fn() => $runner->run(), 'Injected failure stops migration.');
    check((int) $pdo->query("SELECT COUNT(*) FROM migrations WHERE migration = '0002_foundation_recovery'")->fetchColumn() === 0, 'Failed migration has no ledger row.');
    if ($mysql) check(count(MigrationSchema::columns($pdo, 'recovery_probe')) === 1, 'MySQL DDL survived failure.');
    else check(MigrationSchema::columns($pdo, 'recovery_probe') === [], 'SQLite failed DDL rolled back.');
    putenv('FOUNDATION_FAIL=0');
    check($runner->run() === 1 && $runner->run() === 0, 'Interrupted migration repairs once then becomes a no-op.');
    check(isset(MigrationSchema::indexes($pdo, 'recovery_probe')['idx_recovery_value']), 'Rerun completed missing index.');

    file_put_contents($fixture . '/0003_foundation_concurrency.php', <<<'FIXTURE'
<?php
class FoundationConcurrency extends Migration {
    public function up() {
        usleep(250000);
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS concurrent_probe (value INTEGER NOT NULL)');
        $this->pdo->exec('INSERT INTO concurrent_probe VALUES (1)');
    }
    public function verify(): void { MigrationSchema::requireColumns($this->pdo, 'concurrent_probe', ['value']); }
}
FIXTURE
    );
    $worker = $temp . '/runner.php';
    file_put_contents($worker, '<?php require ' . var_export(dirname(__DIR__) . '/config/cli.php', true)
        . '; require ' . var_export(dirname(__DIR__) . '/config/migrate.php', true)
        . '; (new MigrationRunner($pdo, ' . var_export($fixture, true) . '))->run();');
    $workers = [];
    for ($i = 0; $i < 2; $i++) {
        $process = proc_open([PHP_BINARY, $worker], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, dirname(__DIR__), array_merge(getenv(), $env));
        if (!is_resource($process)) throw new RuntimeException('Unable to launch concurrent runner.');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) {
        stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        check(proc_close($process) === 0 && $error === '', 'Concurrent CLI runner completes safely.');
    }
    check((int) $pdo->query('SELECT COUNT(*) FROM concurrent_probe')->fetchColumn() === 1, 'Concurrent runners execute pending migration only once.');

    if ($mysql) {
        $key = 'vayu:migrate:' . substr(hash('sha256', $schema), 0, 48);
        $admin->prepare('SELECT GET_LOCK(?, 0)')->execute([$key]);
        try { fails(fn() => (new MigrationRunner($pdo, $migrations, null, 0))->run(), 'Other connection cannot enter held named lock.'); }
        finally { $admin->prepare('SELECT RELEASE_LOCK(?)')->execute([$key]); }
    } else {
        $handle = fopen(realpath($env['DB_DATABASE']) . '.migrate.lock', 'c'); flock($handle, LOCK_EX);
        try { fails(fn() => (new MigrationRunner($pdo, $migrations, null, 0))->run(), 'Other runner cannot enter held file lock.'); }
        finally { flock($handle, LOCK_UN); fclose($handle); }
    }
    check((new MigrationRunner($pdo, $migrations, static function (string $line): void {}, 0))->run() === 0, 'Lock released after failure.');

    $bad = $temp . '/bad'; mkdir($bad);
    file_put_contents($bad . '/0001_missing_class.php', "<?php\n");
    fails(fn() => (new MigrationRunner($pdo, $bad, static function (string $line): void {}))->run(), 'Missing class is fatal.');
    file_put_contents($bad . '/0002_missing_class.php', "<?php\n");
    fails(fn() => (new MigrationRunner($pdo, $bad, static function (string $line): void {}))->run(), 'Duplicate derived class is fatal.');

    $pdo->exec('DROP TABLE users_tbl');
    $pdo->exec('CREATE TABLE users_tbl (id VARCHAR(20) PRIMARY KEY)');
    [$code, $out, $err] = command(['vayu', 'migrate'], $env);
    check($code !== 0 && str_contains($err, 'repair') && !str_contains($err, 'SQLSTATE'), 'Incompatible recorded schema gives safe, nonzero CLI failure.');
    $pdo->exec('DROP TABLE users_tbl');
    $pdo->exec("DELETE FROM migrations WHERE migration IN ('UsersTable', '0001_users_foundation')");
    check((new MigrationRunner($pdo, $migrations, static function (string $line): void {}))->run() === 2, 'Repair/retry works after incompatible schema.');

    foreach ($env as $key => $value) $_ENV[$key] = $value;
    require dirname(__DIR__) . '/config/db.php';
    check($pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE) === PDO::FETCH_ASSOC, 'Associative fetch default.');
    if ($mysql) {
        check(!$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), 'Native MySQL prepared statements.');
        $unicode = 'Marble মেঝে 🪨';
        $pdo->prepare('INSERT INTO users_tbl (firstname, lastname, name, email, password) VALUES (?, ?, ?, ?, ?)')
            ->execute(['Unicode', 'User', $unicode, 'unicode@example.test', $hash]);
        check($pdo->query("SELECT name FROM users_tbl WHERE email = 'unicode@example.test'")->fetchColumn() === $unicode, 'utf8mb4 roundtrip.');
        $pdo->exec('DELETE FROM users_tbl');
    } else {
        check((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1, 'SQLite foreign keys enabled.');
        check((int) $pdo->query('PRAGMA busy_timeout')->fetchColumn() === 5000, 'SQLite bounded busy timeout.');
        $pdo->exec('CREATE TABLE child_probe (user_id INTEGER REFERENCES users_tbl(id))');
        fails(fn() => $pdo->exec('INSERT INTO child_probe VALUES (99999)'), 'SQLite actually rejects missing FK parent.');
    }
    $_ENV['DB_TYPE'] = 'unsupported';
    fails(fn() => database_connect(), 'Unsupported driver rejected explicitly.');
    [$code, $out, $err] = command(['vayu', 'migrate'], ['DB_TYPE' => 'unsupported', 'DB_PASSWORD' => 'must-never-appear']);
    check($code !== 0 && !str_contains($err, 'must-never-appear'), 'Bootstrap failure is nonzero and secret-free.');

    [$code, $out, $err] = command(['vayu', 'developer:create', 'cli@example.test'], $env, "Disposable-CLI-Password-Only\n");
    check($code === 0 && $err === '', 'Existing developer:create works with shared CLI bootstrap.');
    $account = $pdo->query("SELECT password_hash FROM developer_accounts WHERE email = 'cli@example.test'")->fetchColumn();
    check(password_verify('Disposable-CLI-Password-Only', $account), 'Console password remains hashed.');
    [$code, $out, $err] = command(['vayu', '--help']);
    check($code === 0 && str_contains($out, 'migrate') && str_contains($out, 'run'), 'CLI help preserves run and documents migrate.');
    echo 'Foundations (' . ($mysql ? 'MySQL driver / ' : 'SQLite / ') . $version . "): {$checks} checks passed.\n";
} finally {
    putenv('FOUNDATION_FAIL');
    $pdo = null;
    if ($admin !== null) $admin->exec('DROP DATABASE ' . MigrationSchema::identifier($schema));
    removeDirectory($temp);
}
