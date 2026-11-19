<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Settings: a row opens its drawer; a drawer saves with its own button.
declare(strict_types=1);

$s = Posts::session();
$hl = Engine::health();
// the demo has no engine, no Instagram session and no password to change: those parts are left out
$tot = Posts::totals();
$pwAt = (int) Db::meta('password_at');
$themeId = Theme::id();
?>
<div class="sheet">
  <div class="sheet-head">
    <h1><?= e(t('nav_settings')) ?></h1>
    <p class="sub"><?= e(t('set_sub')) ?></p>
  </div>

  <section class="group">
    <h2><?= e(t('sec_look')) ?></h2>
    <table class="t kv">
      <thead class="vh"><tr><th><?= e(t('c_what')) ?></th><th><?= e(t('c_value')) ?></th></tr></thead>
      <tbody>
        <tr class="click" data-drawer="d-theme" tabindex="0"><th scope="row"><?= e(t('theme')) ?></th><td><?= e(t(Theme::NAMES[$themeId])) ?></td></tr>
      </tbody>
    </table>
  </section>

  <?php if (!Demo::on()): ?>
  <section class="group">
    <h2><?= e(t('sec_instagram')) ?></h2>
    <table class="t kv">
      <thead class="vh"><tr><th><?= e(t('c_what')) ?></th><th><?= e(t('c_value')) ?></th></tr></thead>
      <tbody>
        <tr class="click" data-drawer="d-ig" tabindex="0"><th scope="row"><?= e(t('your_account')) ?></th><td><?= $s['set'] ? e(t('session_set', ['d' => fmt_when($s['saved_at'])])) : '<span class="muted">' . e(t('session_unset')) . '</span>' ?></td></tr>
        <tr><th scope="row"><?= e(t('engine')) ?></th><td><?php if ($hl === null): ?><span class="tag st-err"><?= e(t('engine_down')) ?></span> <span class="muted mono"><?= e((string) cfg('engine_url')) ?></span><?php else: ?><?= e(t('engine_v', ['v' => (string) ($hl['version'] ?? '?'), 'c' => (string) $hl['chromium']])) ?>, <?= ($hl['busy'] ?? null) !== null ? e(t('engine_works', ['code' => (string) $hl['busy']])) : e(t('engine_idle')) ?><?= !empty($hl['queued']) ? ', ' . e(tn('in_line', count($hl['queued']))) : '' ?><?php endif; ?></td></tr>
      </tbody>
    </table>
  </section>
  <?php endif; ?>

  <section class="group">
    <h2><?= e(t('sec_app')) ?></h2>
    <table class="t kv">
      <thead class="vh"><tr><th><?= e(t('c_what')) ?></th><th><?= e(t('c_value')) ?></th></tr></thead>
      <tbody>
        <?php if (Demo::on()): ?>
        <tr><th scope="row"><?= e(t('demo_row')) ?></th><td><?= e(t('demo_next', ['t' => date('H:i', Demo::nextReset())])) ?></td></tr>
        <?php else: ?>
        <tr class="click" data-drawer="d-pass" tabindex="0"><th scope="row"><?= e(t('page_password')) ?></th><td class="muted"><?= $pwAt ? e(t('pw_chosen', ['d' => fmt_day($pwAt)])) : '' ?></td></tr>
        <?php endif; ?>
        <tr><th scope="row"><?= e(t('storage')) ?></th><td><?= $tot['count'] > 0 ? e(t('storage_v', ['posts' => tn('n_posts', $tot['count']), 'files' => tn('n_files', $tot['files']), 'size' => size_text($tot['bytes'])])) : '<span class="muted">' . e(t('nothing_yet')) . '</span>' ?></td></tr>
        <tr><th scope="row"><?= e(t('version')) ?></th><td class="mono">pullpost <?= e(APP_VERSION) ?></td></tr>
      </tbody>
    </table>
  </section>
</div>

<?php View::drawerStart('d-theme', t('theme')); ?>
    <form class="drawer-body" data-ajax data-act="theme">
      <fieldset class="themes">
        <legend class="vh"><?= e(t('theme')) ?></legend>
        <?php foreach (Theme::NAMES as $id => $key): ?>
          <label class="pick pick-<?= $id ?>">
            <input type="radio" name="theme" value="<?= $id ?>"<?= $id === $themeId ? ' checked' : '' ?>>
            <span class="swatch" aria-hidden="true"></span>
            <span class="pick-t"><b><?= e(t($key)) ?></b><span><?= e(t($key . '_d')) ?></span></span>
          </label>
        <?php endforeach; ?>
      </fieldset>
      <p class="muted"><?= e(t('theme_note')) ?></p>
      <div class="drawer-foot"><button type="submit" class="b sm"><?= e(t('save')) ?></button></div>
    </form>
<?php View::drawerEnd(); ?>

<?php if (!Demo::on()): ?>
<?php View::drawerStart('d-ig', t('your_account')); ?>
    <form class="drawer-body" data-ajax data-act="session_save" autocomplete="off">
      <p><?= e(t('d_ig_1')) ?></p>
      <p class="note warn"><?= icon('alert') ?><span><?= e(t('d_ig_risk')) ?></span></p>
      <ol class="howto">
        <li><?= e(t('howto_1')) ?></li>
        <li><?= e(t('howto_2')) ?></li>
        <li><?= e(t('howto_3')) ?></li>
      </ol>
      <label class="f"><span><?= e(t('sessionid_v')) ?></span><input class="inp mono" type="password" name="sessionid" autocomplete="off" spellcheck="false" data-1p-ignore required></label>
      <p class="muted small"><?= e(t('session_note')) ?></p>
      <div class="drawer-foot"><button type="submit" class="b sm"><?= e(t('save')) ?></button><?php if ($s['set']): ?><button type="button" class="b sec sm" data-act="session_clear"><?= icon('trash') ?><?= e(t('del_session')) ?></button><?php endif; ?></div>
    </form>
<?php View::drawerEnd(); ?>

<?php View::drawerStart('d-pass', t('page_password')); ?>
    <form class="drawer-body" data-ajax data-act="password" autocomplete="off">
      <label class="f"><span><?= e(t('current_pw')) ?></span><input class="inp" type="password" name="current" autocomplete="current-password" required></label>
      <label class="f"><span><?= e(t('new_password')) ?> <small>(<?= e(t('min_chars', ['n' => Auth::MIN_PASSWORD])) ?>)</small></span><input class="inp" type="password" name="password" minlength="<?= Auth::MIN_PASSWORD ?>" autocomplete="new-password" required></label>
      <label class="f"><span><?= e(t('again_pw')) ?></span><input class="inp" type="password" name="again" minlength="<?= Auth::MIN_PASSWORD ?>" autocomplete="new-password" required></label>
      <div class="drawer-foot"><button type="submit" class="b sm"><?= e(t('change_pw')) ?></button></div>
    </form>
<?php View::drawerEnd(); ?>
<?php endif; ?>
