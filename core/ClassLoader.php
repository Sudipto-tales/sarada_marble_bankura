<?php

/** Only trusted, explicitly registered paths can load application classes. */
final class ClassLoader
{
    private static array $classes = [];
    private static bool $installed = false;

    public static function register(array $map, string $baseDirectory): void
    {
        $base = realpath($baseDirectory);
        if ($base === false) throw new LogicException('Class-map root does not exist.');
        $pending = [];
        foreach ($map as $class => $relativePath) {
            if (!is_string($class) || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $class)
                || !is_string($relativePath)) throw new LogicException('Invalid application class mapping.');
            $key = strtolower($class);
            $file = realpath($base . '/' . $relativePath);
            if ($file === false || !is_file($file) || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)
                || pathinfo($file, PATHINFO_EXTENSION) !== 'php') throw new LogicException('Invalid application class file.');
            if (isset(self::$classes[$key]) || isset($pending[$key])) {
                throw new LogicException('Duplicate application class name.');
            }
            if ((class_exists($class, false) || interface_exists($class, false))
                && (new ReflectionClass($class))->getFileName() !== $file) {
                throw new LogicException('Application class name is already defined elsewhere.');
            }
            $pending[$key] = ['class' => $class, 'file' => $file];
        }
        self::$classes += $pending;
        if (!self::$installed) {
            spl_autoload_register([self::class, 'load']);
            self::$installed = true;
        }
    }

    public static function load(string $class): void
    {
        $mapping = self::$classes[strtolower($class)] ?? null;
        if ($mapping === null) return;
        require_once $mapping['file'];
        if (!class_exists($mapping['class'], false) && !interface_exists($mapping['class'], false)) {
            throw new LogicException('Registered file did not define its application class.');
        }
        $reflection = new ReflectionClass($mapping['class']);
        if (realpath($reflection->getFileName()) !== $mapping['file']) {
            throw new LogicException('Application class was defined by another file.');
        }
    }
}
