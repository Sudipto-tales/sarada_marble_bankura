<?php

require_once __DIR__ . '/../config/env.php';

/** Runtime secrets stay outside the Website document root, including symlink targets. */
final class PrivateStorage
{
    public static function directory(string $area): string
    {
        if (!preg_match('/\A[a-z][a-z0-9-]{0,40}\z/', $area)) {
            throw new InvalidArgumentException('Invalid private storage area.');
        }
        $root = (string) env('PRIVATE_STORAGE_PATH', dirname(__DIR__, 2) . '/var');
        if (!str_starts_with($root, '/') || str_contains($root, "\0")) {
            throw new RuntimeException('Private storage requires an absolute path.');
        }
        $parts = [];
        foreach (explode('/', $root) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') array_pop($parts); else $parts[] = $part;
        }
        $root = '/' . implode('/', $parts);
        $ancestor = $root;
        $missing = [];
        while (!file_exists($ancestor) && !is_link($ancestor)) {
            array_unshift($missing, basename($ancestor));
            $ancestor = dirname($ancestor);
        }
        $resolved = realpath($ancestor);
        if ($resolved === false) throw new RuntimeException('Private storage is unavailable.');
        $root = rtrim($resolved, '/') . ($missing ? '/' . implode('/', $missing) : '');
        $public = realpath(dirname(__DIR__));
        if ($root === '' || $root === '/' || $public === false || $root === $public || str_starts_with($root, $public . '/')) {
            throw new RuntimeException('Private storage must be outside the document root.');
        }
        self::create($root);
        $path = $root . '/' . $area;
        if (is_link($path)) throw new RuntimeException('Private storage symlinks are unsupported.');
        self::create($path);
        return $path;
    }

    private static function create(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Private storage is unavailable.');
        }
        if (!@chmod($path, 0700) || !is_writable($path)) {
            throw new RuntimeException('Private storage is unavailable.');
        }
    }
}
