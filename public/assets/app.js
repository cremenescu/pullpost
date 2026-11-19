// SPDX-License-Identifier: GPL-2.0-or-later
// pullpost pages: the address form posts to index.php and shows the post's card, fetched again from
// api.php every second while the engine works; buttons with data-act and drawer forms with
// data-ajax post to index.php and answer in a toast; regions marked data-live are drawn again after
// every action and every few seconds. History filters and sorts its table in the page.
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var data = {};
  try { data = JSON.parse(($('#pullpost-data') || {}).textContent || '{}'); } catch (e) { data = {}; }
  var str = data.str || {};
  var fill = function (s, vars) {
    Object.keys(vars).forEach(function (k) { s = s.split('{' + k + '}').join(String(vars[k])); });
    return s;
  };

  /* ------------------------------------------------------------ toasts */
  function toast(text, kind) {
    var box = $('#toasts');
    if (!box || !text) return;
    var t = document.createElement('div');
    t.className = 'toast ' + (kind || 'ok');
    t.setAttribute('role', kind === 'err' ? 'alert' : 'status');
    var span = document.createElement('span');
    span.textContent = text;
    var x = document.createElement('button');
    x.type = 'button';
    x.className = 'x';
    x.setAttribute('aria-label', str.close || 'Close');
    x.textContent = '×';
    t.appendChild(span);
    t.appendChild(x);
    box.appendChild(t);
    var gone = function () { t.classList.add('out'); setTimeout(function () { t.remove(); }, 220); };
    x.addEventListener('click', gone);
    if (kind !== 'err') setTimeout(gone, 3500);
  }

  /* ------------------------------------------------------------ talking to the server */
  function answer(r) {
    return r.json().catch(function () { return { error: fill(str.bad_answer || '({s})', { s: r.status }) }; })
      .then(function (j) {
        if (j.error) throw new Error(j.error);
        return j;
      });
  }

  function post(fields) {
    var body = new URLSearchParams();
    Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
    body.append('csrf', data.csrf);
    return fetch('index.php', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-CSRF': data.csrf } })
      .then(answer);
  }

  /* ------------------------------------------------------------ live regions */
  var liveTimer = null;
  function refreshLive() {
    clearTimeout(liveTimer);
    if (!$('[data-live]')) return;
    fetch(location.href, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        if (!doc.getElementById('pullpost-data')) {
          location.reload();   // signed out meanwhile: the sign-in page came back
          return;
        }
        $$('[data-live]').forEach(function (el) {
          var fresh = doc.getElementById(el.id);
          if (fresh) el.replaceWith(document.importNode(fresh, true));
        });
      })
      .catch(function () { /* the next round tries again */ })
      .then(function () { liveTimer = setTimeout(refreshLive, document.hidden ? 60000 : 15000); });
  }

  /* ------------------------------------------------------------ the post's card */
  // The till's display in theme B says what the card is doing.
  function status(j) {
    var line = $('#status-line');
    if (line) line.innerHTML = j.status || line.getAttribute('data-idle') || '';
    var vfd = $('#vfd');
    if (vfd) vfd.classList.toggle('err', j.state === 'error');
  }

  var pollRun = 0;
  function showCard(code, j) {
    var box = $('#post');
    if (!box) return;
    box.setAttribute('data-code', code);
    if (box.innerHTML !== j.html) box.innerHTML = j.html;
    status(j);
    if (j.live) poll(code);
  }

  function poll(code) {
    var run = ++pollRun;
    var step = function () {
      if (run !== pollRun) return;
      fetch('api.php?a=card&c=' + encodeURIComponent(code), { credentials: 'same-origin', cache: 'no-store' })
        .then(answer)
        .then(function (j) {
          if (run !== pollRun) return;
          var box = $('#post');
          if (box.innerHTML !== j.html) box.innerHTML = j.html;
          status(j);
          if (j.live) {
            setTimeout(step, 1000);
          } else {
            if (j.state === 'done') toast(str.done, 'ok');
            refreshLive();
          }
        })
        .catch(function (err) {
          if (run !== pollRun) return;
          toast(err.message, 'err');
          setTimeout(step, 4000);
        });
    };
    setTimeout(step, 700);
  }

  var form = $('#getform');
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var input = $('#geturl');
      var url = input.value.trim();
      if (!url) return;
      var btn = $('button[type=submit]', form);
      btn.disabled = true;
      post({ act: 'start', url: url }).then(function (j) {
        input.value = '';
        if (!$('#post')) {
          location.assign('./?c=' + encodeURIComponent(j.code));
          return;
        }
        history.replaceState(null, '', './?c=' + encodeURIComponent(j.code));
        showCard(j.code, j);
        if (j.msg) toast(j.msg, 'ok');
        refreshLive();
      }).catch(function (err) { toast(err.message, 'err'); })
        .then(function () { btn.disabled = false; });
    });
  }

  var box = $('#post');
  if (box && box.getAttribute('data-live-post') === '1') poll(box.getAttribute('data-code'));

  /* ------------------------------------------------------------ deleting, behind its dialog */
  // A post (data-del), or one file of it (data-del + data-file). On History the post's row goes
  // away in place; elsewhere the page goes back to Fetch.
  var dlg = $('#deldlg');
  var delCode = null, delFile = null, delRow = null;
  function askDelete(el) {
    delCode = el.getAttribute('data-del');
    delFile = el.getAttribute('data-file');
    delRow = el.closest('#hist tbody tr');
    $('#deldlg-t').textContent = el.getAttribute('data-title') || '';
    $('#deldlg-p').textContent = el.getAttribute('data-text') || '';
    dlg.showModal();
  }
  if (dlg) {
    $('#deldlg-go').addEventListener('click', function () {
      var btn = this;
      btn.disabled = true;
      var code = delCode, file = delFile, row = delRow;
      var req = file ? post({ act: 'delete_file', code: code, n: file }) : post({ act: 'delete', code: code });
      req.then(function (j) {
        dlg.close();
        if (file) {
          showCard(code, j);
          toast(j.msg, 'ok');
          refreshLive();
        } else if (row && dropRow) {
          dropRow(row);
          toast(j.msg, 'ok');
          refreshLive();
        } else {
          location.assign('./');
        }
      }).catch(function (err) {
        dlg.close();
        toast(err.message, 'err');
      }).then(function () { btn.disabled = false; });
    });
  }

  /* ------------------------------------------------------------ drawers */
  var lastFocus = null;
  function openDrawer(id) {
    var d = document.getElementById(id);
    if (!d) return;
    lastFocus = document.activeElement;
    $$('.drawer').forEach(function (x) { x.hidden = x !== d; });
    var err = $('.form-err', d);
    if (err) err.remove();
    d.hidden = false;
    var first = $('input:not([type=hidden]):not([type=radio]), input[type=radio]:checked, select', d);
    if (first) first.focus();
  }
  function closeDrawers() {
    var open = $$('.drawer').some(function (d) { return !d.hidden; });
    $$('.drawer').forEach(function (d) { d.hidden = true; });
    if (open && lastFocus) lastFocus.focus();
  }

  document.addEventListener('submit', function (e) {
    var f = e.target.closest('form[data-ajax]');
    if (!f) return;
    e.preventDefault();
    var fields = { act: f.getAttribute('data-act') };
    new FormData(f).forEach(function (v, k) { fields[k] = v; });
    var btn = $('button[type=submit]', f);
    btn.disabled = true;
    var old = $('.form-err', f);
    if (old) old.remove();
    post(fields).then(function (j) {
      f.reset();
      closeDrawers();
      toast(j.msg, 'ok');
      // Settings' rows say what was saved; the page is small, so it is drawn again whole
      setTimeout(function () { location.reload(); }, 700);
    }).catch(function (err) {
      var n = document.createElement('p');
      n.className = 'note err form-err';
      n.setAttribute('role', 'alert');
      n.textContent = err.message;
      $('.drawer-foot', f).before(n);
    }).then(function () { btn.disabled = false; });
  });

  /* ------------------------------------------------------------ clicks */
  document.addEventListener('click', function (e) {
    var t = e.target, el;
    if ((el = t.closest('[data-drawer]'))) { openDrawer(el.getAttribute('data-drawer')); return; }
    if ((el = t.closest('[data-close]'))) { closeDrawers(); return; }
    if ((el = t.closest('[data-dclose]'))) { el.closest('dialog').close(); return; }
    if ((el = t.closest('[data-del]'))) { askDelete(el); return; }
    if ((el = t.closest('button[data-act]'))) {
      if (el.disabled) return;
      var act = el.getAttribute('data-act');
      var fields = { act: act };
      if (el.getAttribute('data-code')) fields.code = el.getAttribute('data-code');
      el.disabled = true;
      post(fields).then(function (j) {
        if (act === 'refetch') {
          history.replaceState(null, '', './?c=' + encodeURIComponent(j.code));
          showCard(j.code, j);
          refreshLive();
        } else {
          closeDrawers();
          toast(j.msg, 'ok');
          setTimeout(function () { location.reload(); }, 700);
        }
      }).catch(function (err) { toast(err.message, 'err'); }).then(function () { el.disabled = false; });
      return;
    }
    // a row opens its post; links and buttons inside it keep their own job
    if ((el = t.closest('tr[data-href]')) && !t.closest('a, button')) {
      location.assign(el.getAttribute('data-href'));
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeDrawers(); return; }
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('tr[data-drawer]')) {
      e.preventDefault();
      openDrawer(e.target.getAttribute('data-drawer'));
    }
  });
  document.addEventListener('visibilitychange', function () { if (!document.hidden) refreshLive(); });

  /* ------------------------------------------------------------ History: filters and sorting */
  var dropRow = null;
  var hist = $('#hist');
  if (hist) {
    var rows = $$('tbody tr', hist);
    var facet = '';
    var q = $('#hist-q'), who = $('#hist-user');
    var apply = function () {
      var text = (q.value || '').trim().toLowerCase();
      var user = who ? who.value : '';
      var shown = 0;
      rows.forEach(function (r) {
        var ok = (!text || r.getAttribute('data-q').indexOf(text) !== -1)
          && (!user || r.getAttribute('data-user') === user)
          && (!facet || (facet === 'video' ? r.getAttribute('data-video') === '1' : r.getAttribute('data-st') === facet));
        r.hidden = !ok;
        if (ok) shown++;
      });
      $('#hist-n').textContent = fill(str.of || '{a}/{b}', { a: shown, b: rows.length });
      $('#hist-none').hidden = shown > 0;
    };
    // A deleted post's row leaves the table, the facet counts and the account list.
    dropRow = function (r) {
      rows = rows.filter(function (x) { return x !== r; });
      r.parentNode.removeChild(r);
      if (!rows.length) {
        location.reload();   // the page's empty state
        return;
      }
      var less = function (chip) {
        if (!chip) return;
        var c = $('.c', chip);
        c.textContent = Math.max(0, Number(c.textContent) - 1);
      };
      less($('#hist-bar [data-f=""]'));
      less($('#hist-bar [data-f="' + r.getAttribute('data-st') + '"]'));
      if (r.getAttribute('data-video') === '1') less($('#hist-bar [data-f="video"]'));
      var u = r.getAttribute('data-user');
      if (who && u) {
        var opt = $$('option', who).filter(function (o) { return o.value === u; })[0];
        if (opt) {
          var left = (Number((opt.textContent.match(/\((\d+)\)$/) || [])[1]) || 1) - 1;
          if (left > 0) {
            opt.textContent = u + ' (' + left + ')';
          } else {
            if (who.value === u) who.value = '';
            who.removeChild(opt);
          }
        }
      }
      apply();
    };
    q.addEventListener('input', apply);
    if (who) who.addEventListener('change', apply);
    $$('#hist-bar [data-f]').forEach(function (c) {
      c.addEventListener('click', function () {
        facet = c.getAttribute('data-f');
        $$('#hist-bar [data-f]').forEach(function (x) {
          x.classList.toggle('on', x === c);
          x.setAttribute('aria-pressed', x === c ? 'true' : 'false');
        });
        apply();
      });
    });
    $$('th[data-sort]', hist).forEach(function (th) {
      $('button', th).addEventListener('click', function () {
        var key = th.getAttribute('data-sort');
        var asc = th.classList.contains('sorted') ? !th.classList.contains('asc') : key === 'name';
        $$('th[data-sort]', hist).forEach(function (x) {
          x.classList.remove('sorted', 'asc', 'desc');
          x.setAttribute('aria-sort', 'none');
        });
        th.classList.add('sorted', asc ? 'asc' : 'desc');
        th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');
        var num = key !== 'name';
        rows.sort(function (a, b) {
          var x = a.getAttribute('data-' + key), y = b.getAttribute('data-' + key);
          var d = num ? Number(x) - Number(y) : x.localeCompare(y);
          return asc ? d : -d;
        });
        var body = $('tbody', hist);
        rows.forEach(function (r) { body.appendChild(r); });
      });
    });
  }

  /* ------------------------------------------------------------ start */
  var params = new URLSearchParams(location.search);
  if (params.get('open')) openDrawer(params.get('open'));
  liveTimer = setTimeout(refreshLive, 15000);
}());
