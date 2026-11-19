<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// The files of a post: file.php?c=<code>&f=<which>, where which is zip, f<n> (the file itself,
// as a download; with v=1 shown in the browser) or s<n> (the small picture for the grids).
// Only files the database knows are served, and only to a signed-in browser.
declare(strict_types=1);
require __DIR__ . '/_boot.php';

if (!Auth::check()) {
    http_response_code(403);
    exit(t('sign_in_first'));
}
session_write_close();

$code = (string) ($_GET['c'] ?? '');
$which = (string) ($_GET['f'] ?? '');
$path = null;
if (preg_match(Posts::CODE_RE, $code)) {
    if (preg_match('/^s(\d{1,3})$/', $which, $m)) {
        $path = Posts::smallPath($code, (int) $m[1]);
    } elseif ($which === 'zip' || preg_match('/^f\d{1,3}$/', $which)) {
        $path = Posts::filePath($code, $which);
    }
}
if ($path === null) {
    http_response_code(404);
    exit(t('no_file'));
}

$types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
          'heic' => 'image/heic', 'mp4' => 'video/mp4', 'zip' => 'application/zip'];
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$size = (int) filesize($path);
$mtime = (int) filemtime($path);
$etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';
$inline = $which[0] === 's' || (($_GET['v'] ?? '') === '1' && $ext !== 'zip');

header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
$name = basename($path);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));

// Behind nginx with accel_prefix set, nginx sends the file itself (X-Accel-Redirect to an internal
// location that serves the data folder): some proxies drop the Content-Length of what PHP sends,
// and the browser could not show the size of a download.
$accel = cfg('accel_prefix');
$root = data_path('') . '/';
if ($accel && str_starts_with($path, $root)) {
    header('X-Accel-Redirect: ' . $accel . implode('/', array_map('rawurlencode', explode('/', substr($path, strlen($root))))));
    exit;
}

// a video opened in the browser asks for ranges; a download takes the whole file
$start = 0;
$end = $size - 1;
header('Accept-Ranges: bytes');
if (preg_match('/^bytes=(\d*)-(\d*)$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $r) && ($r[1] !== '' || $r[2] !== '')) {
    if ($r[1] === '') {
        $start = max(0, $size - (int) $r[2]);
    } else {
        $start = (int) $r[1];
        $end = $r[2] !== '' ? min((int) $r[2], $size - 1) : $size - 1;
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}
header('Content-Length: ' . ($end - $start + 1));
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') {
    exit;
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
set_time_limit(0);
$fh = fopen($path, 'rb');
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh) && !connection_aborted()) {
    $chunk = fread($fh, (int) min(1 << 20, $left));
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    flush();
    $left -= strlen($chunk);
}
fclose($fh);
