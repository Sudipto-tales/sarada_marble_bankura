<?php
// CLI only, called from private staging; never emit credentials or PDO exception text.
if (PHP_SAPI !== 'cli') { exit(1); }
try {
    $root = $argv[1];
    require $root . '/vendor/autoload.php';
    $config = Dotenv\Dotenv::parse(file_get_contents($root . '/.env'));
    $lines = ["[client]"];
    foreach (['host'=>'DB_HOST', 'port'=>'DB_PORT', 'user'=>'DB_USERNAME', 'password'=>'DB_PASSWORD'] as $option=>$name) {
        $value = $config[$name] ?? '';
        if (preg_match('/[\x00-\x1f\x7f]/', $value)) { throw new RuntimeException(); }
        $lines[] = $option . '="' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
    if (!preg_match('/\A[A-Za-z0-9_]+\z/', $config['DB_DATABASE'] ?? '')) { throw new RuntimeException(); }
    if (file_put_contents($argv[2], implode("\n", $lines) . "\n") === false || !chmod($argv[2], 0600)) { throw new RuntimeException(); }
    // Database name is validated and is not a password.
    echo $config['DB_DATABASE'];
} catch (Throwable $error) {
    fwrite(STDERR, "Database backup configuration failed; inspect private configuration.\n");
    exit(1);
}
