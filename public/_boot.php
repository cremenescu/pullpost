<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Every page starts here: the application, the session, the language, the security headers.
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';

header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

// The demo goes back to its snapshot once per period, and stays out of search engines.
if (Demo::on()) {
    Demo::maybeReset();
    header('X-Robots-Tag: noindex, nofollow');
}

Auth::startSession();

// The EN / RO buttons: remember the choice and come back to the same page without ?lang=.
if (isset($_GET['lang']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    I18n::remember((string) $_GET['lang']);
    $q = $_GET;
    unset($q['lang']);
    redirect(basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php')) . ($q ? '?' . http_build_query($q) : ''));
}
