<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare(strict_types=1);

// The parts every page shares, whatever the theme: the head, the icons, the navigation, the
// language buttons, the delete dialog, the toasts and the drawers of Settings. Regions marked
// data-live are drawn again by app.js after an action and every few seconds.
final class View
{
    public const PAGES = ['fetch' => 'nav_fetch', 'history' => 'nav_history', 'settings' => 'nav_settings'];

    public static function asset(string $file): string
    {
        return 'assets/' . $file . '?v=' . (int) @filemtime(APP_ROOT . '/public/assets/' . $file);
    }

    public static function head(string $title, string $bodyClass = ''): void
    {
        $theme = Theme::id();
        ?><!doctype html>
<html lang="<?= e(I18n::lang()) ?>" data-skin="<?= e($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="referrer" content="no-referrer">
<?php if (Demo::on()): ?><meta name="robots" content="noindex, nofollow">
<?php endif; ?><title><?= e($title) ?></title>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(self::asset('fonts/fonts.css')) ?>">
<link rel="stylesheet" href="<?= e(self::asset('base.css')) ?>">
<link rel="stylesheet" href="<?= e(self::asset($theme . '.css')) ?>">
</head>
<body<?= $bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '' ?>>
<?php
        self::icons();
        echo self::demoBand();
    }

    /** The band at the top of every page of the demo: what it is, the source and the project page. */
    public static function demoBand(): string
    {
        if (!Demo::on()) {
            return '';
        }
        $min = max(5, (int) cfg('demo_reset_minutes'));
        $when = $min === 60 ? t('demo_hourly') : tn('demo_every', $min);
        $h = '<div class="demo-band" role="note"><span>' . e(t('demo_band', ['v' => APP_VERSION, 'when' => $when])) . '</span>'
            . '<a href="' . e(Demo::SOURCE) . '" rel="noopener">' . e(t('demo_source')) . '</a>';
        if (Demo::about() !== '') {
            $h .= '<a href="' . e(Demo::about()) . '" rel="noopener">' . e(t('demo_about')) . '</a>';
        }
        return $h . "</div>\n";
    }

    public static function icons(): void
    {
        ?><svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="i-down" viewBox="0 0 24 24"><path d="M12 4v11"/><path d="M7 10l5 5 5-5"/><path d="M5 20h14"/></symbol>
  <symbol id="i-zip" viewBox="0 0 24 24"><path d="M6 3h9l4 4v14H6z"/><path d="M15 3v4h4"/><path d="M11 6h1M11 9h1M11 12h1M10.5 15h2v3h-2z"/></symbol>
  <symbol id="i-ext" viewBox="0 0 24 24"><path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></symbol>
  <symbol id="i-play" viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5z"/></symbol>
  <symbol id="i-check" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></symbol>
  <symbol id="i-dot" viewBox="0 0 24 24"><circle cx="12" cy="12" r="2.5"/></symbol>
  <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
  <symbol id="i-sliders" viewBox="0 0 24 24"><path d="M4 7h9M17 7h3M4 17h3M11 17h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/></symbol>
  <symbol id="i-trash" viewBox="0 0 24 24"><path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13"/><path d="M9 7V4h6v3"/></symbol>
  <symbol id="i-redo" viewBox="0 0 24 24"><path d="M20 12a8 8 0 1 1-2.34-5.66"/><path d="M20 4v5h-5"/></symbol>
  <symbol id="i-x" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></symbol>
  <symbol id="i-prev" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></symbol>
  <symbol id="i-next" viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></symbol>
  <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></symbol>
  <symbol id="i-out" viewBox="0 0 24 24"><path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 17l5-5-5-5"/><path d="M15 12H4"/></symbol>
  <symbol id="i-lock" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></symbol>
  <symbol id="i-link" viewBox="0 0 24 24"><path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/></symbol>
  <symbol id="i-alert" viewBox="0 0 24 24"><path d="M12 3.5l9.5 16.5h-19z"/><path d="M12 10v4.5"/><path d="M12 17.5h.01"/></symbol>
</svg>
<?php
    }

    /** History, Settings, the language buttons and Sign out, the same in both themes. */
    public static function nav(string $page): string
    {
        $links = [
            'history' => ['?p=history', 'clock', t('nav_history')],
            'settings' => ['?p=settings', 'sliders', t('nav_settings')],
        ];
        $h = '<nav class="nav">';
        foreach ($links as $id => [$href, $ic, $label]) {
            $h .= '<a href="' . e($href) . '"' . ($page === $id ? ' class="on" aria-current="page"' : '') . '>' . icon($ic) . '<span class="lbl">' . e($label) . '</span></a>';
        }
        $h .= self::lang();
        $h .= '<a href="logout.php" title="' . e(t('signout')) . '" aria-label="' . e(t('signout')) . '">' . icon('out') . '</a>';
        return $h . '</nav>';
    }

    /** EN / RO: links that set the language cookie and come back to the same page. */
    public static function lang(): string
    {
        $q = $_GET;
        $h = '<span class="lang" role="group" aria-label="' . e(t('language')) . '">';
        foreach (I18n::LANGS as $code => $label) {
            $q['lang'] = $code;
            $on = I18n::lang() === $code;
            $h .= '<a href="?' . e(http_build_query($q)) . '" hreflang="' . $code . '" lang="' . $code . '"' . ($on ? ' class="on" aria-current="true"' : '') . '>' . $label . '</a>';
        }
        return $h . '</span>';
    }

    /** The address form; $class is the theme's. */
    public static function getForm(string $inner): string
    {
        return '<form class="getform" id="getform" autocomplete="off">' . $inner . '</form>';
    }

    public static function end(): void
    {
        $js = [
            'csrf' => csrf_token(),
            'str' => [
                'close' => t('close'),
                'done' => t('done_toast'),
                'bad_answer' => t('bad_answer'),
                'of' => t('k_of'),
                'dl_name' => t('dl_name'),
            ],
        ];
        ?>
<dialog class="dlg" id="deldlg" aria-labelledby="deldlg-t">
  <div class="dlg-h"><h2 id="deldlg-t"></h2><button type="button" class="b ghost sm" data-dclose aria-label="<?= e(t('close')) ?>"><?= icon('x') ?></button></div>
  <div class="dlg-b"><p id="deldlg-p"></p></div>
  <div class="dlg-a"><button type="button" class="b danger" id="deldlg-go"><?= icon('trash') ?><?= e(t('del')) ?></button><button type="button" class="b sec" data-dclose><?= e(t('cancel')) ?></button></div>
</dialog>
<dialog class="viewer" id="viewer" aria-label="<?= e(t('viewer')) ?>">
  <div class="v-bar"><span class="v-n" id="viewer-n"></span><span class="v-name" id="viewer-name"></span><a class="v-btn" id="viewer-dl" href="#" download aria-label="" title=""><?= icon('down') ?></a><button type="button" class="v-btn" data-dclose aria-label="<?= e(t('close')) ?>" title="<?= e(t('close')) ?>"><?= icon('x') ?></button></div>
  <div class="v-stage" id="viewer-stage"><div class="v-fit" id="viewer-fit"></div></div>
  <button type="button" class="v-nav prev" id="viewer-prev" aria-label="<?= e(t('prev')) ?>" title="<?= e(t('prev')) ?>"><?= icon('prev') ?></button>
  <button type="button" class="v-nav next" id="viewer-next" aria-label="<?= e(t('next')) ?>" title="<?= e(t('next')) ?>"><?= icon('next') ?></button>
</dialog>
<div class="toasts" id="toasts" aria-live="polite"></div>
<script type="application/json" id="pullpost-data"><?= json_encode($js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= e(self::asset('app.js')) ?>"></script>
</body>
</html>
<?php
    }

    // ------------------------------------------------------------ drawers (Settings)

    public static function drawerStart(string $id, string $title): void
    {
        ?><div class="drawer" id="<?= e($id) ?>" hidden>
  <div class="drawer-backdrop" data-close></div>
  <div class="drawer-panel" role="dialog" aria-modal="true" aria-label="<?= e($title) ?>">
    <div class="drawer-head"><h2><?= e($title) ?></h2><button type="button" class="b ghost sm" data-close aria-label="<?= e(t('close')) ?>"><?= icon('x') ?></button></div>
<?php
    }

    public static function drawerEnd(): void
    {
        echo "  </div>\n</div>\n";
    }
}
