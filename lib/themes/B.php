<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare(strict_types=1);

// Theme B, "receipt": the link goes into a till's display, and every fetch prints a receipt that
// lists each file as a line item with its size; the total is the post, and the ZIP is a coupon you
// tear off. Earlier receipts sit on the spike. One family, Martian Mono, at different widths.
final class ThemeB extends Theme
{
    public function shellStart(string $page): void
    {
        ?><svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <filter id="ink" x="-5%" y="-10%" width="110%" height="120%">
    <feTurbulence type="fractalNoise" baseFrequency="1.1" numOctaves="2" seed="7" result="n"/>
    <feDisplacementMap in="SourceGraphic" in2="n" scale="2.4" xChannelSelector="R" yChannelSelector="G" result="d"/>
    <feColorMatrix in="n" type="matrix" values="0 0 0 0 0  0 0 0 0 0  0 0 0 0 0  -2.2 0 0 0 1.75" result="m"/>
    <feComposite in="d" in2="m" operator="in"/>
  </filter>
</svg>
<header class="top">
  <a class="brand" href="./">pullpost</a>
  <?= View::nav($page) ?>
</header>
<main class="desk page-<?= e($page) ?>">
<?php
    }

    public function shellEnd(): void
    {
        ?>
</main>
<footer class="foot"><?= e(t('disclaimer')) ?></footer>
<?php
        View::end();
    }

    public function gateStart(): void
    {
        ?><header class="top">
  <span class="brand">pullpost</span>
  <nav class="nav"><?= View::lang() ?></nav>
</header>
<main class="desk gate">
<?php
    }

    public function gateEnd(): void
    {
        ?>
</main>
<footer class="foot"><?= e(t('disclaimer')) ?></footer>
</body>
</html>
<?php
    }

    // ------------------------------------------------------------ the Fetch page

    public function fetchPage(?string $current): void
    {
        $card = $current !== null ? Card::render($current) : null;
        $idle = e(self::engineLine());
        $line = $card !== null && $card['status'] !== '' ? $card['status'] : $idle;
        ?>
<div class="left">
  <form class="till" id="getform" autocomplete="off">
    <label class="vfd<?= $card !== null && $card['state'] === 'error' ? ' err' : '' ?>" id="vfd"><input type="text" inputmode="url" name="url" id="geturl" placeholder="<?= e(t('paste')) ?>" aria-label="<?= e(t('post_link')) ?>" spellcheck="false" autocapitalize="off" required<?= $current === null ? ' autofocus' : '' ?>><span class="l2" id="status-line" data-idle="<?= e($idle) ?>" aria-live="polite"><?= $line ?></span></label>
    <div class="keys"><p class="hint"><?= e(t('hint')) ?></p><button class="ok" type="submit"><span class="ret" aria-hidden="true">&#8629;</span><span><?= e(t('fetch')) ?></span></button></div>
  </form>
  <?= $this->spike($current) ?>
</div>
<div class="printer"><div class="slot" aria-hidden="true"></div><div id="post" data-code="<?= e((string) $current) ?>" data-live-post="<?= $card !== null && $card['live'] ? '1' : '0' ?>"><?= $card['html'] ?? $this->empty() ?></div></div>
<?php
    }

    private function empty(): string
    {
        $any = (int) Db::value('SELECT count(*) FROM posts') > 0;
        return '<div class="empty"><b>' . e(t('empty_t')) . '</b>' . e(t($any ? 'empty_d' : 'empty_first')) . '</div>';
    }

    /** Earlier receipts, torn off and pushed onto the spike. */
    private function spike(?string $current): string
    {
        $rows = Posts::recent(5);
        if (!$rows) {
            return '<section class="spike" id="recent-live" data-live hidden></section>';
        }
        $h = '<section class="spike" id="recent-live" data-live><div class="spike-h"><h2>' . e(t('earlier')) . '</h2>'
            . '<a class="more" href="?p=history">' . e(t('all_history')) . '</a></div><ul>';
        foreach ($rows as $r) {
            $x = Card::row($r);
            $pic = $x['small'] !== null ? '<img loading="lazy" src="' . e($x['small']) . '" alt="">' : '<span class="ph" aria-hidden="true"></span>';
            $line = match ($x['st']) {
                'done' => $x['counts'] . ', ' . $x['size'],
                'error' => t('st_error'),
                default => t('st_work'),
            };
            $h .= '<li class="st-' . $x['st'] . ($x['code'] === $current ? ' cur' : '') . '"><a class="stub" href="' . e($x['href']) . '"><span class="s">' . $pic
                . '<b>' . e($x['user'] !== '' ? $x['user'] : $x['code']) . '</b>'
                . ($x['user'] !== '' ? '<span class="m">' . e($x['code']) . '</span>' : '')
                . '<span class="m">' . e($line) . '</span><span class="m">' . e($x['when']) . '</span></span></a></li>';
        }
        return $h . '</ul></section>';
    }

    // ------------------------------------------------------------ the receipt

    public function status(array $m): string
    {
        if ($m['state'] === 'work') {
            $w = $m['work'];
            if ($w['total'] < 1) {
                return e(t('printing')) . '<span class="blk">…</span>';
            }
            return e(t('printing') . ' ' . $w['done'] . '/' . $w['total']) . '<span class="blk">'
                . str_repeat('█', $w['done']) . str_repeat('░', max(0, $w['total'] - $w['done'])) . '</span>';
        }
        if ($m['state'] === 'error') {
            return e($m['error']['kind'] === 'login' ? t('login_needed') : $m['error']['title']);
        }
        return '';
    }

    public function card(array $m): string
    {
        return match ($m['state']) {
            'done' => $this->done($m),
            'work' => $this->printing($m),
            'error' => $this->declined($m),
            default => '<div class="empty"><b>' . e($m['code']) . '</b>' . e(t('gone')) . '</div>',
        };
    }

    private static function line(string $k, string $v, string $cls = ''): string
    {
        return '<div class="line' . ($cls !== '' ? ' ' . $cls : '') . '"><span class="k">' . e($k) . '</span><span class="lead"></span><span class="v">' . e($v) . '</span></div>';
    }

    /** Bars drawn from the shortcode's own bits, so every receipt carries a different one. */
    private static function bars(string $code): string
    {
        $seq = [1, 1, 2, 1];
        foreach (str_split($code) as $ch) {
            $c = ord($ch);
            for ($b = 0; $b < 6; $b++) {
                $seq[] = ($c >> $b) & 1 ? 2 : 1;
            }
            $seq[] = 1;
        }
        array_push($seq, 2, 1, 1, 1);
        $x = 0;
        $rects = '';
        foreach ($seq as $k => $w) {
            if ($k % 2 === 0) {
                $rects .= '<rect x="' . $x . '" width="' . $w . '" height="40"/>';
            }
            $x += $w;
        }
        return '<svg class="bars" viewBox="0 0 ' . $x . ' 40" preserveAspectRatio="none" fill="currentColor" aria-hidden="true">' . $rects . '</svg>';
    }

    private function head(): string
    {
        return '<header class="r-head"><span class="shop">PULLPOST</span><span class="tagline">' . e(t('tagline')) . '</span></header><hr class="rule">';
    }

    private function done(array $m): string
    {
        $one = $m['single'];
        $ratio = $m['files'] ? $m['files'][0]['ratio'] : '4 / 5';
        $h = '<div class="paper"><article class="receipt">' . $this->head()
            . self::line(t('receipt'), $m['code'])
            . ($m['user'] !== '' ? self::line(t('k_account'), $m['user']) : '')
            . ($m['taken'] !== '' ? self::line(t('k_posted'), $m['taken']) : '')
            . self::line(t('k_fetched'), $m['fetched']) . self::line(t('k_mode'), $m['mode_text']) . '<hr class="rule">';
        if ($m['refetch_error'] !== null) {
            $h .= '<div class="msg warn" role="alert">' . e($m['refetch_error']) . '</div><hr class="rule">';
        }

        $h .= '<div class="thumbs' . ($one !== null ? ' one' : '') . '" style="--ratio:' . e($ratio) . '">';
        foreach ($m['files'] as $f) {
            $h .= '<a href="' . e($f['view']) . '" target="_blank" rel="noopener" title="' . e(t('open_name', ['name' => $f['name']])) . '">'
                . '<img loading="lazy" src="' . e($f['small']) . '" alt="' . e(ucfirst($f['label'])) . '">' . ($f['type'] === 'video' ? icon('play') : '') . '</a>';
        }
        $h .= '</div><ol class="items">';
        foreach ($m['files'] as $f) {
            $star = $m['pointed'] === $f['n'] ? '*' : '';
            $h .= '<li><span class="n">' . $f['nn'] . $star . '</span><span>' . e($f['ext']) . ($f['dims'] !== '' ? ' <span class="dim">' . e(str_replace(' × ', '×', $f['dims'])) . '</span>' : '') . '</span>'
                . '<span class="lead"></span><span>' . e($f['size']) . '</span>'
                . '<a href="' . e($f['href']) . '" download title="' . e($f['name']) . '" aria-label="' . e(t('dl_name', ['name' => $f['name']])) . '">' . icon('down') . '</a>'
                . ($one === null ? '<button type="button" class="rm"' . Card::delAttrs($f['del']) . ' title="' . e(t('del_name', ['name' => $f['label']])) . '" aria-label="' . e(t('del_name', ['name' => $f['name']])) . '">' . icon('trash') . '</button>' : '')
                . '</li>';
        }
        $h .= '</ol><hr class="rule double"><div class="total"><span>' . e(t('total')) . '</span><span class="lead"></span><span>' . e($m['size']) . '</span></div>'
            . '<div class="log">' . e($m['counts']) . '</div>';
        if ($m['pointed'] !== null) {
            $h .= '<div class="log">* ' . e(t('pointed', ['n' => sprintf('%02d', $m['pointed'])])) . '</div>';
        }

        if ($m['zip'] !== null) {
            $h .= '<div class="coupon"><span class="sc" aria-hidden="true">&#9986;</span><p class="what">' . e(t('coupon')) . '</p>'
                . '<a class="get" href="' . e($m['zip']['href']) . '" download>' . icon('zip') . e(t('dl_all')) . ', ZIP ' . e($m['zip']['size']) . '</a>'
                . '<span class="fn">' . e($m['zip']['name']) . '</span></div>';
        } elseif ($one !== null) {
            $h .= '<div class="coupon"><span class="sc" aria-hidden="true">&#9986;</span><p class="what">' . e($one['ext'] . ($one['dims'] !== '' ? ', ' . $one['dims'] : '')) . '</p>'
                . '<a class="get" href="' . e($one['href']) . '" download>' . icon('down') . e(t('dl')) . ' ' . e($one['size']) . '</a>'
                . '<span class="fn">' . e($one['name']) . '</span></div>';
        }
        $h .= self::bars($m['code']) . '<div class="bcode">' . e($m['code']) . '</div>'
            . '<div class="r-acts"><a href="' . e($m['url']) . '" target="_blank" rel="noopener noreferrer">' . icon('ext') . e(t('open')) . '</a>'
            . '<button type="button" data-act="refetch" data-code="' . e($m['code']) . '">' . icon('redo') . e(t('again')) . '</button>'
            . '<button type="button" class="del"' . Card::delAttrs($m['del']) . '>' . icon('trash') . e(t('del')) . '</button></div>'
            . '<span class="stamp">' . e(t('stamp_ok')) . '</span></article></div>';
        return $h;
    }

    private function printing(array $m): string
    {
        $w = $m['work'];
        $h = '<div class="paper printing"><article class="receipt">' . $this->head()
            . self::line(t('receipt'), $m['code'])
            . ($w['job_user'] !== '' ? self::line(t('k_account'), $w['job_user']) : '')
            . self::line(t('k_mode'), t($w['account'] ? 'mode_account' : 'mode_anon')) . '<hr class="rule">';
        foreach ($w['steps'] as [$cls, $text, $at]) {
            if ($cls === 'wait') {
                continue;
            }
            $h .= $at !== '' ? self::line($text, $at, 'log' . ($cls === 'now' ? ' now' : '')) : '<div class="log' . ($cls === 'now' ? ' now' : '') . '">' . e($text) . '</div>';
        }
        if ($w['total'] > 0) {
            $h .= '<ol class="items">';
            for ($i = 1; $i <= $w['done']; $i++) {
                $h .= '<li><span class="n">' . sprintf('%02d', $i) . '</span><span class="lead"></span><span>OK</span></li>';
            }
            if ($w['done'] < $w['total']) {
                $h .= '<li><span class="n">' . sprintf('%02d', $w['done'] + 1) . '</span><span class="cursor" aria-hidden="true"></span></li>';
            }
            $h .= '</ol>';
        } else {
            $h .= '<div class="log"><span class="cursor" aria-hidden="true"></span></div>';
        }
        return $h . '</article></div>';
    }

    private function declined(array $m): string
    {
        $x = $m['error'];
        return '<div class="paper"><article class="receipt">' . $this->head()
            . self::line(t('receipt'), $m['code']) . '<hr class="rule">'
            . '<div class="msg"><b>' . e($x['title']) . '</b>' . e($x['why']) . '</div>'
            . ($x['detail'] !== '' ? '<div class="log detail">' . e($x['detail']) . '</div>' : '')
            . '<div class="r-acts start"><button type="button" class="main" data-act="refetch" data-code="' . e($m['code']) . '">' . icon('redo') . e(t('retry')) . '</button>'
            . '<a href="' . e($m['url']) . '" target="_blank" rel="noopener noreferrer">' . icon('ext') . e(t('open')) . '</a>'
            . '<button type="button"' . Card::delAttrs($m['del']) . '>' . icon('x') . e(t('remove')) . '</button></div>'
            . ($x['settings'] ? '<a class="acct" href="?p=settings&amp;open=d-ig">' . e(t('err_acct')) . '</a>' : '')
            . '<span class="stamp no">' . e(t('stamp_err')) . '</span></article></div>';
    }
}
