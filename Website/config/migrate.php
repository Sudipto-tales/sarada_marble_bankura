<?php
define('_BASEDIR_', $base_url);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/migration.php';


$pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    migration TEXT NOT NULL,
    batch INTEGER
)");

$stmt = $pdo->query("SELECT migration FROM migrations");
$ran = array_column($stmt->fetchAll(), 'migration');

$migrationFiles = glob(__DIR__ . '/../database/migrations/*.php');
$batch = time();

foreach ($migrationFiles as $file) {
    $filename = basename($file, '.php');
    if (in_array($filename, $ran)) continue;

    require_once $file;

    $className = implode('', array_map('ucfirst', explode('_', preg_replace('/^\d+_/', '', $filename))));
    if (!class_exists($className)) {
        echo "Class $className not found in $filename\n";
        continue;
    }

    echo "Running: $filename...\n";
    $migration = new $className($pdo);
    $migration->up();

    $stmt = $pdo->prepare("INSERT INTO migrations (migration, batch) VALUES (?, ?)");
    $stmt->execute([$filename, $batch]);
    echo "Migrated: $filename\n";
}
