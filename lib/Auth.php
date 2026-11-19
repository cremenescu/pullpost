<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare(strict_types=1);

// Single user: one bcrypt hash in meta. auth_epoch signs out other browsers when the password
// changes. Until a password is chosen, choosing it needs the setup code, which only the person
// who can read the server's log (or run bin/setup-code.php) has.
final class Auth
{
    public const MIN_PASSWORD = 8;
    private const CODE_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name(cfg('session_name'));
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        session_set_cookie_params([
            'lifetime' => (int) cfg('session_days') * 86400,
            'path' => $dir === '' ? '/' : $dir . '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.gc_maxlifetime', (string) ((int) cfg('session_days') * 86400));
        ini_set('session.save_path', data_path('tmp'));
        session_start();
    }

    private static function hash(): string
    {
        return (string) Db::meta('password_hash');
    }

    public static function configured(): bool
    {
        return self::hash() !== '';
    }

    public static function check(): bool
    {
        return !empty($_SESSION['uid']) && ($_SESSION['epoch'] ?? '0') === (Db::meta('auth_epoch') ?: '0');
    }

    public static function tooManyFails(): bool
    {
        $n = (int) Db::value(
            'SELECT count(*) FROM login_attempts WHERE ip = ? AND ok = 0 AND at > ?',
            [client_ip(), time() - (int) cfg('login_window_minutes') * 60]
        );
        return $n >= (int) cfg('login_max_fails');
    }

    private static function note(bool $ok): void
    {
        Db::run('INSERT INTO login_attempts (ip, ok, at) VALUES (?, ?, ?)', [client_ip(), $ok ? 1 : 0, time()]);
        Db::run('DELETE FROM login_attempts WHERE at < ?', [time() - 30 * 86400]);
    }

    public static function attempt(string $password): bool
    {
        $ok = self::configured() && password_verify($password, self::hash());
        self::note($ok);
        if ($ok) {
            self::signIn();
        }
        return $ok;
    }

    private static function signIn(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['uid'] = 1;
        $_SESSION['epoch'] = Db::meta('auth_epoch') ?: '0';
    }

    // ------------------------------------------------------------ the first run

    private static function codeFile(): string
    {
        return data_path('setup-code');
    }

    /**
     * The setup code, made the first time it is needed and kept until the password is chosen.
     * $announce writes it to stderr, which is the container's log.
     */
    public static function setupCode(bool $announce = false): ?string
    {
        if (self::configured()) {
            @unlink(self::codeFile());
            return null;
        }
        $code = trim((string) @file_get_contents(self::codeFile()));
        if ($code === '') {
            $chars = self::CODE_CHARS;
            for ($i = 0; $i < 8; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $code = substr($code, 0, 4) . '-' . substr($code, 4);
            $old = umask(0077);
            file_put_contents(self::codeFile(), $code . "\n");
            umask($old);
            $announce = true;
        }
        if ($announce) {
            file_put_contents('php://stderr', 'pullpost: setup code for the first sign-in: ' . $code . "\n");
        }
        return $code;
    }

    private static function sameCode(string $given, string $code): bool
    {
        $norm = fn (string $s) => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $s));
        return $code !== '' && hash_equals($norm($code), $norm($given));
    }

    /**
     * The first password, chosen with the setup code and after the notice was read. Returns what
     * is wrong, or null when it was taken; the browser is then signed in. The insert can succeed
     * only once, so two browsers on this page at the same time cannot both set it.
     */
    public static function choose(string $code, string $password, string $again, bool $ack): ?string
    {
        if (self::configured()) {
            return t('pw_set_already');
        }
        $ok = self::sameCode($code, (string) self::setupCode());
        self::note($ok);
        if (!$ok) {
            return t('setup_wrong');
        }
        if (!$ack) {
            return t('ack_needed');
        }
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            return t('pw_short', ['n' => self::MIN_PASSWORD]);
        }
        if ($password !== $again) {
            return t('pw_mismatch');
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        if (Db::run('INSERT OR IGNORE INTO meta (k, v) VALUES (?, ?)', ['password_hash', $hash]) !== 1) {
            return t('pw_set_already');
        }
        Db::meta('password_at', (string) time());
        Db::meta('notice_ack_at', (string) time());
        Db::meta('auth_epoch', bin2hex(random_bytes(8)));
        @unlink(self::codeFile());
        self::signIn();
        return null;
    }

    /** From bin/set-password.php: no setup code, every browser signed out. */
    public static function setPassword(string $password): void
    {
        Db::meta('password_hash', password_hash($password, PASSWORD_BCRYPT));
        Db::meta('auth_epoch', bin2hex(random_bytes(8)));
        Db::meta('password_at', (string) time());
        @unlink(self::codeFile());
    }

    /** Settings: the other browsers are signed out, this one stays in. Returns what is wrong, or null. */
    public static function change(string $current, string $password, string $again): ?string
    {
        if (!password_verify($current, self::hash())) {
            return t('pw_wrong_now');
        }
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            return t('pw_short', ['n' => self::MIN_PASSWORD]);
        }
        if ($password !== $again) {
            return t('pw_mismatch');
        }
        self::setPassword($password);
        $_SESSION['epoch'] = Db::meta('auth_epoch');
        return null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }
}
