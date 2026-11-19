<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare(strict_types=1);

// Theme A, "light table": the post's files lie as slides on a backlit table, the frame the link
// pointed at is circled in grease pencil, and the post's details are typed on an index card. The
// address form sits in the dark bezel above the table, on every page.
final class ThemeA extends Theme
{
    // Each mount lies on the table at its own small angle.
    private const ROT = [-1.2, 0.8, -0.4, 1.1, -0.9, 0.5, -1, 0.7, -0.3, 1];
    // Hand-drawn grease pencil: a loop that overshoots where it closes, and a cross.
    private const LOOP = '<svg class="grease" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true"><path d="M22 24C34 8 70 5 86 19c13 12 13 50-1 66-14 14-52 16-70 2C1 75 2 46 12 33 21 21 40 11 60 12"/></svg>';
    private const CROSS = '<svg class="grease" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true"><path d="M26 22C42 40 58 58 76 80M75 20C60 38 44 60 25 82"/></svg>';

    public function shellStart(string $page): void
    {
        ?><header class="bezel top">
  <a class="brand" href="./"><span class="mark" aria-hidden="true"></span>pullpost</a>
  <form class="slot" id="getform" autocomplete="off">
    <label class="field"><?= icon('link') ?><input type="text" inputmode="url" name="url" id="geturl" placeholder="<?= e(t('paste')) ?>" aria-label="<?= e(t('post_link')) ?>" spellcheck="false" autocapitalize="off" required<?= $page === 'fetch' && !isset($_GET['c']) ? ' autofocus' : '' ?>></label>
    <button class="fetch" type="submit" title="<?= e(t('fetch')) ?>"><?= icon('down') ?><span class="lbl"><?= e(t('fetch')) ?></span></button>
  </form>
  <?= View::nav($page) ?>
  <p class="hint"><?= e(t('hint')) ?></p>
</header>
<main class="table page-<?= e($page) ?>">
<?php
    }

    public function shellEnd(): void
    {
        $up = Engine::health() !== null;
        ?>
</main>
<footer class="bezel foot">
  <span><?= e(t('disclaimer')) ?></span>
  <span class="eng" id="engine-live" data-live><span class="lamp<?= $up ? '' : ' off' ?>" aria-hidden="true"></span><span><?= e(self::engineLine()) ?></span></span>
</footer>
<?php
        View::end();
    }

    public function gateStart(): void
    {
        ?><header class="bezel top gate-top">
  <span class="brand"><span class="mark" aria-hidden="true"></span>pullpost</span>
  <nav class="nav"><?= View::lang() ?></nav>
</header>
<main class="table gate">
<?php
    }

    public function gateEnd(): void
    {
        ?>
</main>
<footer class="bezel foot"><span><?= e(t('disclaimer')) ?></span></footer>
</body>
</html>
<?php
    }

    // ------------------------------------------------------------ the Fetch page

    public function fetchPage(?string $current): void
    {
        $card = $current !== null ? Card::render($current) : null;
        ?>
<div id="post" data-code="<?= e((string) $current) ?>" data-live-post="<?= $card !== null && $card['live'] ? '1' : '0' ?>"><?= $card['html'] ?? $this->empty() ?></div>
<?= $this->recent($current) ?>
<?php
    }

    private function empty(): string
    {
        $any = (int) Db::value('SELECT count(*) FROM posts') > 0;
        return '<div class="work solo"><aside class="card"><h1>' . e(t('empty_t')) . '</h1><p>' . e(t($any ? 'empty_d' : 'empty_first')) . '</p></aside></div>';
    }

    /** Earlier posts as small stacks of mounts along the table's edge. */
    private function recent(?string $current): string
    {
        $rows = Posts::recent(6);
        if (!$rows) {
            return '<section class="recent" id="recent-live" data-live hidden></section>';
        }
        $h = '<section class="recent" id="recent-live" data-live><div class="recent-h"><h2>' . e(t('recent')) . '</h2>'
            . '<a class="more" href="?p=history">' . icon('clock') . e(t('all_history')) . '</a></div><ul>';
        foreach ($rows as $r) {
            $x = Card::row($r);
            $pic = $x['small'] !== null ? '<img loading="lazy" src="' . e($x['small']) . '" alt="">'
                : ($x['st'] === 'error' ? icon('lock') : icon('dot'));
            $line = match ($x['st']) {
                'done' => $x['counts'] . ', ' . $x['size'],
                'error' => t('st_error'),
                default => t('st_work'),
            };
            $h .= '<li class="st-' . $x['st'] . ($x['code'] === $current ? ' cur' : '') . '"><a href="' . e($x['href']) . '">'
                . '<span class="stack"><span>' . $pic . '</span></span>'
                . '<span class="r-t"><b>' . e($x['user'] !== '' ? $x['user'] : $x['code']) . '</b>'
                . ($x['user'] !== '' ? '<span class="code">' . e($x['code']) . '</span>' : '')
                . '<span class="m">' . e($line) . '</span><span class="m">' . e($x['when']) . '</span></span></a></li>';
        }
        return $h . '</ul></section>';
    }

    // ------------------------------------------------------------ the card of a post

    public function card(array $m): string
    {
        return match ($m['state']) {
            'done' => $this->done($m),
            'work' => $this->work($m),
            'error' => $this->error($m),
            default => '<div class="work solo"><aside class="card"><h1>' . e($m['code']) . '</h1><p>' . e(t('gone')) . '</p></aside></div>',
        };
    }

    private function head(string $user, string $code): string
    {
        return $user !== '' ? '<h1>' . e($user) . '</h1><span class="code">' . e($code) . '</span>' : '<h1>' . e($code) . '</h1>';
    }

    /** @param array<int, array{0: string, 1?: string}> $lines */
    private function typed(array $lines): string
    {
        $h = '<ul class="typed">';
        foreach ($lines as $l) {
            $h .= '<li' . (isset($l[1]) ? ' class="' . $l[1] . '"' : '') . '>' . e($l[0]) . '</li>';
        }
        return $h . '</ul>';
    }

    private function done(array $m): string
    {
        $one = $m['single'];
        $lines = [[$m['counts']]];
        if ($m['taken'] !== '') {
            $lines[] = [t('posted', ['d' => $m['taken']])];
        }
        $lines[] = [t('fetched', ['d' => $m['fetched']]) . ', ' . $m['mode_text']];
        if ($one !== null) {
            $lines[] = [$one['ext'] . ($one['dims'] !== '' ? ', ' . $one['dims'] : '')];
            $lines[] = [$one['name'], 'file'];
        } elseif ($m['files'] && $m['files'][0]['dims'] !== '') {
            $lines[] = [t('resolution', ['d' => $m['files'][0]['dims']])];
        }
        if ($m['pointed'] !== null) {
            $lines[] = [t('pointed', ['n' => sprintf('%02d', $m['pointed'])]), 'red'];
        }

        $h = '<div class="work"><aside class="card">' . $this->head($m['user'], $m['code']) . $this->typed($lines);
        if ($m['refetch_error'] !== null) {
            $h .= '<p class="err-note" role="alert">' . e($m['refetch_error']) . '</p>';
        }
        $h .= '<div class="acts">';
        if ($m['zip'] !== null) {
            $h .= '<a class="btn solid" href="' . e($m['zip']['href']) . '" download>' . icon('zip') . e(t('dl_all')) . ' <span class="sub">ZIP ' . e($m['zip']['size']) . '</span></a>';
        } elseif ($one !== null) {
            $h .= '<a class="btn solid" href="' . e($one['href']) . '" download>' . icon('down') . e(t('dl')) . ' <span class="sub">' . e($one['size']) . '</span></a>';
        }
        $h .= '<a class="btn" href="' . e($m['url']) . '" target="_blank" rel="noopener noreferrer">' . icon('ext') . e(t('open')) . '</a></div>'
            . '<div class="quiet"><button type="button" data-act="refetch" data-code="' . e($m['code']) . '">' . icon('redo') . e(t('again')) . '</button>'
            . '<button type="button"' . Card::delAttrs($m['del']) . '>' . icon('trash') . e(t('del')) . '</button></div></aside>';

        $h .= '<ol class="slides' . ($one !== null ? ' reel' : '') . '" data-viewgroup>';
        foreach ($m['files'] as $i => $f) {
            $video = $f['type'] === 'video';
            $h .= '<li class="mount" style="--r:' . self::ROT[$i % count(self::ROT)] . 'deg">'
                . '<a class="win" href="' . e($f['view']) . '"' . Card::viewAttrs($f) . ' title="' . e(t('open_name', ['name' => $f['name']])) . '">'
                . '<img loading="lazy" src="' . e($f['small']) . '" alt="' . e(ucfirst($f['label'])) . '">' . ($video ? icon('play') : '') . '</a>'
                . '<span class="ml"><span>' . $f['nn'] . '</span><span class="sz">' . e($f['size']) . '</span>'
                . '<a href="' . e($f['href']) . '" download title="' . e($f['name']) . '" aria-label="' . e(t('dl_name', ['name' => $f['name']])) . '">' . icon('down') . '</a>'
                . ($one === null ? '<button type="button" class="rm"' . Card::delAttrs($f['del']) . ' title="' . e(t('del_name', ['name' => $f['label']])) . '" aria-label="' . e(t('del_name', ['name' => $f['name']])) . '">' . icon('trash') . '</button>' : '')
                . '</span>' . ($m['pointed'] === $f['n'] ? self::LOOP : '') . '</li>';
        }
        return $h . '</ol></div>';
    }

    private function work(array $m): string
    {
        $w = $m['work'];
        $h = '<div class="work"><aside class="card">' . $this->head($w['job_user'], $m['code']) . '<ol class="steps">';
        foreach ($w['steps'] as [$cls, $text, $at]) {
            $ic = match ($cls) {
                'done' => icon('check'),
                'now' => $w['state'] === 'downloading' ? icon('down') : '<span class="spin" aria-hidden="true"></span>',
                default => icon('dot'),
            };
            $h .= '<li class="' . $cls . '">' . $ic . '<span>' . e($text) . '</span>' . ($at !== '' ? '<span class="at">' . e($at) . '</span>' : '') . '</li>';
        }
        $h .= '</ol>';
        if ($w['pct'] !== null) {
            $h .= '<div class="bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $w['pct'] . '"><i style="width:' . $w['pct'] . '%"></i></div>';
        }
        $h .= '<p>' . e($w['ago']) . '</p></aside>';
        if ($w['total'] > 0) {
            $h .= '<ol class="slides">';
            for ($i = 0; $i < $w['total']; $i++) {
                $cls = $i < $w['done'] ? 'got' : ($i === $w['done'] ? 'empty next' : 'empty');
                $h .= '<li class="mount ' . $cls . '" style="--r:' . self::ROT[$i % count(self::ROT)] . 'deg"><span class="win">'
                    . ($i < $w['done'] ? icon('check') : '') . '</span><span class="ml"><span>' . sprintf('%02d', $i + 1) . '</span></span></li>';
            }
            $h .= '</ol>';
        }
        return $h . '</div>';
    }

    private function error(array $m): string
    {
        $x = $m['error'];
        $h = '<div class="work"><aside class="card">' . $this->head($m['user'], $m['code'])
            . '<p class="err-t">' . e($x['title']) . '</p><p>' . e($x['why']) . '</p>'
            . ($x['detail'] !== '' ? '<p class="detail">' . e($x['detail']) . '</p>' : '')
            . '<div class="acts"><button type="button" class="btn solid" data-act="refetch" data-code="' . e($m['code']) . '">' . icon('redo') . e(t('retry')) . '</button>'
            . '<a class="btn" href="' . e($m['url']) . '" target="_blank" rel="noopener noreferrer">' . icon('ext') . e(t('open')) . '</a></div>'
            . '<div class="quiet"><button type="button"' . Card::delAttrs($m['del']) . '>' . icon('x') . e(t('remove')) . '</button>'
            . ($x['settings'] ? '<a href="?p=settings&amp;open=d-ig">' . icon('sliders') . e(t('err_acct')) . '</a>' : '')
            . '</div></aside>';
        return $h . '<ol class="slides reel"><li class="mount dark" style="--r:' . self::ROT[0] . 'deg"><span class="win">' . icon('lock') . '</span>'
            . '<span class="ml"><span>' . e($m['code']) . '</span></span>' . self::CROSS . '</li></ol></div>';
    }
}
