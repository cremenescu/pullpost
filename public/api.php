<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// What the page polls while the engine works (GET, JSON):
//   api.php?a=card&c=<code>  -> {state, live, html, status}   the post's card, drawn again
//   api.php?a=health         -> the engine's /health answer, or {ok: false}
declare(strict_types=1);
require __DIR__ . '/_boot.php';

if (!Auth::check()) {
    json_out(['error' => t('session_expired')], 401);
}
session_write_close();
try {
    switch ((string) ($_GET['a'] ?? '')) {
        case 'card':
            $code = (string) ($_GET['c'] ?? '');
            if (!preg_match(Posts::CODE_RE, $code)) {
                json_out(['error' => 'bad code'], 400);
            }
            json_out(Card::render($code));
        case 'health':
            json_out(Engine::health() ?? ['ok' => false]);
    }
    json_out(['error' => 'unknown a'], 400);
} catch (Throwable $e) {
    error_log('api.php: ' . $e);
    json_out(['error' => t('internal')], 500);
}
