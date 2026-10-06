<?php

/** One connection shared by requests, services and transaction-aware producers. */
function database_connect(): PDO
{
    $driver = strtolower((string) env('DB_TYPE', 'sqlite'));
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];

    if ($driver === 'sqlite') {
        $path = (string) env('DB_DATABASE', dirname(__DIR__) . '/database/database.sqlite');
        if ($path !== ':memory:' && !str_starts_with($path, '/')) {
            $path = dirname(__DIR__) . '/' . $path;
        }
        $connection = new PDO('sqlite:' . $path, null, null, $options);
        $connection->exec('PRAGMA foreign_keys = ON');
        $connection->exec('PRAGMA busy_timeout = 5000');
        return $connection;
    }

    if ($driver !== 'mysql') {
        throw new RuntimeException('Unsupported database driver. Use sqlite or mysql.');
    }
    $host = (string) env('DB_HOST', '127.0.0.1');
    $database = (string) env('DB_DATABASE', '');
    $port = (string) env('DB_PORT', '3306');
    if ($database === '' || $host === '' || preg_match('/[;\x00]/', $host . $database)
        || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('Invalid MySQL host, database or port configuration.');
    }
    $options[PDO::ATTR_EMULATE_PREPARES] = false;
    $connection = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        (string) env('DB_USERNAME', ''), (string) env('DB_PASSWORD', ''), $options);
    $connection->exec("SET time_zone = '+00:00'");
    $connection->exec('SET SESSION innodb_lock_wait_timeout = 3');
    return $connection;
}

$pdo = database_connect();

function db_query($sql, $params = [])
{
    global $pdo;
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function db_fetch_all($sql, $params = []) { return db_query($sql, $params)->fetchAll(); }
function db_fetch_one($sql, $params = []) { return db_query($sql, $params)->fetch(); }
function db_execute($sql, $params = []) { return db_query($sql, $params)->rowCount(); }
function db_last_insert_id() { global $pdo; return $pdo->lastInsertId(); }
