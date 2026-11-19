<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Sign in; on the first run, choose the password with the setup code from the server's log, after
// reading the notice.
declare(strict_types=1);
require __DIR__ . '/_boot.php';

if (Auth::check()) {
    redirect('index.php');
}
$first = !Auth::configured();
if ($first) {
    Auth::setupCode();
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $error = t('page_expired');
    } elseif (Auth::tooManyFails()) {
        $error = t('too_many', ['n' => (int) cfg('login_window_minutes')]);
    } elseif ($first) {
        $error = (string) Auth::choose((string) ($_POST['code'] ?? ''), (string) ($_POST['password'] ?? ''),
            (string) ($_POST['again'] ?? ''), !empty($_POST['ack']));
        if ($error === '') {
            redirect('index.php');
        }
    } elseif (Auth::attempt((string) ($_POST['password'] ?? ''))) {
        redirect('index.php');
    } else {
        $error = t('pw_wrong');
    }
}
header('Cache-Control: no-store');
$theme = Theme::current();
View::head(t($first ? 'first_t' : 'signin_t') . ' · pullpost', 'p-gate');
$theme->gateStart();
?>
<div class="sheet gate-sheet<?= $first ? ' first' : '' ?>">
  <div class="sheet-head">
    <h1><?= e(t($first ? 'first_t' : 'signin_t')) ?></h1>
    <p class="sub"><?= e(t('tagline')) ?></p>
  </div>
  <?php if ($error !== ''): ?><p class="note err" role="alert"><?= icon('alert') ?><span><?= e($error) ?></span></p><?php endif; ?>
  <?php if ($first): ?>
    <p><?= e(t('first_d')) ?></p>
    <form method="post" class="gate-form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <label class="f"><span><?= e(t('setup_code')) ?></span><input class="inp mono" name="code" value="<?= e($error !== '' ? substr((string) ($_POST['code'] ?? ''), 0, 20) : '') ?>" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" required autofocus><small><?= e(t('setup_where')) ?></small></label>
      <label class="f"><span><?= e(t('new_password')) ?> <small>(<?= e(t('min_chars', ['n' => Auth::MIN_PASSWORD])) ?>)</small></span><input class="inp" type="password" name="password" minlength="<?= Auth::MIN_PASSWORD ?>" autocomplete="new-password" required></label>
      <label class="f"><span><?= e(t('again_pw')) ?></span><input class="inp" type="password" name="again" minlength="<?= Auth::MIN_PASSWORD ?>" autocomplete="new-password" required></label>
      <section class="notice" aria-labelledby="notice-t">
        <h2 id="notice-t"><?= e(t('notice_t')) ?></h2>
        <p><?= e(t('notice_1')) ?></p>
        <p><?= e(t('notice_2')) ?></p>
        <p><?= e(t('notice_3')) ?></p>
        <p class="muted"><?= e(t('notice_4')) ?></p>
        <label class="ack"><input type="checkbox" name="ack" value="1" required><span><?= e(t('ack')) ?></span></label>
      </section>
      <button class="b" type="submit"><?= e(t('first_go')) ?></button>
    </form>
  <?php else: ?>
    <form method="post" class="gate-form" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <label class="f"><span><?= e(t('password')) ?></span><input class="inp" type="password" name="password" autocomplete="current-password" required autofocus><?php if (Demo::on()): ?><small><?= e(Demo::password() !== '' ? t('demo_pw', ['pw' => Demo::password()]) : t('demo_no_pw')) ?></small><?php endif; ?></label>
      <button class="b" type="submit"><?= e(t('signin')) ?></button>
    </form>
  <?php endif; ?>
</div>
<?php
$theme->gateEnd();
