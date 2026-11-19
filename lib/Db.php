<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare(strict_types=1);

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::open(data_path((string) cfg('db_file')));
            self::migrate(self::$pdo);
        }
        return self::$pdo;
    }

    // Used by the smoke test, which switches to a throwaway data dir after bootstrap.
    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function open(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        // SQLite creates its file as 0644 whatever the umask; where the web server and the CLI
        // scripts run as different users, config sets a umask that lets both write.
        if (cfg('umask') !== null) {
            @chmod($path, 0666 & ~(int) cfg('umask'));
        }
        return $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
        $done = array_flip($pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
        $files = glob(__DIR__ . '/migrations/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            if (isset($done[$name])) {
                continue;
            }
            $pdo->beginTransaction();
            try {
                $pdo->exec((string) file_get_contents($file));
                $st = $pdo->prepare('INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)');
                $st->execute([$name, date('c')]);
                $pdo->commit();
            } catch (Throwable $err) {
                $pdo->rollBack();
                throw $err;
            }
        }
    }

    public static function all(string $sql, array $args = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $args = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($args);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $args = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($args);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function run(string $sql, array $args = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($args);
        return $st->rowCount();
    }

    public static function insert(string $sql, array $args = []): int
    {
        self::run($sql, $args);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Runs $fn inside BEGIN IMMEDIATE, so a read-modify-write of a meta value cannot interleave with
     * another process doing the same (the wake loop runs beside the web requests).
     */
    public static function tx(callable $fn)
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $out = $fn();
            $pdo->exec('COMMIT');
            return $out;
        } catch (Throwable $err) {
            $pdo->exec('ROLLBACK');
            throw $err;
        }
    }

    public static function meta(string $key, ?string $set = null): ?string
    {
        if ($set !== null) {
            self::run('INSERT INTO meta (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v', [$key, $set]);
            return $set;
        }
        $v = self::value('SELECT v FROM meta WHERE k = ?', [$key]);
        return $v === null ? null : (string) $v;
    }

    // Tells open pages that something changed, so they draw it again.
    public static function bump(): void
    {
        self::meta('rev', (string) (((int) self::meta('rev')) + 1));
    }
}
