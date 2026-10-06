<?php

/** Prepared statements and bounded whole-transaction retry on the caller's PDO. */
final class CommerceDatabase
{
    public static function query(PDO $pdo, string $sql, array $parameters = []): PDOStatement
    {
        $query = $pdo->prepare($sql); $query->execute($parameters); return $query;
    }
    public static function lock(PDO $pdo): string { return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''; }
    public static function clock(PDO $pdo): int
    {
        return (int)$pdo->query($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'SELECT UNIX_TIMESTAMP(CURRENT_TIMESTAMP)' : "SELECT CAST(strftime('%s','now') AS INTEGER)")->fetchColumn();
    }
    public static function transaction(PDO $pdo, callable $operation)
    {
        if ($pdo->inTransaction()) throw new LogicException('Top-level commerce operation requires its own transaction.');
        for ($attempt=0; $attempt<3; $attempt++) {
            try {
                $pdo->beginTransaction();
                // SQLite must acquire its write lock before any authoritative read.
                // MySQL uses domain row locks, never this global SQLite fallback.
                if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                    && $pdo->exec('UPDATE commerce_write_lock SET revision = revision WHERE id = 1') !== 1) {
                    throw new RuntimeException('Commerce write lock is unavailable.');
                }
                $result = $operation($pdo);
                $pdo->commit();
                return $result;
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $code = $error instanceof PDOException ? ($error->errorInfo[1] ?? null) : null;
                $retry = $error instanceof PDOException && ($error->getCode() === '40001' || in_array($code,[5,6,1205,1213],true));
                if (!$retry || $attempt === 2) throw $error;
                usleep(random_int(10000,50000)*($attempt+1));
            }
        }
    }
    public static function uniqueConflict(Throwable $error): bool
    {
        return $error instanceof PDOException && (int)($error->errorInfo[1] ?? 0) === 1062
            || ($error instanceof PDOException && in_array((int)($error->errorInfo[1] ?? 0),[19,2067],true)
                && str_contains(strtolower($error->getMessage()),'unique'));
    }
}
