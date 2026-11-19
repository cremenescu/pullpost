<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Defaults, overridden by the environment (the compose file sets PULLPOST_*), then by
// config/config.local.php when it exists (outside git).
$env = static function (string $name, $default) {
    $v = getenv($name);
    return $v === false || $v === '' ? $default : $v;
};
$config = [
    'data_dir'             => $env('PULLPOST_DATA', dirname(__DIR__) . '/data'),
    'db_file'              => 'pullpost.sqlite',
    // The engine (engine/server.py); in the compose file the service named "engine".
    'engine_url'           => $env('PULLPOST_ENGINE_URL', 'http://127.0.0.1:8898'),
    'timezone'             => $env('TZ', 'UTC'),
    // The theme new browsers get until one is chosen in Settings: a or b.
    'theme'                => $env('PULLPOST_THEME', 'a'),
    'session_name'         => 'pullpost_sess',
    'session_days'         => 30,
    'login_max_fails'      => 8,
    'login_window_minutes' => 15,
    // null keeps the process umask. Where the web server and the engine run as different users
    // sharing the data folder, 0 lets each write the other's files.
    'umask'                => null,
    // Behind nginx: an internal location that serves the data folder, so nginx sends the files
    // itself (X-Accel-Redirect). Empty: PHP sends them.
    'accel_prefix'         => $env('PULLPOST_ACCEL_PREFIX', ''),
    // Where PHP writes its errors: a file under the data folder, or stderr (the container's log).
    'log'                  => $env('PULLPOST_LOG', 'logs/php.log'),
    // Behind a reverse proxy only: trust its X-Real-IP / X-Forwarded-For (login rate limit) and
    // X-Forwarded-Proto (Secure cookies).
    'trust_proxy'          => $env('PULLPOST_TRUST_PROXY', '0') === '1',
];
$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace($config, (array) require $local);
}
// A test points the application at another data folder with a file named by PULLPOST_CONFIG, read
// last. Only from the command line and PHP's own web server: never under Apache or nginx.
$extra = in_array(PHP_SAPI, ['cli', 'cli-server'], true) ? getenv('PULLPOST_CONFIG') : false;
if (is_string($extra) && $extra !== '' && is_file($extra)) {
    $config = array_replace($config, (array) require $extra);
}
return $config;
