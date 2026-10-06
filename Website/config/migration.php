<?php

abstract class Migration
{
    protected PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }
    abstract public function up();
    public function down() {}
    public function verify(): void {}
}

/** Schema inspection for rerunnable DDL; identifiers come from migration code. */
final class MigrationSchema
{
    public static function identifier(string $name): string
    {
        if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $name)) {
            throw new RuntimeException('Invalid migration identifier.');
        }
        return chr(96) . $name . chr(96);
    }

    public static function columns(PDO $pdo, string $table): array
    {
        $quoted = self::identifier($table);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return array_column($pdo->query("PRAGMA table_xinfo({$quoted})")->fetchAll(PDO::FETCH_ASSOC), null, 'name');
        }
        return array_column($pdo->query("SHOW COLUMNS FROM {$quoted}")->fetchAll(PDO::FETCH_ASSOC), null, 'Field');
    }

    public static function indexes(PDO $pdo, string $table): array
    {
        $quoted = self::identifier($table);
        $indexes = [];
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach ($pdo->query("PRAGMA index_list({$quoted})")->fetchAll(PDO::FETCH_ASSOC) as $index) {
                $name = self::identifier($index['name']);
                $rows = $pdo->query("PRAGMA index_info({$name})")->fetchAll(PDO::FETCH_ASSOC);
                $indexes[$index['name']] = ['unique' => (bool) $index['unique'],
                    'columns' => array_column($rows, 'name'), 'partial' => (bool) $index['partial']];
            }
        } else {
            foreach ($pdo->query("SHOW INDEX FROM {$quoted}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $indexes[$row['Key_name']]['unique'] = !(bool) $row['Non_unique'];
                $indexes[$row['Key_name']]['columns'][(int) $row['Seq_in_index'] - 1] = $row['Column_name'];
                $indexes[$row['Key_name']]['partial'] = ($indexes[$row['Key_name']]['partial'] ?? false) || $row['Sub_part'] !== null;
            }
            foreach ($indexes as &$index) { ksort($index['columns']); $index['columns'] = array_values($index['columns']); }
            unset($index);
        }
        return $indexes;
    }

    public static function ensureIndex(PDO $pdo, string $table, string $name, array $columns, bool $unique = false): void
    {
        $expected = ['unique' => $unique, 'columns' => $columns, 'partial' => false];
        $indexes = self::indexes($pdo, $table);
        if (isset($indexes[$name])) {
            if ($indexes[$name] !== $expected) throw new RuntimeException("Incompatible index {$table}.{$name}.");
            return;
        }
        foreach ($indexes as $index) {
            if ($index === $expected) return;
        }
        $list = implode(', ', array_map([self::class, 'identifier'], $columns));
        $pdo->exec('CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . self::identifier($name)
            . ' ON ' . self::identifier($table) . " ({$list})");
    }

    public static function requireColumns(PDO $pdo, string $table, array $required): array
    {
        $columns = self::columns($pdo, $table);
        if (array_diff($required, array_keys($columns))) throw new RuntimeException("Incompatible columns in {$table}.");
        return $columns;
    }

    /** Explicit migration contracts, including type/nullability and relational ownership. */
    public static function verifyDefinition(PDO $pdo, string $table, array $definition, array $indexes = [], array $foreignKeys = []): void
    {
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $columns = self::requireColumns($pdo, $table, array_keys($definition));
        if (isset($definition['id'])) self::requireIntegerPrimaryKey($pdo, $table, $columns['id']);
        foreach ($definition as $name => $expected) {
            if ($name === 'id') continue;
            $nullable = str_starts_with($expected, '?');
            $type = ltrim($expected, '?');
            $actual = strtolower($sqlite ? $columns[$name]['type'] : $columns[$name]['Type']);
            $matches = in_array($type, ['int', 'bigint'], true)
                ? (bool) preg_match('/\A' . $type . '(?:\(\d+\))?\z/', $actual) : $actual === $type;
            if (!$matches || $nullable !== ($sqlite ? !(bool) $columns[$name]['notnull'] : $columns[$name]['Null'] === 'YES')) {
                throw new RuntimeException("Incompatible {$table} column definition.");
            }
        }
        $actualIndexes = self::indexes($pdo, $table);
        foreach ($indexes as [$names, $unique]) {
            if (!in_array(['unique' => $unique, 'columns' => $names, 'partial' => false], $actualIndexes, true)) {
                throw new RuntimeException("Missing {$table} index.");
            }
        }
        foreach ($foreignKeys as [$column, $parent, $parentColumn, $onDelete]) {
            if ($sqlite) {
                $keys = $pdo->query('PRAGMA foreign_key_list(' . self::identifier($table) . ')')->fetchAll(PDO::FETCH_ASSOC);
                $found = array_filter($keys, static fn(array $key): bool => $key['from'] === $column
                    && $key['table'] === $parent && $key['to'] === $parentColumn && $key['on_delete'] === $onDelete);
            } else {
                $query = $pdo->prepare('SELECT k.COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE k
                    JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
                    AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
                    WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.COLUMN_NAME = ?
                    AND k.REFERENCED_TABLE_NAME = ? AND k.REFERENCED_COLUMN_NAME = ? AND r.DELETE_RULE = ?');
                $query->execute([$table, $column, $parent, $parentColumn, $onDelete]);
                $found = $query->fetchAll();
            }
            if (!$found) throw new RuntimeException("Missing {$table} ownership constraint.");
        }
    }

    public static function requireIntegerPrimaryKey(PDO $pdo, string $table, array $column): void
    {
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        if ($sqlite ? (strtoupper($column['type']) !== 'INTEGER' || (int) $column['pk'] !== 1)
            : (!preg_match('/\Aint(?:\(\d+\))?\z/i', $column['Type']) || $column['Key'] !== 'PRI'
                || !str_contains($column['Extra'], 'auto_increment'))) {
            throw new RuntimeException("Incompatible integer primary key in {$table}.");
        }
        if ($sqlite) {
            $primary = array_filter(self::columns($pdo, $table), static fn(array $item): bool => (int) $item['pk'] > 0);
            if (array_keys($primary) !== ['id']) throw new RuntimeException('Composite identity keys are unsupported.');
        } elseif ((self::indexes($pdo, $table)['PRIMARY']['columns'] ?? []) !== ['id']) {
            throw new RuntimeException('Composite identity keys are unsupported.');
        }
        if (!$sqlite) {
            $statement = $pdo->prepare('SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $statement->execute([$table]);
            $info = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$info || strtoupper($info['ENGINE']) !== 'INNODB' || !str_starts_with($info['TABLE_COLLATION'], 'utf8mb4_')) {
                throw new RuntimeException("{$table} requires InnoDB and utf8mb4; repair explicitly before retrying.");
            }
        }
    }
}
