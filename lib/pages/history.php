<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// History: every post ever asked for, newest first. Search, facets, the account list and the
// column sorting all run in the page (app.js), over the rows' data- attributes.
declare(strict_types=1);

$rows = array_map([Card::class, 'row'], Posts::all());
$users = [];
$facet = ['done' => 0, 'video' => 0, 'error' => 0, 'work' => 0];
foreach ($rows as $r) {
    if ($r['user'] !== '') {
        $users[$r['user']] = ($users[$r['user']] ?? 0) + 1;
    }
    $facet[$r['st']]++;
    if ($r['videos'] > 0) {
        $facet['video']++;
    }
}
ksort($users, SORT_NATURAL | SORT_FLAG_CASE);
$tot = Posts::totals();
$n = count($rows);
?>
<div class="sheet wide">
  <div class="sheet-head">
    <h1><?= e(t('nav_history')) ?></h1>
    <p class="sub" id="hist-sub" data-live><?= $tot['count'] > 0 ? e(tn('n_posts', $tot['count']) . ', ' . tn('n_files', $tot['files']) . ', ' . size_text($tot['bytes'])) : e(t('nothing_yet')) ?></p>
  </div>

<?php if (!$rows): ?>
  <p class="empty"><?= e(t('hist_empty')) ?></p>
<?php else: ?>
  <div class="toolbar" id="hist-bar">
    <label class="search-box"><?= icon('search') ?><input class="inp" type="search" id="hist-q" placeholder="<?= e(t('search')) ?>" aria-label="<?= e(t('search')) ?>"></label>
    <div class="chips" role="group" aria-label="<?= e(t('filters')) ?>">
      <button type="button" class="chip on" data-f="" aria-pressed="true"><?= e(t('f_all')) ?> <span class="c"><?= $n ?></span></button>
      <button type="button" class="chip" data-f="done" aria-pressed="false"><?= e(t('f_done')) ?> <span class="c"><?= $facet['done'] ?></span></button>
      <?php if ($facet['video'] > 0): ?><button type="button" class="chip" data-f="video" aria-pressed="false"><?= e(t('f_video')) ?> <span class="c"><?= $facet['video'] ?></span></button><?php endif; ?>
      <?php if ($facet['error'] > 0): ?><button type="button" class="chip" data-f="error" aria-pressed="false"><?= e(t('f_error')) ?> <span class="c"><?= $facet['error'] ?></span></button><?php endif; ?>
      <?php if ($facet['work'] > 0): ?><button type="button" class="chip" data-f="work" aria-pressed="false"><?= e(t('f_work')) ?> <span class="c"><?= $facet['work'] ?></span></button><?php endif; ?>
    </div>
    <?php if (count($users) > 1): ?>
      <select class="inp" id="hist-user" aria-label="<?= e(t('k_account')) ?>">
        <option value=""><?= e(t('all_accounts')) ?></option>
        <?php foreach ($users as $u => $c): ?><option value="<?= e($u) ?>"><?= e($u) ?> (<?= $c ?>)</option><?php endforeach; ?>
      </select>
    <?php endif; ?>
    <span class="count" id="hist-n"><?= e(t('k_of', ['a' => $n, 'b' => $n])) ?></span>
  </div>

  <div class="tablewrap">
  <table class="t sortable" id="hist">
    <thead><tr>
      <th class="th-pic"><span class="vh"><?= e(t('c_pic')) ?></span></th>
      <th data-sort="name" aria-sort="none"><button type="button"><?= e(t('c_post')) ?></button></th>
      <th data-sort="taken" class="wide-only" aria-sort="none"><button type="button"><?= e(t('c_posted')) ?></button></th>
      <th data-sort="at" class="sorted desc" aria-sort="descending"><button type="button"><?= e(t('c_fetched')) ?></button></th>
      <th data-sort="files" class="num" aria-sort="none"><button type="button"><?= e(t('c_files')) ?></button></th>
      <th data-sort="bytes" class="num wide-only" aria-sort="none"><button type="button"><?= e(t('c_size')) ?></button></th>
      <th class="act"><span class="vh"><?= e(t('c_acts')) ?></span></th>
    </tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="click" data-href="<?= e($r['href']) ?>"
            data-q="<?= e(mb_strtolower($r['user'] . ' ' . $r['code'])) ?>" data-user="<?= e($r['user']) ?>"
            data-st="<?= $r['st'] ?>" data-video="<?= $r['videos'] > 0 ? '1' : '0' ?>"
            data-name="<?= e(mb_strtolower(($r['user'] ?: '~') . ' ' . $r['code'])) ?>" data-taken="<?= $r['taken'] ?>"
            data-at="<?= $r['at'] ?>" data-files="<?= $r['files'] ?>" data-bytes="<?= $r['bytes'] ?>">
          <td class="th"><?php if ($r['small'] !== null): ?><img loading="lazy" src="<?= e($r['small']) ?>" alt=""><?php endif; ?></td>
          <td class="who"><a href="<?= e($r['href']) ?>"><?php if ($r['user'] !== ''): ?><b><?= e($r['user']) ?></b> <?php endif; ?><span class="code"><?= e($r['code']) ?></span></a><?php if ($r['kind'] !== 'p'): ?> <span class="tag"><?= e($r['kind']) ?></span><?php endif; ?></td>
          <td class="at wide-only"><?= e($r['taken_text']) ?></td>
          <td class="at"><?= e($r['when']) ?></td>
          <td class="num"><?= $r['st'] === 'done' ? e($r['counts']) : ($r['st'] === 'error' ? '<span class="tag st-err">' . e(t('st_error')) . '</span>' : '<span class="tag">' . e(t('st_work')) . '</span>') ?></td>
          <td class="num wide-only"><?= $r['st'] === 'done' ? e($r['size']) : '' ?></td>
          <td class="act"><?php if ($r['zip'] !== null): ?><a class="b sec sm" href="<?= e($r['zip']) ?>" download><?= icon('zip') ?>ZIP</a><?php endif; ?><button type="button" class="b ghost sm"<?= Card::delAttrs($r['del']) ?> title="<?= e(t('del')) ?>" aria-label="<?= e(t('del_name', ['name' => $r['title']])) ?>"><?= icon('trash') ?></button></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="empty" id="hist-none" hidden><?= e(t('no_match')) ?></p>
<?php endif; ?>
</div>
