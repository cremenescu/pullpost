<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare(strict_types=1);

// Single entry point for every request and CLI script: config, time zone, error log, language,
// classes. APP_ROOT is the folder holding lib/, config/, lang/ and bin/.
define('APP_ROOT', dirname(__DIR__));
const APP_VERSION = '0.1.1';

$GLOBALS['config'] = require APP_ROOT . '/config/config.php';

function cfg(string $key, $default = null)
{
    return $GLOBALS['config'][$key] ?? $default;
}

if (!@date_default_timezone_set((string) cfg('timezone'))) {
    date_default_timezone_set('UTC');
}
mb_internal_encoding('UTF-8');
// Behind a reverse proxy that ends TLS, the proxy says the visitor came over HTTPS, and the cookies
// are then marked Secure. Only with PULLPOST_TRUST_PROXY=1: anyone can send the header otherwise.
if (cfg('trust_proxy') && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
if (cfg('umask') !== null) {
    umask((int) cfg('umask'));
}

function data_path(string $sub = ''): string
{
    $dir = rtrim((string) cfg('data_dir'), '/');
    return $sub === '' ? $dir : $dir . '/' . ltrim($sub, '/');
}

function prepare_data_dir(): void
{
    foreach (['', 'logs', 'tmp', 'jobs', 'posts'] as $sub) {
        if (!is_dir(data_path($sub))) {
            @mkdir(data_path($sub), 0777, true);
        }
    }
    ini_set('log_errors', '1');
    $log = (string) cfg('log');
    if ($log !== '' && $log !== 'stderr') {
        ini_set('error_log', data_path($log));
    }
}

prepare_data_dir();
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
}

require __DIR__ . '/helpers.php';
require __DIR__ . '/Db.php';
require __DIR__ . '/Auth.php';
require __DIR__ . '/Engine.php';
require __DIR__ . '/Zip.php';
require __DIR__ . '/Posts.php';
require __DIR__ . '/Card.php';
require __DIR__ . '/Theme.php';
require __DIR__ . '/View.php';
require __DIR__ . '/Actions.php';
require __DIR__ . '/Demo.php';

I18n::init();
