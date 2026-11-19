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

  /* ------------------------------------------------------------ the viewer */
  // A photo or video of the post opens in a modal over the page, with the post's other files a
  // click, an arrow key or a swipe away. The links keep their href: a click with a modifier key, or
  // the middle button, still opens the file in a tab of its own.
  var viewer = $('#viewer');
  var vList = [], vAt = 0;
  function viewShow(i) {
    vAt = (i + vList.length) % vList.length;
    var a = vList[vAt];
    var fit = $('#viewer-fit');
    var thumb = $('img', a);
    var media;
    fit.textContent = '';
    if (a.getAttribute('data-type') === 'video') {
      media = document.createElement('video');
      media.controls = true;
      media.autoplay = true;
      media.playsInline = true;
      if (thumb) media.poster = thumb.getAttribute('src');
    } else {
      media = document.createElement('img');
      media.alt = thumb ? thumb.alt : '';
      media.decoding = 'async';
    }
    media.src = a.getAttribute('href');
    fit.appendChild(media);
    var name = a.getAttribute('data-name') || '';
    $('#viewer-n').textContent = vList.length > 1 ? (vAt + 1) + ' / ' + vList.length : '';
    $('#viewer-name').textContent = name;
    var dl = $('#viewer-dl');
    dl.href = a.getAttribute('data-dl');
    dl.setAttribute('aria-label', fill(str.dl_name || '{name}', { name: name }));
    dl.title = dl.getAttribute('aria-label');
    $('#viewer-prev').hidden = $('#viewer-next').hidden = vList.length < 2;
    // the next photo is on its way while this one is looked at
    var next = vList[(vAt + 1) % vList.length];
    if (vList.length > 1 && next.getAttribute('data-type') !== 'video') new Image().src = next.getAttribute('href');
  }
  function viewOpen(a) {
    vList = $$('a[data-view]', a.closest('[data-viewgroup]') || a.parentNode);
    viewShow(Math.max(0, vList.indexOf(a)));
    if (!viewer.open) viewer.showModal();
  }
  // Closing stops a video and lets go of the file. Done on every way out, not only on the close
  // event, which a browser may hold back while the tab is in the background.
  function viewClear() {
    $('#viewer-fit').textContent = '';
    vList = [];
  }
  if (viewer) {
    $('#viewer-prev').addEventListener('click', function () { viewShow(vAt - 1); });
    $('#viewer-next').addEventListener('click', function () { viewShow(vAt + 1); });
    // a click on the dark around the photo closes it; the X closes it through data-dclose
    viewer.addEventListener('click', function (e) {
      if (e.target === viewer || e.target.id === 'viewer-stage' || e.target.id === 'viewer-fit') {
        viewer.close();
        viewClear();
      } else if (e.target.closest('[data-dclose]')) {
        viewClear();
      }
    });
    viewer.addEventListener('cancel', viewClear);   // Escape
    viewer.addEventListener('close', viewClear);
    viewer.addEventListener('keydown', function (e) {
      if (vList.length < 2 || e.target.tagName === 'VIDEO') return;
      if (e.key === 'ArrowLeft') { e.preventDefault(); viewShow(vAt - 1); }
      if (e.key === 'ArrowRight') { e.preventDefault(); viewShow(vAt + 1); }
    });
    var touchX = null;
    viewer.addEventListener('touchstart', function (e) { touchX = e.touches.length === 1 ? e.touches[0].clientX : null; }, { passive: true });
    viewer.addEventListener('touchend', function (e) {
      if (touchX === null || vList.length < 2) return;
      var dx = e.changedTouches[0].clientX - touchX;
      touchX = null;
      if (Math.abs(dx) > 50) viewShow(vAt + (dx < 0 ? 1 : -1));
    }, { passive: true });
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
    if (viewer && (el = t.closest('a[data-view]')) && e.button === 0 && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey) {
      e.preventDefault();
      viewOpen(el);
      return;
    }
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
