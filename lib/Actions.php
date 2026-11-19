<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare(strict_types=1);

// What the form and the buttons of the pages do (POST index.php, act=...). Each returns the JSON
// the page gets: a message for the toast, and for a post the card to show.
final class Actions
{
    public static function handle(string $act, array $p): array
    {
        $code = (string) ($p['code'] ?? '');
        if (Demo::on()) {
            if (in_array($act, ['start', 'refetch'], true)) {
                throw new UserError(t('demo_no_fetch'));
            }
            if (in_array($act, ['session_save', 'session_clear', 'password'], true)) {
                throw new UserError(t('demo_off'));
            }
        }
        switch ($act) {
            case 'start':
            case 'refetch':
                $r = $act === 'start'
                    ? Posts::start((string) ($p['url'] ?? ''))
                    : Posts::start(self::known($code), true);
                return ['ok' => true, 'code' => $r['code'], 'existing' => $r['existing']] + Card::render($r['code'])
                    + ($r['existing'] ? ['msg' => t('msg_existing')] : []);
            case 'delete':
                Posts::delete(self::known($code));
                return ['ok' => true, 'msg' => t('msg_deleted')];
            case 'delete_file':
                $f = Posts::deleteFile(self::known($code), (int) ($p['n'] ?? 0));
                return ['ok' => true, 'code' => $code, 'msg' => t($f['type'] === 'video' ? 'msg_video_deleted' : 'msg_photo_deleted')]
                    + Card::render($code);
            case 'session_save':
                Posts::saveSession((string) ($p['sessionid'] ?? ''));
                return ['ok' => true, 'msg' => t('msg_session_saved')];
            case 'session_clear':
                Posts::clearSession();
                return ['ok' => true, 'msg' => t('msg_session_gone')];
            case 'password':
                $err = Auth::change((string) ($p['current'] ?? ''), (string) ($p['password'] ?? ''), (string) ($p['again'] ?? ''));
                if ($err !== null) {
                    throw new UserError($err);
                }
                return ['ok' => true, 'msg' => t('msg_password')];
            case 'theme':
                $theme = (string) ($p['theme'] ?? '');
                if (!isset(Theme::NAMES[$theme])) {
                    throw new UserError(t('bad_theme'));
                }
                // in the demo each visitor keeps their own
                Demo::on() ? Demo::rememberTheme($theme) : Db::meta('theme', $theme);
                return ['ok' => true, 'msg' => t('msg_theme')];
        }
        throw new UserError(t('unknown_action'));
    }

    private static function known(string $code): string
    {
        if (!preg_match(Posts::CODE_RE, $code) || Posts::get($code) === null) {
            throw new UserError(t('gone'));
        }
        return $code;
    }
}
