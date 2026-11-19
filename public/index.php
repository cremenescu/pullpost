<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// The pages Fetch, History and Settings (?p=...), and the actions of their forms and buttons
// (POST, act=..., JSON back).
declare(strict_types=1);
require __DIR__ . '/_boot.php';

if (!Auth::check()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        json_out(['error' => t('session_expired')], 401);
    }
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        json_out(['error' => t('page_expired')], 403);
    }
    // the password change writes the session; everything else only reads it
    if (($_POST['act'] ?? '') !== 'password') {
        session_write_close();
    }
    try {
        json_out(Actions::handle((string) ($_POST['act'] ?? ''), $_POST));
    } catch (UserError $e) {
        json_out(['error' => $e->getMessage()], 400);
    } catch (Throwable $e) {
        error_log('index.php act: ' . $e);
        json_out(['error' => t('internal')], 500);
    }
}

csrf_token();
session_write_close();
$page = isset(View::PAGES[$_GET['p'] ?? '']) ? (string) $_GET['p'] : 'fetch';
$current = null;
if ($page === 'fetch' && isset($_GET['c']) && preg_match(Posts::CODE_RE, (string) $_GET['c']) && Posts::get((string) $_GET['c']) !== null) {
    $current = (string) $_GET['c'];
}
header('Cache-Control: no-store');
$theme = Theme::current();
View::head(t(View::PAGES[$page]) . ' · pullpost', 'p-' . $page);
$theme->shellStart($page);
if ($page === 'fetch') {
    $theme->fetchPage($current);
} else {
    require APP_ROOT . '/lib/pages/' . $page . '.php';
}
$theme->shellEnd();
