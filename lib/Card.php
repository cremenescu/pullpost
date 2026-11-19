<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare(strict_types=1);

// What the card of one post says, worked out once for both themes: the steps while the engine
// works, the files when it is done, what went wrong when it is not. The theme draws it; api.php
// sends the same HTML while the page polls.
final class Card
{
    /** @return array{html: string, live: bool, state: string, status: string} */
    public static function render(string $code): array
    {
        $m = self::model($code);
        $theme = Theme::current();
        return [
            'html' => $theme->card($m),
            'live' => $m['state'] === 'work',
            'state' => $m['state'],
            'status' => $theme->status($m),
        ];
    }

    public static function model(string $code): array
    {
        $job = Posts::sync($code);
        $p = Posts::get($code);
        if ($p === null) {
            return ['state' => 'gone', 'code' => $code];
        }
        $m = [
            'code' => (string) $p['code'],
            'kind' => (string) $p['kind'],
            'user' => (string) $p['user'],
            'title' => self::title($p),
            'url' => self::instagramUrl($p),
            'del' => self::delPost($p),
        ];
        if ($p['state'] === 'done') {
            return ['state' => 'done'] + $m + self::done($p);
        }
        if ($p['state'] === 'error') {
            return ['state' => 'error'] + $m + ['error' => self::error($p)];
        }
        return ['state' => 'work'] + $m + ['work' => self::work($p, $job)];
    }

    // ------------------------------------------------------------ shared bits

    public static function title(array $p): string
    {
        return ($p['user'] ?? '') !== '' ? $p['user'] . ' · ' . $p['code'] : (string) $p['code'];
    }

    public static function instagramUrl(array $p): string
    {
        return 'https://www.instagram.com/' . ($p['kind'] === 'p' ? 'p' : $p['kind']) . '/' . rawurlencode((string) $p['code']) . '/';
    }

    public static function fileUrl(string $code, string $which, bool $inline = false): string
    {
        return 'file.php?c=' . rawurlencode($code) . '&f=' . rawurlencode($which) . ($inline ? '&v=1' : '');
    }

    public static function postHref(string $code): string
    {
        return '?c=' . rawurlencode($code);
    }

    /** "10 photos", "10 photos and 2 videos", "1 video" */
    public static function counts(int $images, int $videos): string
    {
        $parts = [];
        if ($images > 0) {
            $parts[] = tn('n_photos', $images);
        }
        if ($videos > 0) {
            $parts[] = tn('n_videos', $videos);
        }
        return $parts ? implode(t('and'), $parts) : t('no_files');
    }

    /** "photo 03", "video 04" */
    public static function fileLabel(array $f): string
    {
        return t($f['type'] === 'video' ? 'video_n' : 'photo_n', ['n' => sprintf('%02d', (int) $f['n'])]);
    }

    /** The dialog before the whole post is deleted. */
    private static function delPost(array $p): array
    {
        $text = t('del_post_d0');
        if ($p['state'] === 'done') {
            $n = (int) $p['n_images'] + (int) $p['n_videos'];
            $zip = $n > 1 && $p['zip_name'];
            $vars = ['counts' => self::counts((int) $p['n_images'], (int) $p['n_videos']),
                     'size' => size_text((int) $p['bytes'] + ($zip ? (int) $p['zip_bytes'] : 0))];
            $text = t('del_post_d', ['what' => t($zip ? 'del_what_zip' : 'del_what', $vars)]);
        }
        return ['code' => (string) $p['code'], 'title' => t('del_post_t'), 'text' => $text];
    }

    /** data- attributes of a delete button, read by app.js to fill in its dialog. */
    public static function delAttrs(array $d): string
    {
        $out = ' data-del="' . e($d['code']) . '" data-title="' . e($d['title']) . '" data-text="' . e($d['text']) . '"';
        if (isset($d['file'])) {
            $out .= ' data-file="' . (int) $d['file'] . '"';
        }
        return $out;
    }

    /** The number of the file the pasted link pointed at (?img_index=), when there is a choice. */
    private static function pointed(array $p, array $files): ?int
    {
        if (count($files) < 2) {
            return null;
        }
        parse_str((string) parse_url((string) $p['url'], PHP_URL_QUERY), $q);
        $i = (int) ($q['img_index'] ?? 0);
        foreach ($files as $f) {
            if ((int) $f['n'] === $i) {
                return $i;
            }
        }
        return null;
    }

    // ------------------------------------------------------------ done

    private static function done(array $p): array
    {
        $code = (string) $p['code'];
        $rows = Posts::files($code);
        $count = count($rows);
        $files = [];
        foreach ($rows as $f) {
            $n = (int) $f['n'];
            $label = self::fileLabel($f);
            $del = null;
            if ($count > 1) {
                $kind = $f['type'] === 'video' ? 'video' : 'photo';
                $zip = !$p['zip_name'] ? '' : t($count > 2 ? 'del_zip_redo_' . $kind : 'del_zip_gone');
                $del = ['code' => $code, 'file' => $n, 'title' => t('del_' . $kind . '_t'),
                        'text' => t('del_' . $kind . '_d', ['what' => $label . ' (' . size_text((int) $f['bytes']) . ')', 'zip' => $zip])];
            }
            $files[] = [
                'n' => $n,
                'nn' => sprintf('%02d', $n),
                'name' => (string) $f['name'],
                'type' => $f['type'] === 'video' ? 'video' : 'image',
                'ext' => strtoupper((string) pathinfo((string) $f['name'], PATHINFO_EXTENSION)),
                'bytes' => (int) $f['bytes'],
                'size' => size_text((int) $f['bytes']),
                'w' => (int) $f['w'],
                'h' => (int) $f['h'],
                'dims' => (int) $f['w'] > 0 ? (int) $f['w'] . ' × ' . (int) $f['h'] : '',
                'ratio' => (int) $f['w'] > 0 && (int) $f['h'] > 0 ? (int) $f['w'] . ' / ' . (int) $f['h'] : '4 / 5',
                'label' => $label,
                'href' => self::fileUrl($code, 'f' . $n),
                'view' => self::fileUrl($code, 'f' . $n, true),
                'small' => self::fileUrl($code, 's' . $n),
                // the last file of a post goes only with the post
                'del' => $del ?? (['code' => $code] + self::delPost($p)),
            ];
        }
        $zip = $count > 1 && $p['zip_name']
            ? ['href' => self::fileUrl($code, 'zip'), 'size' => size_text((int) $p['zip_bytes']), 'name' => (string) $p['zip_name']]
            : null;
        $mode = $p['mode'] === 'account' ? 'account' : 'anon';
        return [
            'files' => $files,
            'count' => $count,
            'counts' => self::counts((int) $p['n_images'], (int) $p['n_videos']),
            'bytes' => (int) $p['bytes'],
            'size' => size_text((int) $p['bytes']),
            'zip' => $zip,
            'single' => $count === 1 ? $files[0] : null,
            'taken' => $p['taken_at'] ? fmt_day((int) $p['taken_at']) : '',
            'fetched' => fmt_when((int) ($p['fetched_at'] ?: $p['requested_at'])),
            'mode' => $mode,
            'mode_text' => t($mode === 'account' ? 'mode_account' : 'mode_anon'),
            'pointed' => self::pointed($p, $files),
            'refetch_error' => $p['error_kind'] ? t('refetch_failed', ['why' => self::why((string) $p['error_kind'])]) : null,
        ];
    }

    // ------------------------------------------------------------ the engine at work

    private static function work(array $p, ?array $job): array
    {
        $state = (string) ($job['state'] ?? 'queued');
        $since = (int) ($job['started_at'] ?? $job['queued_at'] ?? $p['requested_at']);
        $total = (int) ($job['total'] ?? 0);
        $done = (int) ($job['done'] ?? 0);
        $bytes = (int) ($job['bytes'] ?? 0);
        $account = ($job['mode'] ?? '') === 'account';
        $opened = isset($job['opened_s']) ? fmt_decimal((float) $job['opened_s']) . ' s' : '';

        // [class, text, time or size]: class done, now or wait
        $steps = [];
        if ($state === 'queued') {
            $steps[] = ['now', t('s_wait'), ''];
            $steps[] = ['wait', t('s_open'), ''];
        } elseif ($state === 'opening') {
            $steps[] = ['now', t($account ? 's_opening_acct' : 's_opening'), fmt_span(time() - $since)];
        } else {
            $steps[] = ['done', t($account ? 's_opened_acct' : 's_opened'), $opened];
        }
        if ($state === 'downloading') {
            $steps[] = ['done', tn('s_found', $total), ''];
            $steps[] = ['now', t('s_dl', ['d' => $done, 'n' => $total]), size_text($bytes)];
        } elseif ($state === 'zipping') {
            $steps[] = ['done', tn('s_downloaded', $total), size_text($bytes)];
            $steps[] = ['now', t('s_zipping'), ''];
        }
        if (in_array($state, ['queued', 'opening'], true)) {
            $steps[] = ['wait', t('s_dl_wait'), ''];
        }
        if ($state !== 'zipping') {
            $steps[] = ['wait', t('s_zip'), ''];
        }
        $user = (string) ($job['user'] ?? '');
        return [
            'state' => $state,
            'steps' => $steps,
            'total' => $total,
            'done' => $state === 'zipping' ? $total : $done,
            'pct' => $total > 0 ? (int) round(($state === 'zipping' ? $total : $done) * 100 / $total) : null,
            'size' => size_text($bytes),
            'ago' => t('started', ['t' => fmt_span(time() - $since)]),
            'account' => $account,
            'opened' => $opened,
            'job_user' => $user,
            'job_title' => $user !== '' ? $user . ' · ' . $p['code'] : (string) $p['code'],
        ];
    }

    // ------------------------------------------------------------ what went wrong

    public static function why(string $kind): string
    {
        return match ($kind) {
            'login' => t(Posts::session()['set'] ? 'why_login_acct' : 'why_login'),
            'notfound' => t('why_notfound'),
            'engine' => t('why_engine'),
            default => t('why_failed'),
        };
    }

    private static function error(array $p): array
    {
        $kind = in_array($p['error_kind'], ['login', 'notfound', 'engine'], true) ? (string) $p['error_kind'] : 'failed';
        return [
            'kind' => $kind,
            'title' => t('err_t_' . $kind),
            'why' => self::why($kind),
            'detail' => in_array($kind, ['failed', 'engine'], true) ? (string) $p['error'] : '',
            'settings' => $kind === 'login' && !Posts::session()['set'],
        ];
    }

    // ------------------------------------------------------------ the lists

    /** A row of Posts::recent() or Posts::all(), as the lists show it. */
    public static function row(array $r): array
    {
        $files = (int) $r['n_images'] + (int) $r['n_videos'];
        $st = match ($r['state']) { 'done' => 'done', 'error' => 'error', default => 'work' };
        return [
            'code' => (string) $r['code'],
            'kind' => (string) $r['kind'],
            'user' => (string) $r['user'],
            'title' => self::title($r),
            'href' => self::postHref((string) $r['code']),
            'st' => $st,
            'files' => $files,
            'videos' => (int) $r['n_videos'],
            'bytes' => (int) $r['bytes'],
            'counts' => self::counts((int) $r['n_images'], (int) $r['n_videos']),
            'size' => size_text((int) $r['bytes']),
            'when' => fmt_when((int) $r['requested_at']),
            'at' => (int) $r['requested_at'],
            'taken' => (int) $r['taken_at'],
            'taken_text' => $r['taken_at'] ? fmt_day((int) $r['taken_at']) : '',
            'small' => $st === 'done' && $r['first_n'] !== null ? self::fileUrl((string) $r['code'], 's' . (int) $r['first_n']) : null,
            'zip' => $st === 'done' && $files > 1 && $r['zip_name'] ? self::fileUrl((string) $r['code'], 'zip') : null,
            'del' => self::delPost($r),
        ];
    }
}
