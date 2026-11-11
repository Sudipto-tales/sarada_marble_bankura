<?php

require_once __DIR__ . '/migration.php';

final class MigrationRunException extends RuntimeException {}

/** Serialize deployment DDL. This is deliberately separate from request bootstrap. */
final class MigrationRunner
{
    private PDO $pdo;
    private string $directory;
    private Closure $output;
    private int $lockWait;

    public function __construct(PDO $pdo, string $directory, ?callable $output = null, int $lockWait = 10)
    {
        $this->pdo = $pdo;
        $this->directory = $directory;
        $this->output = $output === null ? static function (string $line): void { echo $line . "\n"; }
            : Closure::fromCallable($output);
        if ($lockWait < 0 || $lockWait > 60) throw new InvalidArgumentException('Invalid migration lock wait.');
        $this->lockWait = $lockWait;
    }

    public function run(): int
    {
        if ($this->pdo->inTransaction()) throw new MigrationRunException('Migrations require a connection outside any transaction.');
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['sqlite', 'mysql'], true)) throw new MigrationRunException('Migrations require sqlite or mysql.');
        $release = $this->lock($driver);
        $current = 'migration registration';
        try {
            $migrations = $this->discover();
            $current = 'migration ledger';
            $this->ledger($driver);
            $ran = $this->pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
            $batch = (int) $this->pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations')->fetchColumn();
            $count = 0;
            foreach ($migrations as $name => $class) {
                if (in_array($name, $ran, true)) continue;
                $current = $name;
                ($this->output)('Running: ' . $name);
                // SQLite DDL is transactional; MySQL DDL needs explicit, rerunnable schema checks.
                if ($driver === 'sqlite') $this->pdo->beginTransaction();
                $migration = new $class($this->pdo);
                $migration->up();
                $migration->verify();
                if ($driver === 'mysql' && $this->pdo->inTransaction()) {
                    throw new MigrationRunException('A migration left a transaction open.');
                }
                $this->pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (?, ?)')->execute([$name, $batch]);
                if ($driver === 'sqlite') $this->pdo->commit();
                $ran[] = $name;
                $count++;
                ($this->output)('Migrated: ' . $name);
            }
            // Validate applied schemas too; forward repairs have already had a chance to run.
            foreach ($migrations as $name => $class) {
                $current = $name;
                (new $class($this->pdo))->verify();
            }
            ($this->output)($count === 0 ? 'No pending migrations.' : "Applied {$count} migration(s).");
            return $count;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            // Do not expose SQL errors, DSNs or row values on the command line.
            throw new MigrationRunException("Migration stopped at {$current}. No failed step was recorded. Inspect its schema and source, repair incompatibilities, then rerun php vayu migrate.", 0, $error);
        } finally {
            $release();
        }
    }

    private function discover(): array
    {
        $files = glob($this->directory . '/*.php');
        if ($files === false || !$files) throw new RuntimeException('No migration files found.');
        usort($files, static function (string $a, string $b): int {
            if ($a === $b) return 0;
            if (basename($a) === 'UsersTable.php') return -1;
            if (basename($b) === 'UsersTable.php') return 1;
            return strnatcmp(basename($a), basename($b));
        });
        $migrations = [];
        $classes = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if ($name !== 'UsersTable' && !preg_match('/\A[0-9]+_[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/', $name)) {
                throw new RuntimeException('Migration names require numeric ordering prefixes.');
            }
            $class = $name === 'UsersTable' ? $name : implode('', array_map('ucfirst', explode('_', preg_replace('/^[0-9]+_/', '', $name))));
            if (strlen($name) > 190 || isset($classes[strtolower($class)])) {
                throw new RuntimeException('Duplicate migration class or oversized identity.');
            }
            $classes[strtolower($class)] = true;
            $migrations[$name] = $class;
        }
        // Validate all classes before executing any migration or creating the ledger.
        foreach ($files as $file) {
            $class = $migrations[basename($file, '.php')];
            require_once $file;
            if (!class_exists($class, false) || !is_subclass_of($class, Migration::class)) {
                throw new RuntimeException('Missing migration class or Migration contract.');
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || realpath($reflection->getFileName()) !== realpath($file)) {
                throw new RuntimeException('Migration class belongs to a different file.');
            }
        }
        return $migrations;
    }

    private function ledger(string $driver): void
    {
        $ddl = $driver === 'mysql'
            ? 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(190) NOT NULL, batch BIGINT'
            : 'id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL, batch INTEGER';
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS migrations ({$ddl}){$suffix}");
        $columns = MigrationSchema::requireColumns($this->pdo, 'migrations', ['id', 'migration', 'batch']);
        MigrationSchema::requireIntegerPrimaryKey($this->pdo, 'migrations', $columns['id']);
        $identity = $columns['migration'];
        $batch = $columns['batch'];
        if ($driver === 'sqlite'
            ? (strtoupper($identity['type']) !== 'TEXT' || !$identity['notnull'] || strtoupper($batch['type']) !== 'INTEGER')
            : ($identity['Type'] !== 'varchar(190)' || $identity['Null'] !== 'NO'
                || !preg_match('/\A(?:bigint|int)(?:\(\d+\))?\z/i', $batch['Type']))) {
            throw new RuntimeException('Incompatible migration ledger column definitions.');
        }
        MigrationSchema::ensureIndex($this->pdo, 'migrations', 'idx_migrations_identity', ['migration'], true);
    }

    private function lock(string $driver): Closure
    {
        if ($driver === 'mysql') {
            $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
            $key = 'vayu:migrate:' . substr(hash('sha256', $database), 0, 48);
            $statement = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
            $statement->execute([$key, $this->lockWait]);
            if ((int) $statement->fetchColumn() !== 1) throw new MigrationRunException('Migration lock unavailable. Retry after the other runner finishes.');
            return function () use ($key): void {
                $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$key]);
            };
        }
        $databases = $this->pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);
        $path = '';
        foreach ($databases as $database) if ($database['name'] === 'main') $path = $database['file'];
        $lockPath = $path !== '' ? (realpath($path) ?: $path) . '.migrate.lock'
            : sys_get_temp_dir() . '/vayu-memory-migrate-' . hash('sha256', realpath($this->directory) ?: $this->directory) . '.lock';
        $handle = fopen($lockPath, 'c');
        if ($handle === false) throw new MigrationRunException('Migration lock path is not writable.');
        $deadline = microtime(true) + $this->lockWait;
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return static function () use ($handle): void { flock($handle, LOCK_UN); fclose($handle); };
            }
            if (microtime(true) >= $deadline) break;
            usleep(50000);
        } while (true);
        fclose($handle);
        throw new MigrationRunException('Migration lock unavailable. Retry after the other runner finishes.');
    }
}
