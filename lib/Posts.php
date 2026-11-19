<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare(strict_types=1);

// The history of posts: reading a pasted address, asking the engine for a post, folding the
// engine's result into the database, the files on disk, and the saved Instagram session.
final class Posts
{
    public const CODE_RE = '/^[A-Za-z0-9_-]{5,64}$/';
    private const HOSTS = ['instagram.com', 'www.instagram.com', 'm.instagram.com', 'instagr.am', 'www.instagr.am'];
    private const KINDS = ['p' => 'p', 'reel' => 'reel', 'reels' => 'reel', 'tv' => 'tv'];
    // first_n: the number of the post's first file left, for its picture in the lists
    private const LIST_SQL = 'SELECT p.*, (SELECT min(n) FROM files f WHERE f.code = p.code) AS first_n FROM posts p';

    /**
     * The post's shortcode and kind from what was pasted: a full address (with or without
     * ?img_index and the like, with or without the account name before /p/) or a bare shortcode.
     * @return array{code: string, kind: string}
     */
    public static function parse(string $input): array
    {
        $input = trim($input);
        if (preg_match(self::CODE_RE, $input)) {
            return ['code' => $input, 'kind' => 'p'];
        }
        if (!preg_match('#^https?://#i', $input)) {
            $input = 'https://' . $input;
        }
        $u = parse_url($input);
        $host = strtolower((string) ($u['host'] ?? ''));
        if (!in_array($host, self::HOSTS, true)) {
            throw new UserError(t('not_instagram'));
        }
        $parts = array_values(array_filter(explode('/', (string) ($u['path'] ?? ''))));
        foreach ($parts as $i => $seg) {
            $kind = self::KINDS[strtolower($seg)] ?? null;
            $code = $parts[$i + 1] ?? '';
            if ($kind !== null && preg_match(self::CODE_RE, $code)) {
                return ['code' => $code, 'kind' => $kind];
            }
        }
        throw new UserError(t('not_post'));
    }

    public static function get(string $code): ?array
    {
        return Db::one('SELECT * FROM posts WHERE code = ?', [$code]);
    }

    /** @return array<int, array> */
    public static function files(string $code): array
    {
        return Db::all('SELECT * FROM files WHERE code = ? ORDER BY n', [$code]);
    }

    /** @return array<int, array> newest first */
    public static function recent(int $limit = 8): array
    {
        return Db::all(self::LIST_SQL . ' ORDER BY requested_at DESC LIMIT ?', [$limit]);
    }

    /** @return array<int, array> */
    public static function all(): array
    {
        return Db::all(self::LIST_SQL . ' ORDER BY requested_at DESC');
    }

    /**
     * Asks the engine for the post. A post already in the history is not fetched again unless
     * $again: its result comes back as it is.
     * @return array{code: string, existing: bool}
     */
    public static function start(string $input, bool $again = false): array
    {
        $p = self::parse($input);
        $code = $p['code'];
        $row = self::get($code);
        $now = time();
        if ($row !== null && !$again && $row['state'] === 'done' && is_dir(self::dir($code))) {
            Db::run('UPDATE posts SET requested_at = ? WHERE code = ?', [$now, $code]);
            return ['code' => $code, 'existing' => true];
        }
        if ($row !== null && in_array($row['state'], ['queued', 'running'], true) && self::live(self::sync($code))) {
            return ['code' => $code, 'existing' => false];
        }
        // a bare code reads as /p/; asked again, a post keeps the kind and address it came with
        $kind = $row !== null && $again ? (string) $row['kind'] : $p['kind'];
        $url = $row !== null && $again ? (string) $row['url'] : trim($input);
        Db::run(
            'INSERT INTO posts (code, kind, url, state, created_at, requested_at) VALUES (?, ?, ?, \'queued\', ?, ?)
             ON CONFLICT(code) DO UPDATE SET kind = excluded.kind, url = excluded.url, state = \'queued\',
               error_kind = NULL, error = NULL, requested_at = excluded.requested_at',
            [$code, $kind, $url, $now, $now]
        );
        try {
            Engine::fetch($code, $kind);
        } catch (UserError $err) {
            // the card says so, with the button to try again
            self::fail($code, 'engine', $err->getMessage());
        }
        return ['code' => $code, 'existing' => false];
    }

    private static function live(?array $job): bool
    {
        return $job !== null && !in_array($job['state'] ?? '', ['done', 'error'], true);
    }

    /**
     * Brings the database up to what the engine last wrote for this post and returns that job
     * (null when the engine never wrote one).
     */
    public static function sync(string $code): ?array
    {
        $row = self::get($code);
        $job = Engine::job($code);
        if ($row === null || $job === null || !in_array($row['state'], ['queued', 'running'], true)) {
            return $job;
        }
        $state = (string) ($job['state'] ?? '');
        if ($state === 'done') {
            self::import($code);
        } elseif ($state === 'error') {
            self::fail($code, (string) ($job['error_kind'] ?? 'failed'), (string) ($job['error'] ?? ''));
        } elseif ($state !== 'queued' && $row['state'] === 'queued') {
            Db::run('UPDATE posts SET state = \'running\' WHERE code = ?', [$code]);
        }
        return $job;
    }

    /**
     * Marks the post as failed. When an earlier download of it is still on disk (a Fetch again that
     * did not work), the post keeps those files and the card shows the error above them.
     */
    private static function fail(string $code, string $kind, string $error): void
    {
        if (is_file(self::dir($code) . '/manifest.json')) {
            self::import($code);
            if ((self::get($code)['state'] ?? '') === 'done') {
                Db::run('UPDATE posts SET error_kind = ?, error = ? WHERE code = ?', [$kind, $error, $code]);
                return;
            }
        }
        Db::run('UPDATE posts SET state = \'error\', error_kind = ?, error = ? WHERE code = ?', [$kind, $error, $code]);
    }

    /** Reads data/posts/<code>/manifest.json into posts and files. */
    public static function import(string $code): void
    {
        $raw = @file_get_contents(self::dir($code) . '/manifest.json');
        $m = $raw === false ? null : json_decode($raw, true);
        if (!is_array($m) || !isset($m['files']) || !is_array($m['files'])) {
            Db::run('UPDATE posts SET state = \'error\', error_kind = \'failed\', error = ? WHERE code = ?',
                ['manifest.json is missing or unreadable', $code]);
            return;
        }
        Db::tx(function () use ($code, $m) {
            Db::run('DELETE FROM files WHERE code = ?', [$code]);
            $images = $videos = $bytes = 0;
            foreach ($m['files'] as $f) {
                $name = (string) ($f['name'] ?? '');
                if (!self::safeName($name)) {
                    continue;
                }
                $type = ($f['type'] ?? '') === 'video' ? 'video' : 'image';
                $thumb = isset($f['thumb']) && is_string($f['thumb']) && preg_match('#^thumbs/\d{2,3}\.jpg$#', $f['thumb']) ? $f['thumb'] : null;
                Db::run(
                    'INSERT INTO files (code, n, name, type, bytes, w, h, thumb) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$code, (int) $f['n'], $name, $type, (int) $f['bytes'], (int) ($f['w'] ?? 0), (int) ($f['h'] ?? 0), $thumb]
                );
                $type === 'video' ? $videos++ : $images++;
                $bytes += (int) $f['bytes'];
            }
            $zip = is_array($m['zip'] ?? null) && self::safeName((string) ($m['zip']['name'] ?? '')) ? $m['zip'] : null;
            Db::run(
                'UPDATE posts SET state = \'done\', error_kind = NULL, error = NULL, user = ?, taken_at = ?, mode = ?,
                   n_images = ?, n_videos = ?, bytes = ?, zip_name = ?, zip_bytes = ?, fetched_at = ? WHERE code = ?',
                [(string) ($m['user'] ?? ''), (int) ($m['taken_at'] ?? 0) ?: null, (string) ($m['mode'] ?? 'anon'),
                 $images, $videos, $bytes, $zip['name'] ?? null, isset($zip['bytes']) ? (int) $zip['bytes'] : null,
                 (int) ($m['fetched_at'] ?? time()), $code]
            );
        });
    }

    /** Removes the post from the history together with its files. */
    public static function delete(string $code): void
    {
        $row = self::get($code);
        if ($row === null) {
            throw new UserError(t('gone'));
        }
        if (in_array($row['state'], ['queued', 'running'], true) && self::live(Engine::job($code))) {
            throw new UserError(t('busy_delete'));
        }
        self::removeTree(self::dir($code));
        @unlink(data_path('jobs/' . $code . '.json'));
        Db::run('DELETE FROM posts WHERE code = ?', [$code]);
    }

    /**
     * Removes one file of a downloaded post: the file with its cover and small picture, its line in
     * manifest.json (which a failed Fetch again reads again), and the ZIP, made again from
     * the files left, or removed when one file is left. The last file goes only with the post.
     *
     * @return array the files row that was removed
     */
    public static function deleteFile(string $code, int $n): array
    {
        $row = self::get($code);
        if ($row === null) {
            throw new UserError(t('gone'));
        }
        if ($row['state'] !== 'done') {
            throw new UserError(t('busy_delete'));
        }
        $files = self::files($code);
        $gone = null;
        $left = [];
        foreach ($files as $f) {
            if ((int) $f['n'] === $n) {
                $gone = $f;
            } else {
                $left[] = $f;
            }
        }
        if ($gone === null) {
            throw new UserError(t('file_gone'));
        }
        if (!$left) {
            throw new UserError(t('last_file'));
        }

        $dir = self::dir($code);
        $zipName = (string) ($row['zip_name'] ?? '');
        $zipBytes = null;
        if ($zipName !== '' && count($left) > 1) {
            $entries = [];
            foreach ($left as $f) {
                $entries[$f['name']] = $dir . '/' . $f['name'];
            }
            try {
                $zipBytes = Zip::stored($dir . '/' . $zipName, $entries);
            } catch (Throwable $err) {
                error_log('zip ' . $code . ': ' . $err->getMessage());
                $zipBytes = null;
            }
        }
        if ($zipBytes === null && $zipName !== '') {
            @unlink($dir . '/' . $zipName);
            $zipName = '';
        }

        $raw = @file_get_contents($dir . '/manifest.json');
        $m = $raw === false ? null : json_decode($raw, true);
        if (is_array($m) && is_array($m['files'] ?? null)) {
            $m['files'] = array_values(array_filter($m['files'], fn ($f) => (int) ($f['n'] ?? 0) !== $n));
            $m['zip'] = $zipName !== '' ? ['name' => $zipName, 'bytes' => $zipBytes] : null;
            $tmp = $dir . '/manifest.json.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false
                || !@rename($tmp, $dir . '/manifest.json')) {
                @unlink($tmp);
                throw new UserError(t('manifest_write'));
            }
        }

        Db::tx(function () use ($code, $n, $left, $zipName, $zipBytes) {
            Db::run('DELETE FROM files WHERE code = ? AND n = ?', [$code, $n]);
            $images = $videos = $bytes = 0;
            foreach ($left as $f) {
                $f['type'] === 'video' ? $videos++ : $images++;
                $bytes += (int) $f['bytes'];
            }
            Db::run('UPDATE posts SET n_images = ?, n_videos = ?, bytes = ?, zip_name = ?, zip_bytes = ? WHERE code = ?',
                [$images, $videos, $bytes, $zipName !== '' ? $zipName : null, $zipBytes, $code]);
        });

        @unlink($dir . '/' . $gone['name']);
        if ($gone['thumb'] !== null) {
            @unlink($dir . '/' . $gone['thumb']);
        }
        @unlink(sprintf('%s/small/%02d.jpg', $dir, $n));
        return $gone;
    }

    public static function dir(string $code): string
    {
        return data_path('posts/' . $code);
    }

    /**
     * The path of one of the post's files (a download, the ZIP or a thumbnail), or null when it is
     * not one the database knows.
     */
    public static function filePath(string $code, string $which): ?string
    {
        if (!preg_match(self::CODE_RE, $code)) {
            return null;
        }
        $row = self::get($code);
        if ($row === null || $row['state'] !== 'done') {
            return null;
        }
        $rel = null;
        if ($which === 'zip') {
            $rel = $row['zip_name'];
        } elseif (preg_match('/^(f|t)(\d{1,3})$/', $which, $m)) {
            $f = Db::one('SELECT * FROM files WHERE code = ? AND n = ?', [$code, (int) $m[2]]);
            if ($f !== null) {
                $rel = $m[1] === 't' && $f['thumb'] !== null ? $f['thumb'] : $f['name'];
            }
        }
        if ($rel === null || $rel === '') {
            return null;
        }
        $path = self::dir($code) . '/' . $rel;
        return is_file($path) ? $path : null;
    }

    /**
     * A small JPEG of file n for the grids (320 px wide, made once with GD and kept next to the
     * files); the cover or the picture itself when GD cannot make one.
     */
    public static function smallPath(string $code, int $n): ?string
    {
        $src = self::filePath($code, 't' . $n);
        if ($src === null || !function_exists('imagecreatefromstring')) {
            return $src;
        }
        $dir = self::dir($code) . '/small';
        $out = sprintf('%s/%02d.jpg', $dir, $n);
        if (is_file($out) && filemtime($out) >= filemtime($src)) {
            return $out;
        }
        $img = @imagecreatefromstring((string) file_get_contents($src));
        if ($img === false) {
            return $src;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w <= 320) {
            return $src;
        }
        $small = imagescale($img, 320, max(1, (int) round($h * 320 / $w)), IMG_BICUBIC);
        if ($small === false) {
            return $src;
        }
        @mkdir($dir, 0777);
        $tmp = $out . '.' . getmypid() . '.tmp';
        if (!@imagejpeg($small, $tmp, 80) || !@rename($tmp, $out)) {
            @unlink($tmp);
            return $src;
        }
        return $out;
    }

    private static function safeName(string $name): bool
    {
        return $name !== '' && preg_match('/^[A-Za-z0-9._-]{1,200}$/', $name) === 1 && $name[0] !== '.';
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    /** @return array{count: int, files: int, bytes: int} */
    public static function totals(): array
    {
        $r = Db::one('SELECT count(*) AS c, coalesce(sum(n_images + n_videos), 0) AS f, coalesce(sum(bytes), 0) AS b FROM posts WHERE state = \'done\'');
        return ['count' => (int) $r['c'], 'files' => (int) $r['f'], 'bytes' => (int) $r['b']];
    }

    /* ------------------------------------------------------------ the Instagram session */

    private static function sessionFile(): string
    {
        return data_path('ig-session.json');
    }

    /** @return array{set: bool, saved_at: int} */
    public static function session(): array
    {
        $raw = @file_get_contents(self::sessionFile());
        $j = $raw === false ? null : json_decode($raw, true);
        $ok = is_array($j) && is_string($j['sessionid'] ?? null) && $j['sessionid'] !== '';
        return ['set' => $ok, 'saved_at' => $ok ? (int) ($j['saved_at'] ?? 0) : 0];
    }

    public static function saveSession(string $value): void
    {
        $value = trim($value);
        // what DevTools shows may be pasted with its quotes or as name=value
        $value = preg_replace('/^sessionid\s*[=:]\s*/i', '', $value);
        $value = trim((string) $value, " \t\n\r\0\x0B\"';");
        if (!preg_match('/^[A-Za-z0-9%:._-]{10,400}$/', $value)) {
            throw new UserError(t('bad_session'));
        }
        $tmp = self::sessionFile() . '.tmp';
        file_put_contents($tmp, json_encode(['sessionid' => $value, 'saved_at' => time()]));
        // the engine reads it, as the same user in the compose setup, else as another one
        @chmod($tmp, cfg('umask') === null ? 0600 : 0666 & ~(int) cfg('umask'));
        rename($tmp, self::sessionFile());
    }

    public static function clearSession(): void
    {
        @unlink(self::sessionFile());
    }
}
