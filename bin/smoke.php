<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Offline checks of the web app on a throwaway data folder: php bin/smoke.php
// No web server, no engine, no Instagram: the engine's job files and manifests are written here,
// the way engine/server.py and engine/igfetch.py write them. Needs the gd extension.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$tmp = sys_get_temp_dir() . '/pullpost-smoke-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
putenv('PULLPOST_DATA=' . $tmp);
putenv('PULLPOST_ENGINE_URL=http://127.0.0.1:9');   // nothing listens: the engine is down
putenv('PULLPOST_LOG=logs/php.log');
require dirname(__DIR__) . '/lib/bootstrap.php';
$GLOBALS['config']['data_dir'] = $tmp;
$GLOBALS['config']['engine_url'] = 'http://127.0.0.1:9';
$GLOBALS['config']['umask'] = null;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
prepare_data_dir();
Db::reset();
I18n::use('en');

$GLOBALS['smoke'] = ['n' => 0, 'fail' => 0];
function check(string $what, bool $ok, string $detail = ''): void
{
    $GLOBALS['smoke']['n']++;
    if (!$ok) {
        $GLOBALS['smoke']['fail']++;
    }
    printf("%s %s%s\n", $ok ? 'ok  ' : 'FAIL', $what, $ok || $detail === '' ? '' : ' -- ' . $detail);
}

function refused(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (UserError $err) {
        return true;
    }
}

// Made-up account and post codes: the checks never name real posts.
const CAROUSEL = 'CxAmPle0001';
const REEL = 'CxAmPle0002';
const PRIVATE_POST = 'CxAmPle0003';
const ACCOUNT = 'example_account';

// ------------------------------------------------------------ the two languages
$en = require APP_ROOT . '/lang/en.php';
foreach (array_keys(I18n::LANGS) as $code) {
    $other = require APP_ROOT . '/lang/' . $code . '.php';
    $odd = array_keys(array_diff_key($en, $other) + array_diff_key($other, $en));
    check("lang/$code.php has the same keys as English", !$odd, implode(', ', $odd));
}
I18n::use('ro');
check('Romanian plurals: 1 poză, 3 poze, 20 de poze, 101 poze',
    tn('n_photos', 1) === '1 poză' && tn('n_photos', 3) === '3 poze' && tn('n_photos', 20) === '20 de poze' && tn('n_photos', 101) === '101 poze');
check('Romanian sizes use a decimal comma', size_text(7127599) === '7,1 MB');
I18n::use('en');
check('English plurals and sizes', tn('n_files', 1) === '1 file' && tn('n_files', 10) === '10 files' && size_text(7127599) === '7.1 MB');
$_COOKIE = [];
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'ro-RO,ro;q=0.9,en;q=0.8';
I18n::init();
check('a Romanian browser gets Romanian', I18n::lang() === 'ro');
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,de;q=0.9';
I18n::init();
check('any other browser gets English', I18n::lang() === 'en');
$_COOKIE[I18n::COOKIE] = 'ro';
I18n::init();
check('the EN/RO cookie wins over the browser', I18n::lang() === 'ro');
$_COOKIE = [];
I18n::use('en');

// ------------------------------------------------------------ the first run
$code = Auth::setupCode(false);
check('a setup code exists before the password is chosen', is_string($code) && preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code) === 1);
check('the setup code file is readable only by its owner', (fileperms(data_path('setup-code')) & 0777) === 0600);
check('the code stays the same until it is used', Auth::setupCode() === $code);
check('a wrong setup code is refused', Auth::choose('AAAA-AAAA', 'longenough', 'longenough', true) === t('setup_wrong'));
check('the notice must be acknowledged', Auth::choose($code, 'longenough', 'longenough', false) === t('ack_needed'));
check('a short password is refused', Auth::choose($code, 'short', 'short', true) === t('pw_short', ['n' => Auth::MIN_PASSWORD]));
check('two different passwords are refused', Auth::choose(strtolower($code), 'longenough', 'different1', true) === t('pw_mismatch'));
check('the code is read without its dash and in any case', Auth::choose(strtolower(str_replace('-', ' ', $code)), 'longenough', 'longenough', true) === null);
check('then the password is set and the code is gone', Auth::configured() && !is_file(data_path('setup-code')) && Auth::setupCode() === null);
check('the acknowledgement is recorded', (int) Db::meta('notice_ack_at') > 0);
check('a second first run is refused', Auth::choose($code, 'longenough', 'longenough', true) === t('pw_set_already'));
Db::run('DELETE FROM login_attempts');

// ------------------------------------------------------------ reading the pasted address
$cases = [
    'https://www.instagram.com/p/' . CAROUSEL . '/?img_index=4' => [CAROUSEL, 'p'],
    'instagram.com/p/' . CAROUSEL => [CAROUSEL, 'p'],
    'https://www.instagram.com/reel/' . REEL . '/?igsh=abc' => [REEL, 'reel'],
    'https://www.instagram.com/reels/' . REEL . '/' => [REEL, 'reel'],
    'https://m.instagram.com/tv/AbCdE_-1234/' => ['AbCdE_-1234', 'tv'],
    'https://www.instagram.com/' . ACCOUNT . '/p/' . CAROUSEL . '/' => [CAROUSEL, 'p'],
    'https://instagr.am/p/' . CAROUSEL . '/' => [CAROUSEL, 'p'],
    '  ' . CAROUSEL . '  ' => [CAROUSEL, 'p'],
];
foreach ($cases as $in => [$c, $kind]) {
    $p = Posts::parse($in);
    check('parse ' . trim($in), $p['code'] === $c && $p['kind'] === $kind, json_encode($p));
}
check('a profile address is refused', refused(fn () => Posts::parse('https://www.instagram.com/' . ACCOUNT . '/')));
check('another site is refused', refused(fn () => Posts::parse('https://evil.example/p/' . CAROUSEL . '/')));
check('a look-alike host is refused', refused(fn () => Posts::parse('https://instagram.com.evil.example/p/' . CAROUSEL . '/')));
check('a path-traversal code is refused', refused(fn () => Posts::parse('https://www.instagram.com/p/../../etc/')));

// ------------------------------------------------------------ the engine is down
$r = Posts::start('https://www.instagram.com/p/' . CAROUSEL . '/?img_index=4');
$p = Posts::get(CAROUSEL);
check('a post asked while the engine is down is kept, as an error', $r['code'] === CAROUSEL && $p['state'] === 'error' && $p['error_kind'] === 'engine');
foreach (['a', 'b'] as $th) {
    Theme::force($th);
    $card = Card::render(CAROUSEL);
    check("theme $th: the card says the download did not start and offers to try again",
        !$card['live'] && str_contains($card['html'], t('err_t_engine')) && str_contains($card['html'], 'data-act="refetch"'));
}

// ------------------------------------------------------------ what the engine writes, made up here
function engine_job(string $code, array $job): void
{
    file_put_contents(data_path('jobs/' . $code . '.json'), json_encode($job + ['code' => $code, 'started_at' => time() - 5]));
}

function jpeg(string $path, int $w, int $h): int
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 120, 60));
    imagejpeg($img, $path, 85);
    return (int) filesize($path);
}

function engine_post(string $code, string $kind, array $items, string $user = ACCOUNT, int $taken = 1795478400): array
{
    $dir = Posts::dir($code);
    @mkdir($dir . '/thumbs', 0777, true);
    $stem = date('Y-m-d', $taken) . '_' . $user . '_' . $code;
    $files = [];
    foreach ($items as $i => $type) {
        $nn = $i + 1;
        $name = sprintf('%s_%02d.%s', $stem, $nn, $type === 'video' ? 'mp4' : 'jpg');
        $f = ['n' => $nn, 'name' => $name, 'type' => $type, 'w' => 640, 'h' => 1136, 'thumb' => null];
        if ($type === 'video') {
            file_put_contents("$dir/$name", str_repeat("\0", 50000));
            $f['bytes'] = 50000;
            $f['thumb'] = sprintf('thumbs/%02d.jpg', $nn);
            jpeg("$dir/{$f['thumb']}", 640, 1136);
        } else {
            $f['bytes'] = jpeg("$dir/$name", 640, 1136);
        }
        $files[] = $f;
    }
    file_put_contents("$dir/$stem.zip", 'PK');
    $m = ['code' => $code, 'kind' => $kind, 'user' => $user, 'taken_at' => $taken, 'mode' => 'anon', 'files' => $files,
          'zip' => ['name' => "$stem.zip", 'bytes' => 2], 'fetched_at' => time(), 'opened_s' => 2.4];
    file_put_contents("$dir/manifest.json", json_encode($m));
    return $m;
}

// Try again, with the engine up now: the job moves through its states
Db::run("UPDATE posts SET state = 'queued', error_kind = NULL, error = NULL WHERE code = ?", [CAROUSEL]);
engine_job(CAROUSEL, ['state' => 'opening', 'mode' => 'anon']);
Theme::force('a');
$card = Card::render(CAROUSEL);
check('opening: the card is live and says Chromium opens the post', $card['live'] && str_contains($card['html'], t('s_opening')));
check('opening: the post is marked running', Posts::get(CAROUSEL)['state'] === 'running');
engine_job(CAROUSEL, ['state' => 'downloading', 'mode' => 'anon', 'total' => 10, 'done' => 6, 'bytes' => 4100000, 'opened_s' => 2.4, 'user' => ACCOUNT]);
$card = Card::render(CAROUSEL);
check('downloading: 6 of 10, with the bar and the bytes', str_contains($card['html'], t('s_dl', ['d' => 6, 'n' => 10])) && str_contains($card['html'], 'width:60%') && str_contains($card['html'], '4.1 MB'));
check('downloading: the account name shows up', str_contains($card['html'], '<h1>' . ACCOUNT . '</h1>'));
check('downloading, theme a: 6 mounts done, the 7th filling', substr_count($card['html'], 'mount got') === 6 && substr_count($card['html'], 'empty next') === 1);
Theme::force('b');
$card = Card::render(CAROUSEL);
check('downloading, theme b: the display prints 6/10', str_contains($card['status'], 'Printing 6/10') && substr_count($card['status'], '█') === 6);
engine_job(CAROUSEL, ['state' => 'zipping', 'total' => 10, 'done' => 10, 'bytes' => 7100000]);
check('zipping: the ZIP is being made', str_contains(Card::render(CAROUSEL)['html'], t('s_zipping')));

$m = engine_post(CAROUSEL, 'p', array_fill(0, 10, 'image'));
engine_job(CAROUSEL, ['state' => 'done', 'total' => 10, 'done' => 10]);
Theme::force('a');
$card = Card::render(CAROUSEL);
$p = Posts::get(CAROUSEL);
check('done: imported from manifest.json', $p['state'] === 'done' && (int) $p['n_images'] === 10 && $p['user'] === ACCOUNT && count(Posts::files(CAROUSEL)) === 10);
check('done, theme a: not live, the ZIP and 10 mounts', !$card['live'] && str_contains($card['html'], 'f=zip') && substr_count($card['html'], '<li class="mount"') === 10);
check('done, theme a: the frame the link pointed at is circled', substr_count($card['html'], 'class="grease"') === 1 && str_contains($card['html'], t('pointed', ['n' => '04'])));
Theme::force('b');
$card = Card::render(CAROUSEL);
check('done, theme b: 10 line items, the coupon and the stamp', substr_count($card['html'], '<span class="n">') === 10 && str_contains($card['html'], 'class="coupon"') && str_contains($card['html'], 'class="stamp"'));
check('done, theme b: the pointed file has its star', str_contains($card['html'], '<span class="n">04*</span>'));
check('done: names carry the post date in front', str_starts_with(Posts::files(CAROUSEL)[0]['name'], date('Y-m-d', 1795478400) . '_' . ACCOUNT . '_' . CAROUSEL . '_01'));
check('done: "posted" with the short month', str_contains(Card::render(CAROUSEL)['html'], fmt_day(1795478400)));
check('a file the database knows is served', Posts::filePath(CAROUSEL, 'f3') !== null && Posts::filePath(CAROUSEL, 'zip') !== null);
check('a file number it does not know is not', Posts::filePath(CAROUSEL, 'f11') === null && Posts::filePath(CAROUSEL, 'x') === null);
check('nothing is served for an unknown code', Posts::filePath('../../etc', 'f1') === null && Posts::filePath('Zzzzzzzzzz', 'f1') === null);
$small = Posts::smallPath(CAROUSEL, 1);
check('the small picture is 320 px wide', $small !== null && str_ends_with($small, '/small/01.jpg') && getimagesize($small)[0] === 320);
check('a post already downloaded comes back as it is', Posts::start('https://www.instagram.com/p/' . CAROUSEL . '/')['existing'] === true);

// a manifest that names a file outside the post's folder
$dir = Posts::dir('BadName123');
@mkdir($dir, 0777, true);
file_put_contents("$dir/manifest.json", json_encode(['user' => 'x', 'files' => [
    ['n' => 1, 'name' => '../../../etc/passwd', 'type' => 'image', 'bytes' => 1],
    ['n' => 2, 'name' => '.htaccess', 'type' => 'image', 'bytes' => 1],
], 'zip' => ['name' => '../x.zip', 'bytes' => 1]]));
Db::run("INSERT INTO posts (code, kind, url, state, created_at, requested_at) VALUES ('BadName123', 'p', 'x', 'queued', 1, 1)");
engine_job('BadName123', ['state' => 'done']);
Posts::sync('BadName123');
check('names outside the folder are dropped on import', count(Posts::files('BadName123')) === 0 && Posts::get('BadName123')['zip_name'] === null);

// ------------------------------------------------------------ a reel, with a video
Db::run("INSERT INTO posts (code, kind, url, state, created_at, requested_at) VALUES (?, 'reel', 'x', 'queued', 2, 2)", [REEL]);
engine_post(REEL, 'reel', ['video']);
engine_job(REEL, ['state' => 'done']);
Theme::force('a');
$card = Card::render(REEL);
check('reel: one video, no ZIP, a single download', (int) Posts::get(REEL)['n_videos'] === 1 && !str_contains($card['html'], 'f=zip') && str_contains($card['html'], 'class="btn solid" href="file.php?c=' . REEL . '&amp;f=f1"'));
check('reel: the cover is the thumbnail', str_ends_with((string) Posts::filePath(REEL, 't1'), 'thumbs/01.jpg'));
check('reel: Open original links to /reel/', str_contains($card['html'], 'https://www.instagram.com/reel/' . REEL . '/'));

// Fetch again keeps the kind and, when it fails, the old files
Posts::start(REEL, true);
check('asked again, a reel stays a reel', Posts::get(REEL)['kind'] === 'reel');
Db::run("UPDATE posts SET state = 'queued' WHERE code = ?", [REEL]);
engine_job(REEL, ['state' => 'error', 'error_kind' => 'login', 'error' => 'Instagram asked for a login.']);
$card = Card::render(REEL);
$p = Posts::get(REEL);
check('a failed refetch keeps the files and says so', $p['state'] === 'done' && $p['error_kind'] === 'login' && str_contains($card['html'], 'Fetching again did not work'));

// ------------------------------------------------------------ errors from the engine
Db::run("INSERT INTO posts (code, kind, url, state, created_at, requested_at) VALUES (?, 'p', 'x', 'queued', 3, 3)", [PRIVATE_POST]);
engine_job(PRIVATE_POST, ['state' => 'error', 'error_kind' => 'login', 'error' => 'Instagram asked for a login.']);
foreach (['a', 'b'] as $th) {
    Theme::force($th);
    $card = Card::render(PRIVATE_POST);
    check("theme $th: a login error is explained, with the way to Settings", str_contains($card['html'], t('err_t_login')) && str_contains($card['html'], 'open=d-ig'));
}
check('theme b: the display says a login is needed', Card::render(PRIVATE_POST)['status'] === t('login_needed'));

// ------------------------------------------------------------ the Instagram session
check('a session with spaces is refused', refused(fn () => Posts::saveSession('abc def ghi jkl')));
Posts::saveSession('sessionid=12345678%3AabcDEF%3A9');
$raw = json_decode((string) file_get_contents(data_path('ig-session.json')), true);
check('a pasted name=value is stored as the value alone', $raw['sessionid'] === '12345678%3AabcDEF%3A9' && Posts::session()['set']);
check('the session file is readable only by its owner', (fileperms(data_path('ig-session.json')) & 0777) === 0600);
check('with a session, the login error no longer sends to Settings', !str_contains(Card::render(PRIVATE_POST)['html'], 'open=d-ig'));
Posts::clearSession();
check('the session can be cleared', !Posts::session()['set']);

// ------------------------------------------------------------ history and deleting
Db::run('UPDATE posts SET requested_at = 100 WHERE code = ?', [CAROUSEL]);
check('the history lists every post, newest first', count(Posts::all()) === 4 && Posts::recent(1)[0]['code'] === REEL && Posts::all()[3]['code'] === 'BadName123');
$t = Posts::totals();
check('the totals count only finished posts', $t['count'] === 3 && $t['files'] === 11);
engine_job(PRIVATE_POST, ['state' => 'downloading']);
Db::run("UPDATE posts SET state = 'running' WHERE code = ?", [PRIVATE_POST]);
check('a post that is downloading cannot be deleted', refused(fn () => Posts::delete(PRIVATE_POST)));

// one file at a time: the ZIP is made again from what is left, the manifest follows
foreach (['a', 'b'] as $th) {
    Theme::force($th);
    check("theme $th: every file of a carousel has its own trash", substr_count(Card::render(CAROUSEL)['html'], 'data-file=') === 10);
    $html = Card::render(CAROUSEL)['html'];
    check("theme $th: every file opens in the viewer, not in a new window", substr_count($html, 'data-viewgroup') === 1
        && substr_count($html, ' data-view data-type="image" data-name="') === 10 && substr_count($html, 'data-dl="file.php?c=') === 10
        && !preg_match('/<a [^>]*data-view[^>]*target=/', $html));
}
$r = Actions::handle('delete_file', ['code' => CAROUSEL, 'n' => '3']);
$p = Posts::get(CAROUSEL);
$dir = Posts::dir(CAROUSEL);
$names = array_column(Posts::files(CAROUSEL), 'name');
check('deleting file 03 leaves 9, on disk and in the database', count($names) === 9 && (int) $p['n_images'] === 9
    && !in_array($m['files'][2]['name'], $names, true) && !is_file($dir . '/' . $m['files'][2]['name']) && $r['msg'] === t('msg_photo_deleted'));
if (class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    $zipOk = $zip->open($dir . '/' . $p['zip_name'], ZipArchive::CHECKCONS) === true;
    $inZip = [];
    for ($i = 0; $zipOk && $i < $zip->numFiles; $i++) {
        $inZip[] = $zip->getNameIndex($i);
    }
    $sameBytes = $zipOk && $zip->getFromName($names[0]) === file_get_contents($dir . '/' . $names[0]);
    if ($zipOk) {
        $zip->close();
    }
    check('the ZIP is made again, valid, with the 9 files left', $zipOk && $inZip === $names && $sameBytes && (int) $p['zip_bytes'] === filesize($dir . '/' . $p['zip_name']));
} else {
    check('the ZIP is made again, with the size the database says', (int) $p['zip_bytes'] === filesize($dir . '/' . $p['zip_name']));
}
check('manifest.json no longer lists it, so a failed refetch cannot bring it back', (Posts::import(CAROUSEL) ?? true) && count(Posts::files(CAROUSEL)) === 9);
check('a file number not in the post is refused', refused(fn () => Posts::deleteFile(CAROUSEL, 3)));
check('the only file of a post cannot go alone', refused(fn () => Posts::deleteFile(REEL, 1)));
foreach ([1, 2, 4, 5, 6, 7, 8, 9] as $n) {
    Posts::deleteFile(CAROUSEL, $n);
}
$p = Posts::get(CAROUSEL);
Theme::force('a');
$card = Card::render(CAROUSEL)['html'];
check('down to one file, the ZIP is gone', $p['zip_name'] === null && !glob($dir . '/*.zip') && !str_contains($card, 'f=zip'));
check('and the last file has no trash of its own', !str_contains($card, 'data-file='));
check('the list picture follows the first file left', Card::row(Posts::all()[array_search(CAROUSEL, array_column(Posts::all(), 'code'), true)])['small'] === Card::fileUrl(CAROUSEL, 's10'));

Posts::delete(CAROUSEL);
check('deleting removes the row, its files and the folder', Posts::get(CAROUSEL) === null && !is_dir(Posts::dir(CAROUSEL))
    && (int) Db::value('SELECT count(*) FROM files WHERE code = ?', [CAROUSEL]) === 0 && !is_file(data_path('jobs/' . CAROUSEL . '.json')));

// ------------------------------------------------------------ behind a reverse proxy
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9, 198.51.100.7';
check('without PULLPOST_TRUST_PROXY the forwarded address is ignored', client_ip() === '127.0.0.1');
$GLOBALS['config']['trust_proxy'] = true;
check('with it, the client is the last hop the proxy saw', client_ip() === '198.51.100.7');
$GLOBALS['config']['trust_proxy'] = false;
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
$_COOKIE = [];
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9';
$_SERVER['HTTP_X_PULLPOST_LANG'] = 'ro';
I18n::init();
check('without PULLPOST_TRUST_PROXY the proxy cannot pick the language', I18n::lang() === 'en');
$GLOBALS['config']['trust_proxy'] = true;
I18n::init();
$byProxy = I18n::lang();
$_COOKIE[I18n::COOKIE] = 'en';
I18n::init();
check('with it, X-Pullpost-Lang picks it until the EN/RO buttons set the cookie', $byProxy === 'ro' && I18n::lang() === 'en');
$GLOBALS['config']['trust_proxy'] = false;
$_COOKIE = [];
unset($_SERVER['HTTP_X_PULLPOST_LANG']);
I18n::use('en');

// ------------------------------------------------------------ actions
check('an unknown action is refused', refused(fn () => Actions::handle('nope', [])));
check('refetch of a post not in the history is refused', refused(fn () => Actions::handle('refetch', ['code' => 'Nothing1234'])));
check('an unknown theme is refused', refused(fn () => Actions::handle('theme', ['theme' => 'c'])));
Actions::handle('theme', ['theme' => 'b']);
Theme::force(null);
check('the theme chosen in Settings is kept', Theme::id() === 'b' && Theme::current() instanceof ThemeB);

// ------------------------------------------------------------ the pages build, in both themes and both languages
foreach (['a', 'b'] as $th) {
    foreach (['en', 'ro'] as $lang) {
        Theme::force($th);
        I18n::use($lang);
        $theme = Theme::current();
        ob_start();
        $theme->shellStart('history');
        require APP_ROOT . '/lib/pages/history.php';
        $theme->shellEnd();
        $html = ob_get_clean();
        check("$th/$lang: History has its rows, filters and a trash per row", substr_count($html, 'data-href="?c=') === 3 && str_contains($html, 'id="hist-n"')
            && substr_count($html, 'data-href="?c=') === substr_count($html, 'data-title="' . e(t('del_post_t')) . '"'));
        check("$th/$lang: the page ends with its data and script", str_contains($html, 'id="pullpost-data"') && str_contains($html, 'assets/app.js'));
        check("$th/$lang: the viewer is on the page, with its arrows", str_contains($html, '<dialog class="viewer" id="viewer" aria-label="' . e(t('viewer')) . '"')
            && str_contains($html, 'id="viewer-prev"') && str_contains($html, 'id="viewer-next"'));
        ob_start();
        require APP_ROOT . '/lib/pages/settings.php';
        $html = ob_get_clean();
        check("$th/$lang: Settings says the engine does not answer, and has its drawers", str_contains($html, e(t('engine_down'))) && str_contains($html, 'id="d-ig"') && str_contains($html, 'id="d-theme"'));
        ob_start();
        $theme->fetchPage(REEL);
        $html = ob_get_clean();
        check("$th/$lang: Fetch shows the post and the recent ones", str_contains($html, 'id="post"') && str_contains($html, 'id="recent-live"') && str_contains($html, 'id="getform"') === ($th === 'b'));
    }
}
I18n::use('en');
ob_start();
View::icons();
$html = ob_get_clean();
check("the icons have the viewer's arrows", str_contains($html, 'id="i-prev"') && str_contains($html, 'id="i-next"'));

// ------------------------------------------------------------ the demo (PULLPOST_DEMO=1)
const DEMO_POST = 'CxAmPle0004';
Db::run("INSERT INTO posts (code, kind, url, state, created_at, requested_at) VALUES (?, 'p', 'x', 'queued', 3, 3)", [DEMO_POST]);
engine_post(DEMO_POST, 'p', ['image', 'image', 'image']);
engine_job(DEMO_POST, ['state' => 'done']);
Posts::sync(DEMO_POST);
Db::meta('theme', 'a');
$GLOBALS['config']['demo'] = true;
$GLOBALS['config']['demo_password'] = 'demo-pass-1';
$_SESSION = [];
check('demo: no setup code is asked for', Auth::configured() && Auth::setupCode() === null);
check('demo: only the demo password signs in', !Auth::attempt('wrong-pass') && Auth::attempt('demo-pass-1') && Auth::check());
check('demo: fetching, the Instagram session and the password are refused',
    refused(fn () => Actions::handle('start', ['url' => 'https://www.instagram.com/p/' . DEMO_POST . '/']))
    && refused(fn () => Actions::handle('refetch', ['code' => DEMO_POST]))
    && refused(fn () => Actions::handle('session_save', ['sessionid' => 'abcdefgh']))
    && refused(fn () => Actions::handle('password', ['current' => 'demo-pass-1', 'password' => 'another-1', 'again' => 'another-1'])));
check('demo: the engine is never asked', Engine::health() === null && Theme::engineLine() === t('demo_engine'));
Actions::handle('theme', ['theme' => 'b']);
Theme::force(null);
check('demo: the theme chosen is the visitor\'s, the app\'s stays', Theme::id() === 'b' && Db::meta('theme') === 'a');
unset($_COOKIE[Demo::THEME_COOKIE]);
check('demo: a new visitor gets the default theme', Theme::id() === (string) cfg('theme'));

$done = (int) Db::value("SELECT count(*) FROM posts WHERE state = 'done'");
check('demo: the snapshot takes every finished post', Demo::snapshot() === $done && Demo::hasSeed() && is_dir(Demo::seedDir() . '/posts/' . DEMO_POST));
$seedDir = Demo::seedDir() . '/posts/' . DEMO_POST;
$seedZip = $seedDir . '/' . Posts::get(DEMO_POST)['zip_name'];
Actions::handle('delete_file', ['code' => DEMO_POST, 'n' => '2']);
Actions::handle('delete', ['code' => REEL]);
check('demo: deleting works as in the app', count(Posts::files(DEMO_POST)) === 2 && Posts::get(REEL) === null);
$slot = (string) intdiv(time(), 3600);
file_put_contents(data_path('demo-reset'), $slot);
Demo::maybeReset();
check('demo: nothing is reset before the hour is over', Posts::get(REEL) === null);
file_put_contents(data_path('demo-reset'), '0');
Demo::maybeReset();
$files = Posts::files(DEMO_POST);
check('demo: the reset brings back the rows and the files, and only the finished posts',
    Posts::get(REEL) !== null && count($files) === 3 && is_file(Posts::dir(DEMO_POST) . '/' . $files[1]['name'])
    && is_file((string) Posts::filePath(REEL, 'f1')) && count(Posts::all()) === $done && Posts::get(PRIVATE_POST) === null);
check('demo: the reset is marked for this hour', trim((string) file_get_contents(data_path('demo-reset'))) === $slot);
Actions::handle('delete_file', ['code' => DEMO_POST, 'n' => '1']);
check('demo: deleting after a reset leaves the snapshot whole',
    is_file($seedDir . '/' . $files[0]['name']) && file_get_contents($seedZip) === 'PK' && count(glob($seedDir . '/*.jpg')) === 3);
Theme::force('a');
ob_start();
View::head('x', 'p-settings');
require APP_ROOT . '/lib/pages/settings.php';
$html = ob_get_clean();
check('demo: Settings keep the look and say when it resets, without the session or the password',
    str_contains($html, 'id="d-theme"') && !str_contains($html, 'id="d-ig"') && !str_contains($html, 'id="d-pass"')
    && str_contains($html, e(t('demo_next', ['t' => date('H:i', Demo::nextReset())]))));
check('demo: the pages carry the band, the source link and noindex',
    str_contains($html, 'class="demo-band"') && str_contains($html, e(Demo::SOURCE)) && str_contains($html, '<meta name="robots" content="noindex, nofollow">'));
check('demo: the next reset is on the hour', Demo::nextReset(7205) === 10800);
$GLOBALS['config']['demo'] = false;
$_SESSION = [];
ob_start();
View::head('x');
$html = ob_get_clean();
check('without PULLPOST_DEMO there is no band and no noindex', !str_contains($html, 'demo-band') && !str_contains($html, 'noindex'));

// clean up
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
}
rmdir($tmp);
printf("\n%d checks, %d failed\n", $GLOBALS['smoke']['n'], $GLOBALS['smoke']['fail']);
exit($GLOBALS['smoke']['fail'] === 0 ? 0 : 1);
