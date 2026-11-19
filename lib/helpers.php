<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare(strict_types=1);

function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_valid(): bool
{
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    return is_string($sent) && hash_equals(csrf_token(), $sent);
}

function client_ip(): string
{
    if (cfg('trust_proxy')) {
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return trim((string) $_SERVER['HTTP_X_REAL_IP']);
        }
        // the last hop is the one the proxy itself saw; earlier ones are whatever the client sent
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $hops = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim((string) end($hops));
        }
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '?');
}

// A message the person can act on, as opposed to a bug: answered with a 400.
final class UserError extends RuntimeException
{
}

// ------------------------------------------------------------ the two languages

final class I18n
{
    public const LANGS = ['en' => 'EN', 'ro' => 'RO'];
    public const COOKIE = 'pullpost_lang';

    private static string $lang = 'en';
    private static array $strings = [];
    private static array $fallback = [];

    /**
     * The language of this request: the cookie set by the EN/RO buttons, else the one a trusted
     * reverse proxy names in X-Pullpost-Lang (for an address per language), else the browser's.
     */
    public static function init(): void
    {
        $pick = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (!isset(self::LANGS[$pick]) && cfg('trust_proxy')) {
            $pick = strtolower(trim((string) ($_SERVER['HTTP_X_PULLPOST_LANG'] ?? '')));
        }
        if (!isset(self::LANGS[$pick])) {
            $pick = 'en';
            foreach (explode(',', (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
                $tag = strtolower(substr(trim(explode(';', $part)[0]), 0, 2));
                if (isset(self::LANGS[$tag])) {
                    $pick = $tag;
                    break;
                }
            }
        }
        self::use($pick);
    }

    public static function use(string $lang): void
    {
        self::$lang = isset(self::LANGS[$lang]) ? $lang : 'en';
        self::$fallback = self::$fallback ?: require APP_ROOT . '/lang/en.php';
        self::$strings = self::$lang === 'en' ? self::$fallback : require APP_ROOT . '/lang/' . self::$lang . '.php';
    }

    /** Remembered for a year; the page that sets it is loaded again without ?lang=. */
    public static function remember(string $lang): void
    {
        if (!isset(self::LANGS[$lang])) {
            return;
        }
        setcookie(self::COOKIE, $lang, [
            'expires' => time() + 365 * 86400,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        self::use($lang);
    }

    public static function lang(): string
    {
        return self::$lang;
    }

    /** @return string|array */
    public static function raw(string $key)
    {
        return self::$strings[$key] ?? self::$fallback[$key] ?? $key;
    }

    public static function pluralForm(int $n): string
    {
        if ($n === 1) {
            return 'one';
        }
        if (self::$lang === 'ro') {
            $rest = $n % 100;
            return $n === 0 || ($rest >= 1 && $rest <= 19) ? 'few' : 'other';
        }
        return 'other';
    }
}

/** The text of $key in the page's language, with {name} replaced from $vars. */
function t(string $key, array $vars = []): string
{
    $s = I18n::raw($key);
    if (is_array($s)) {
        $s = (string) ($s['other'] ?? reset($s));
    }
    foreach ($vars as $k => $v) {
        $s = str_replace('{' . $k . '}', (string) $v, $s);
    }
    return $s;
}

/** A plural string: tn('n_photos', 3) is "3 photos", "3 poze". */
function tn(string $key, int $n, array $vars = []): string
{
    $forms = I18n::raw($key);
    $s = is_array($forms) ? ($forms[I18n::pluralForm($n)] ?? $forms['other'] ?? reset($forms)) : (string) $forms;
    foreach (['n' => $n] + $vars as $k => $v) {
        $s = str_replace('{' . $k . '}', (string) $v, $s);
    }
    return $s;
}

// ------------------------------------------------------------ dates, sizes, spans

/** "18 Nov 2026", "18 nov. 2026" */
function fmt_day(int $ts): string
{
    $months = I18n::raw('_months');
    return date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

/** "today, 01:14", "yesterday, 23:20", "4 Nov 2026, 10:40" */
function fmt_when(int $ts): string
{
    $clock = date('H:i', $ts);
    $day = date('Y-m-d', $ts);
    if ($day === date('Y-m-d')) {
        return t('today', ['t' => $clock]);
    }
    if ($day === date('Y-m-d', strtotime('-1 day'))) {
        return t('yesterday', ['t' => $clock]);
    }
    return t('day_time', ['d' => fmt_day($ts), 't' => $clock]);
}

/** A length of time: "4 s", "12 min", "1 h 5 min". */
function fmt_span(int $s): string
{
    $s = max(0, $s);
    if ($s < 60) {
        return $s . ' s';
    }
    if ($s < 3600) {
        return intdiv($s, 60) . ' min';
    }
    $m = intdiv($s % 3600, 60);
    return intdiv($s, 3600) . ' h' . ($m ? ' ' . $m . ' min' : '');
}

function fmt_decimal(float $x, int $places = 1): string
{
    return number_format($x, $places, (string) I18n::raw('_decimal'), '');
}

/** "7.1 MB", "304 KB": decimal units, as file managers show them. */
function size_text(int $bytes): string
{
    if ($bytes >= 1000000000) {
        return fmt_decimal($bytes / 1e9) . ' GB';
    }
    if ($bytes >= 1000000) {
        return fmt_decimal($bytes / 1e6) . ' MB';
    }
    return max(1, (int) round($bytes / 1000)) . ' KB';
}

function icon(string $name, string $class = ''): string
{
    return '<svg class="i' . ($class !== '' ? ' ' . e($class) : '') . '" aria-hidden="true"><use href="#i-' . e($name) . '"/></svg>';
}
