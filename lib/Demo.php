<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare(strict_types=1);

// The demo mode (PULLPOST_DEMO=1), for showing the app to anyone without it fetching anything:
// what a visitor changes stays only until the next reset, which brings the history back to the
// snapshot in data/demo-seed/ (made with bin/demo.php snapshot from an ordinary history). The look
// is kept per visitor, in a cookie, so one visitor does not change it for the others.
final class Demo
{
    public const THEME_COOKIE = 'pullpost_theme';
    public const SOURCE = 'https://github.com/cremenescu/pullpost';

    public static function on(): bool
    {
        return (bool) cfg('demo');
    }

    public static function password(): string
    {
        return (string) cfg('demo_password');
    }

    /** The page about the demo in the visitor's language, or '' when none is set. */
    public static function about(): string
    {
        return str_replace('{lang}', I18n::lang(), (string) cfg('demo_about'));
    }

    // ------------------------------------------------------------ the look, per visitor

    public static function theme(): ?string
    {
        $id = (string) ($_COOKIE[self::THEME_COOKIE] ?? '');
        return isset(Theme::NAMES[$id]) ? $id : null;
    }

    public static function rememberTheme(string $id): void
    {
        if (PHP_SAPI !== 'cli') {
            setcookie(self::THEME_COOKIE, $id, [
                'expires' => time() + 365 * 86400,
                'path' => '/',
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[self::THEME_COOKIE] = $id;
    }

    // ------------------------------------------------------------ the snapshot and the reset

    private static function period(): int
    {
        return max(5, (int) cfg('demo_reset_minutes')) * 60;
    }

    /** When the history goes back to the snapshot next. */
    public static function nextReset(int $now = 0): int
    {
        $now = $now ?: time();
        return (intdiv($now, self::period()) + 1) * self::period();
    }

    public static function seedDir(): string
    {
        $dir = rtrim((string) cfg('demo_seed'), '/');
        return $dir !== '' ? $dir : data_path('demo-seed');
    }

    public static function hasSeed(): bool
    {
        return is_file(self::seedDir() . '/db.json');
    }

    /**
     * Copies the history as it is now into $to (the snapshot's folder when empty): the rows of
     * posts and files, and the folders of the posts. Only finished posts are taken. Returns how many.
     */
    public static function snapshot(string $to = ''): int
    {
        $to = rtrim($to !== '' ? $to : self::seedDir(), '/');
        $posts = Db::all("SELECT * FROM posts WHERE state = 'done' ORDER BY requested_at");
        $codes = array_column($posts, 'code');
        $files = $codes ? Db::all('SELECT * FROM files WHERE code IN (' . implode(',', array_fill(0, count($codes), '?')) . ') ORDER BY code, n', $codes) : [];
        $new = $to . '.new-' . getmypid();
        self::removeTree($new);
        @mkdir($new . '/posts', 0777, true);
        foreach ($codes as $code) {
            self::copyTree(Posts::dir($code), $new . '/posts/' . $code, false);
        }
        $db = ['made_at' => time(), 'version' => APP_VERSION, 'posts' => $posts, 'files' => $files];
        file_put_contents($new . '/db.json', json_encode($db, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $old = $to . '.old-' . getmypid();
        if (is_dir($to)) {
            rename($to, $old);
        }
        rename($new, $to);
        self::removeTree($old);
        return count($posts);
    }

    /** Called by every page: resets once per period, in whichever request comes first. */
    public static function maybeReset(): void
    {
        if (!self::on() || !self::hasSeed()) {
            return;
        }
        $slot = (string) intdiv(time(), self::period());
        $mark = data_path('demo-reset');
        if (trim((string) @file_get_contents($mark)) === $slot) {
            return;
        }
        $lock = @fopen(data_path('demo.lock'), 'c');
        // another request is resetting: this one goes on with what is there
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return;
        }
        try {
            if (trim((string) @file_get_contents($mark)) !== $slot) {
                self::reset();
                file_put_contents($mark, $slot);
            }
        } catch (Throwable $err) {
            error_log('demo reset: ' . $err);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Puts the snapshot back: the post folders are swapped in whole (hard links to the snapshot's
     * files where the file system allows, copies where not; nothing in the app writes a file in
     * place, so the snapshot is never changed through a link), then the rows, in one transaction.
     */
    public static function reset(): void
    {
        $seed = json_decode((string) file_get_contents(self::seedDir() . '/db.json'), true);
        if (!is_array($seed) || !is_array($seed['posts'] ?? null)) {
            throw new RuntimeException('data/demo-seed/db.json cannot be read');
        }
        $tag = getmypid() . '-' . bin2hex(random_bytes(3));
        $new = data_path('posts.new-' . $tag);
        self::copyTree(self::seedDir() . '/posts', $new, true);
        $old = data_path('posts.old-' . $tag);
        rename(data_path('posts'), $old);
        rename($new, data_path('posts'));
        self::removeTree($old);
        foreach (glob(data_path('jobs/*.json')) ?: [] as $job) {
            @unlink($job);
        }
        @unlink(data_path('ig-session.json'));

        $cols = fn (string $table) => array_column(Db::all('PRAGMA table_info(' . $table . ')'), 'name');
        Db::tx(function () use ($seed, $cols) {
            Db::run('DELETE FROM files');
            Db::run('DELETE FROM posts');
            foreach (['posts' => $seed['posts'], 'files' => $seed['files'] ?? []] as $table => $rows) {
                $known = array_flip($cols($table));
                foreach ($rows as $row) {
                    $row = array_intersect_key((array) $row, $known);
                    Db::run('INSERT INTO ' . $table . ' (' . implode(', ', array_keys($row)) . ') VALUES ('
                        . implode(', ', array_fill(0, count($row), '?')) . ')', array_values($row));
                }
            }
        });
    }

    private static function copyTree(string $from, string $to, bool $link): void
    {
        @mkdir($to, 0777, true);
        if (!is_dir($from)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            $dest = $to . '/' . substr($f->getPathname(), strlen($from) + 1);
            if ($f->isLink()) {
                continue;
            }
            if ($f->isDir()) {
                @mkdir($dest, 0777, true);
            } elseif (!($link && @link($f->getPathname(), $dest)) && !copy($f->getPathname(), $dest)) {
                throw new RuntimeException('cannot copy ' . $f->getPathname());
            }
        }
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
