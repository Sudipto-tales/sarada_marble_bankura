<?php
// Shared disposable resources for meaningful service, migration and concurrency checks.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
final class CommerceTest
{
    public static int $checks = 0;
    public static string $temp;
    public static string $version;
    public static array $environment;
    private static ?PDO $admin = null;
    private static string $schema;

    public static function open(): PDO
    {
        global $pdo, $argv;
        self::$temp = sys_get_temp_dir() . '/sarada-commerce-test-' . bin2hex(random_bytes(8));
        mkdir(self::$temp, 0700);
        self::$schema = 'sarada_commerce_test_' . bin2hex(random_bytes(8));
        self::$environment = ['APP_ENV' => 'development', 'APP_URL' => 'http://localhost/shop',
            'PRIVATE_STORAGE_PATH' => self::$temp . '/private', 'MAIL_TRANSPORT' => 'disabled',
            'AUTH_REGISTRATION_ENABLED' => 'false', 'DB_TYPE' => 'sqlite', 'DB_DATABASE' => self::$temp . '/test.sqlite'];
        if (in_array('--mysql', $argv, true)) {
            $host = getenv('TEST_MYSQL_HOST') ?: '127.0.0.1'; $port = getenv('TEST_MYSQL_PORT') ?: '3306';
            if (preg_match('/[;\x00]/', $host) || !ctype_digit($port)) throw new RuntimeException('Invalid test target.');
            $user = getenv('TEST_MYSQL_USER') ?: 'root'; $password = getenv('TEST_MYSQL_PASSWORD') ?: '';
            self::$admin = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            self::$admin->exec('CREATE DATABASE `' . self::$schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            self::$environment += ['DB_HOST' => $host, 'DB_PORT' => $port, 'DB_USERNAME' => $user, 'DB_PASSWORD' => $password];
            self::$environment['DB_TYPE'] = 'mysql'; self::$environment['DB_DATABASE'] = self::$schema;
        }
        foreach (self::$environment as $name => $value) { $_ENV[$name] = $value; putenv($name . '=' . $value); }
        require dirname(__DIR__, 2) . '/config/bootstarp.php';
        require dirname(__DIR__, 2) . '/config/migrate.php';
        (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/database/migrations', static function ($line) {}))->run();
        self::$version = $pdo->query(self::$admin ? 'SELECT VERSION()' : 'SELECT sqlite_version()')->fetchColumn();
        register_shutdown_function([self::class, 'close']);
        return $pdo;
    }

    public static function check(bool $condition, string $message): void
    {
        if (!$condition) throw new RuntimeException($message);
        self::$checks++;
    }

    public static function rejects(callable $call, int $status, string $message): void
    {
        try { $call(); } catch (RequestRejected $e) { self::check($e->status === $status, $message); return; }
        throw new RuntimeException($message);
    }

    public static function fails(callable $call, string $message): void
    {
        try { $call(); } catch (Throwable $e) { self::check(true, $message); return; }
        throw new RuntimeException($message);
    }

    public static function command(array $args, string $input = '', array $environment = []): array
    {
        $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']],
            $pipes, dirname(__DIR__, 2), array_merge(getenv(), self::$environment, $environment));
        if (!is_resource($process)) throw new RuntimeException('Unable to start test command.');
        fwrite($pipes[0], $input); fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        return [proc_close($process), $out, $err];
    }

    public static function user(string $email): int
    {
        global $pdo;
        $pdo->prepare("INSERT INTO users_tbl (firstname, lastname, name, email, password, email_verify, status)
            VALUES ('Test', 'Customer', 'Disposable Test Customer', ?, ?, 1, 1)")
            ->execute([$email, password_hash('Disposable-Test-Customer-Password', PASSWORD_DEFAULT)]);
        return (int) $pdo->lastInsertId();
    }

    /** Independent processes start together; never treat sequential SQLite checks as MySQL race evidence. */
    public static function race(array $requests, string $fixture): array
    {
        $barrier=self::$temp.'/barrier-'.bin2hex(random_bytes(8)); $children=[];
        try {
            foreach($requests as $n=>$input) {
                $ready=$barrier.'-ready-'.$n;
                $process=proc_open([PHP_BINARY,$fixture],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,
                    dirname(__DIR__,2),array_merge(getenv(),self::$environment,['TEST_BARRIER'=>$barrier,'TEST_READY'=>$ready]));
                if(!is_resource($process)) throw new RuntimeException('Unable to start race process.');
                fwrite($pipes[0],json_encode($input)); fclose($pipes[0]); $children[]=[$process,$pipes,$ready];
            }
            $deadline=microtime(true)+8;
            do {
                $ready=true; foreach($children as $child) $ready=is_file($child[2])&&$ready;
                if($ready) break;
                if(microtime(true)>$deadline) throw new RuntimeException('Race processes did not reach barrier.');
                usleep(10000);
            } while(true);
            file_put_contents($barrier,'go'); $results=[];
            foreach($children as [$process,$pipes,$ready]) {
                $out=stream_get_contents($pipes[1]); fclose($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[2]);
                self::check(proc_close($process)===0 && $err==='','Independent race process completes safely.');
                $results[]=json_decode($out,true,16,JSON_THROW_ON_ERROR);
            }
            $children=[]; return $results;
        } finally {
            foreach($children as [$process,$pipes,$ready]) if(is_resource($process)) { proc_terminate($process); proc_close($process); }
        }
    }

    public static function close(): void
    {
        global $pdo;
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        $pdo = null;
        if (self::$admin) { self::$admin->exec('DROP DATABASE IF EXISTS `' . self::$schema . '`'); self::$admin = null; }
        if (isset(self::$temp) && is_dir(self::$temp)) self::remove(self::$temp);
    }

    private static function remove(string $path): void
    {
        foreach (scandir($path) as $name) {
            if ($name === '.' || $name === '..') continue;
            $file = $path . '/' . $name;
            if (is_dir($file) && !is_link($file)) self::remove($file); else unlink($file);
        }
        rmdir($path);
    }

    public static function finish(string $name): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        echo "$name (" . self::$environment['DB_TYPE'] . ' / ' . self::$version . '): ' . self::$checks . " checks passed.\n";
    }
}
