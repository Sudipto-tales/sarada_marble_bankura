<?php

/** Explicit, argument-free spans. Monitoring must never change an application result. */
final class DeveloperTrace
{
    private static ?array $trace = null;
    private static array $stack = [];
    private static int $clock = 0;

    public static function begin(string $route, string $method, string $module): ?string
    {
        if (!defined('DEVELOPER_ENABLED') || !DEVELOPER_ENABLED || self::$trace !== null) return null;
        try {
            $id = bin2hex(random_bytes(16));
            self::$clock = hrtime(true);
            self::$stack = [];
            self::$trace = ['id'=>$id, 'route'=>$route, 'method'=>$method, 'module'=>$module,
                'started_at'=>time(), 'spans'=>[], 'truncated'=>false];
            if (!headers_sent()) header('X-Developer-Trace-Id: '.$id);
            return $id;
        } catch (Throwable $error) { return null; }
    }

    public static function span(string $symbol, callable $operation)
    {
        if (self::$trace === null) return $operation();
        if (count(self::$trace['spans']) >= 64) {
            self::$trace['truncated'] = true;
            return $operation();
        }
        $index = count(self::$trace['spans']);
        self::$trace['spans'][] = ['id'=>$index, 'parent'=>self::$stack ? end(self::$stack) : null,
            'symbol'=>substr($symbol, 0, 120), 'start_us'=>intdiv(hrtime(true)-self::$clock,1000),
            'duration_us'=>0, 'outcome'=>'ok'];
        self::$stack[] = $index;
        try { return $operation(); }
        catch (Throwable $error) {
            self::$trace['spans'][$index]['outcome'] = $error instanceof RequestRejected && $error->status < 500 ? 'rejected' : 'error';
            throw $error;
        } finally {
            self::$trace['spans'][$index]['duration_us'] = max(0, intdiv(hrtime(true)-self::$clock,1000)-self::$trace['spans'][$index]['start_us']);
            array_pop(self::$stack);
        }
    }

    public static function finish(?PDO $pdo, int $status): void
    {
        if (self::$trace === null) return;
        $trace = self::$trace;
        $trace['duration_us'] = max(0, intdiv(hrtime(true)-self::$clock,1000));
        $trace['status'] = $status;
        self::$trace = null; self::$stack = [];
        // Do not enlist telemetry in, commit, or roll back a caller's transaction.
        try {
            if (!$pdo || $pdo->inTransaction()) throw new RuntimeException();
            (new DeveloperKnowledge($pdo))->record($trace);
        } catch (Throwable $error) {
            self::fallback();
        }
    }

    private static function fallback(): void
    {
        try {
            $file = PrivateStorage::directory('monitoring').'/capture-status.log';
            $handle = @fopen($file, 'c+');
            if (!$handle) return;
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                if (fstat($handle)['size'] > 65536) ftruncate($handle, 0);
                fseek($handle, 0, SEEK_END);
                fwrite($handle, gmdate('c')." trace storage unavailable; observation dropped\n");
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        } catch (Throwable $ignored) {}
    }
}
