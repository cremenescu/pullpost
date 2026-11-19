# SPDX-License-Identifier: GPL-2.0-or-later
# Copyright (C) 2026 Răzvan Cremenescu
"""Fetch every photo and video of one Instagram post into a folder.

Chromium runs headless and is driven over the DevTools protocol through a pipe
(--remote-debugging-pipe): nothing listens on a port and no websocket client is needed. The post's
JSON is found inside the page by walking the page's scripts; the files are then downloaded here,
only from Instagram's CDN hosts.

Command line, for tests: python3 igfetch.py <shortcode> [p|reel|tv] <out_dir>
"""
import fcntl
import json
import os
import re
import select
import shutil
import signal
import struct
import sys
import tempfile
import time
import urllib.request
import zipfile
from datetime import datetime
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

CODE_RE = re.compile(r'^[A-Za-z0-9_-]{5,64}$')
KINDS = ('p', 'reel', 'tv')
CDN_RE = re.compile(r'^https://[a-z0-9.-]+\.(fbcdn\.net|cdninstagram\.com)/', re.I)
SESSION_RE = re.compile(r'^[A-Za-z0-9%:._-]{10,400}$')
VERSION = '0.1.0'
USER_AGENT = os.environ.get('USER_AGENT') or 'pullpost/' + VERSION
MAX_FILE_BYTES = 1024 * 1024 * 1024
OPEN_TIMEOUT = 45


def _zone():
    """The time zone of the dates in file names: TZ from the environment, UTC when unset or unknown."""
    try:
        return ZoneInfo(os.environ.get('TZ') or 'UTC')
    except (ValueError, ZoneInfoNotFoundError):
        return ZoneInfo('UTC')


TZ = _zone()


class FetchError(Exception):
    """A failure the person can understand. kind: login, notfound, failed."""

    def __init__(self, kind, message):
        super().__init__(message)
        self.kind = kind


class ChromeError(Exception):
    pass


# Runs inside the post page. Returns the post found in the page's JSON (if any) and whether the page
# is Instagram's login page. %CODE% is replaced with the shortcode, JSON-quoted.
EXTRACT_JS = r'''(() => {
  const code = %CODE%;
  const out = {href: location.href, title: document.title,
    login: location.pathname.startsWith('/accounts/login') || !!document.querySelector('input[name="username"]'),
    // logged out, a deleted post and one of a private account both get "Post isn't available"
    gone: /isn.t available/i.test(document.title)};
  const posts = [];
  const seen = new WeakSet();
  const walk = (o, depth) => {
    if (!o || typeof o !== 'object' || seen.has(o) || depth > 80) return;
    seen.add(o);
    if (o.code && (o.carousel_media || o.image_versions2)) posts.push(o);
    for (const k in o) walk(o[k], depth + 1);
  };
  for (const s of document.scripts) {
    const t = s.textContent || '';
    if (!t.includes('image_versions2')) continue;
    try { walk(JSON.parse(t), 0); } catch (e) {}
  }
  const found = posts.filter(p => p.code === code)
    .sort((a, b) => (b.carousel_media?.length || 0) - (a.carousel_media?.length || 0));
  if (!found.length) return out;
  const post = found[0];
  const items = post.carousel_media?.length ? post.carousel_media : [post];
  const area = x => (x.width || 0) * (x.height || 0);
  // sort is stable: when the candidates carry no sizes, the first one (the largest) stays first
  const best = list => (list || []).slice().sort((a, b) => area(b) - area(a))[0];
  out.post = {
    code: post.code,
    user: post.user?.username || '',
    taken_at: post.taken_at || 0,
    items: items.map(m => {
      const img = best(m.image_versions2?.candidates);
      const vid = m.media_type === 2 ? best(m.video_versions) : null;
      const src = vid || img || {};
      return {type: vid ? 'video' : 'image', url: src.url || null, cover: img?.url || null,
        w: src.width || m.original_width || 0, h: src.height || m.original_height || 0};
    }),
  };
  return out;
})()'''


def _high_fd(fd):
    """Moves a descriptor above 9, so the dup2 onto 3 and 4 in the child never is a no-op."""
    new = fcntl.fcntl(fd, fcntl.F_DUPFD_CLOEXEC, 10)
    os.close(fd)
    return new


class Chrome:
    """One headless Chromium with a fresh profile, spoken to over fds 3 and 4."""

    def __init__(self, binary):
        self.profile = tempfile.mkdtemp(prefix='pullpost-')
        to_r, to_w = (_high_fd(x) for x in os.pipe())
        from_r, from_w = (_high_fd(x) for x in os.pipe())
        devnull = _high_fd(os.open(os.devnull, os.O_RDWR))
        args = [binary, '--headless=new', '--remote-debugging-pipe', '--no-first-run',
                '--no-default-browser-check', '--disable-gpu', '--disable-dev-shm-usage',
                '--disable-extensions', '--disable-background-networking', '--disable-sync',
                '--mute-audio', '--hide-scrollbars', '--user-data-dir=' + self.profile]
        # Inside the container there are no user namespaces for Chromium's own sandbox; the
        # container (no capabilities, no new privileges, a memory cap) is the boundary there.
        if os.environ.get('CHROME_NO_SANDBOX') == '1':
            args.append('--no-sandbox')
        args.append('about:blank')
        actions = [(os.POSIX_SPAWN_DUP2, to_r, 3), (os.POSIX_SPAWN_DUP2, from_w, 4),
                   (os.POSIX_SPAWN_DUP2, devnull, 0), (os.POSIX_SPAWN_DUP2, devnull, 1),
                   (os.POSIX_SPAWN_DUP2, devnull, 2)]
        try:
            self.pid = os.posix_spawn(binary, args, os.environ, file_actions=actions, setsid=True)
        finally:
            for fd in (to_r, from_w, devnull):
                os.close(fd)
        self.w, self.r = to_w, from_r
        self.buf = b''
        self.last_id = 0

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        self.close()

    def call(self, method, params=None, session=None, timeout=20):
        self.last_id += 1
        mid = self.last_id
        msg = {'id': mid, 'method': method, 'params': params or {}}
        if session:
            msg['sessionId'] = session
        data = json.dumps(msg).encode() + b'\0'
        while data:
            data = data[os.write(self.w, data):]
        deadline = time.monotonic() + timeout
        while True:
            reply = self._read(deadline)
            if reply.get('id') != mid:
                continue  # an event
            if 'error' in reply:
                raise ChromeError(reply['error'].get('message', 'error'))
            return reply.get('result', {})

    def _read(self, deadline):
        while b'\0' not in self.buf:
            left = deadline - time.monotonic()
            if left <= 0:
                raise ChromeError('Chromium did not answer in time')
            ready, _, _ = select.select([self.r], [], [], left)
            if not ready:
                continue
            chunk = os.read(self.r, 1 << 16)
            if not chunk:
                raise ChromeError('Chromium closed the pipe')
            self.buf += chunk
        raw, self.buf = self.buf.split(b'\0', 1)
        return json.loads(raw)

    def close(self):
        try:
            self.call('Browser.close', timeout=5)
        except Exception:
            pass
        for _ in range(50):
            try:
                if os.waitpid(self.pid, os.WNOHANG)[0]:
                    break
            except ChildProcessError:
                break
            time.sleep(0.1)
        else:
            try:
                os.killpg(self.pid, signal.SIGKILL)
                os.waitpid(self.pid, 0)
            except (ProcessLookupError, ChildProcessError):
                pass
        for fd in (self.w, self.r):
            try:
                os.close(fd)
            except OSError:
                pass
        shutil.rmtree(self.profile, ignore_errors=True)


def chrome_version(binary):
    import subprocess
    try:
        out = subprocess.run([binary, '--version'], capture_output=True, text=True, timeout=20)
        return out.stdout.strip()
    except Exception as err:
        return 'unknown (%s)' % err


def open_post(binary, code, kind, cookies=None):
    """Loads the post page and returns what EXTRACT_JS saw last (post found, login page, or neither)."""
    url = 'https://www.instagram.com/%s/%s/' % (kind, code)
    js = EXTRACT_JS.replace('%CODE%', json.dumps(code))
    with Chrome(binary) as c:
        if cookies:
            c.call('Storage.setCookies', {'cookies': cookies})
        target = c.call('Target.createTarget', {'url': 'about:blank'})['targetId']
        sid = c.call('Target.attachToTarget', {'targetId': target, 'flatten': True})['sessionId']
        c.call('Page.navigate', {'url': url}, sid)
        deadline = time.monotonic() + OPEN_TIMEOUT
        seen = {}
        while time.monotonic() < deadline:
            time.sleep(0.7)
            try:
                res = c.call('Runtime.evaluate', {'expression': js, 'returnByValue': True}, sid)
            except ChromeError:
                continue  # the page is between documents
            value = (res.get('result') or {}).get('value')
            if isinstance(value, dict):
                seen = value
                if value.get('post'):
                    return value
                elapsed = time.monotonic() - (deadline - OPEN_TIMEOUT)
                # "Post isn't available", or a login page that stays put, is the answer
                if value.get('gone') and elapsed > 3:
                    return value
                if value.get('login') and elapsed > 8:
                    return value
        return seen


class _CdnOnly(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        if not CDN_RE.match(newurl):
            raise FetchError('failed', 'The CDN redirected outside Instagram: ' + newurl[:80])
        return super().redirect_request(req, fp, code, msg, headers, newurl)


_opener = urllib.request.build_opener(_CdnOnly)

EXT_BY_TYPE = {'image/jpeg': 'jpg', 'image/webp': 'webp', 'image/png': 'png', 'image/heic': 'heic',
               'video/mp4': 'mp4', 'video/quicktime': 'mov'}


def download(url, path, on_chunk):
    """Saves url to path; returns (content type, bytes)."""
    if not url or not CDN_RE.match(url):
        raise FetchError('failed', 'A media address outside Instagram\'s CDN was refused.')
    req = urllib.request.Request(url, headers={'User-Agent': USER_AGENT})
    size = 0
    with _opener.open(req, timeout=60) as r, open(path, 'wb') as f:
        ctype = (r.headers.get('Content-Type') or '').split(';')[0].strip().lower()
        while True:
            chunk = r.read(1 << 16)
            if not chunk:
                break
            size += len(chunk)
            if size > MAX_FILE_BYTES:
                raise FetchError('failed', 'A file was larger than 1 GB.')
            f.write(chunk)
            on_chunk(len(chunk))
    return ctype, size


def image_size(path):
    """(width, height) of a JPEG or PNG from its header, or (0, 0)."""
    try:
        with open(path, 'rb') as f:
            head = f.read(26)
            if head[:8] == b'\x89PNG\r\n\x1a\n':
                return struct.unpack('>II', head[16:24])
            if head[:2] != b'\xff\xd8':
                return 0, 0
            f.seek(2)
            while True:
                marker = f.read(2)
                if len(marker) < 2 or marker[0] != 0xFF:
                    return 0, 0
                if marker[1] in (0xD8, 0x01) or 0xD0 <= marker[1] <= 0xD7:
                    continue
                length = struct.unpack('>H', f.read(2))[0]
                if marker[1] in (0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF):
                    h, w = struct.unpack('>HH', f.read(5)[1:5])
                    return w, h
                f.seek(length - 2, 1)
    except (OSError, struct.error):
        return 0, 0


def file_stem(post):
    """<date posted>_<user>_<code>, the date in the TZ time zone."""
    day = datetime.fromtimestamp(post['taken_at'], TZ).strftime('%Y-%m-%d') if post.get('taken_at') else 'undated'
    user = re.sub(r'[^A-Za-z0-9._]', '', post.get('user') or '') or 'instagram'
    return '%s_%s_%s' % (day, user, post['code'])


def fetch(binary, code, kind, out_dir, session=None, progress=lambda **kw: None):
    """Downloads the post into out_dir (replaced as a whole at the end) and returns its manifest."""
    if not CODE_RE.match(code) or kind not in KINDS:
        raise FetchError('failed', 'Invalid post code.')
    progress(state='opening', mode='anon')
    t0 = time.time()
    seen = open_post(binary, code, kind)
    mode = 'anon'
    if not seen.get('post') and session:
        progress(state='opening', mode='account')
        cookie = {'name': 'sessionid', 'value': session, 'domain': '.instagram.com', 'path': '/',
                  'secure': True, 'httpOnly': True}
        seen = open_post(binary, code, kind, [cookie])
        mode = 'account'
    if not seen.get('post'):
        if seen.get('login'):
            raise FetchError('login', 'Instagram asked for a login' + (' even with the saved account.' if session else '.'))
        raise FetchError('notfound', 'The post is not in the page (deleted, private, or the address is wrong).')
    post = seen['post']
    items = [i for i in post['items'] if i.get('url')]
    if not items:
        raise FetchError('notfound', 'The post has no media addresses.')
    opened = round(time.time() - t0, 1)
    progress(state='downloading', mode=mode, total=len(items), done=0, bytes=0, opened_s=opened,
             user=post['user'], taken_at=post['taken_at'])

    stem = file_stem(post)
    parent = os.path.dirname(os.path.abspath(out_dir))
    work = tempfile.mkdtemp(prefix='.%s-' % code, dir=parent)
    # mkdtemp makes 0700; the web app reads these files, possibly as another user
    mask = os.umask(0)
    os.umask(mask)
    os.chmod(work, 0o777 & ~mask)
    try:
        os.mkdir(os.path.join(work, 'thumbs'))
        got = {'bytes': 0}

        def add(n):
            got['bytes'] += n

        files = []
        for n, item in enumerate(items, 1):
            tmp = os.path.join(work, 'part')
            ctype, size = download(item['url'], tmp, add)
            ext = EXT_BY_TYPE.get(ctype) or ('mp4' if item['type'] == 'video' else 'jpg')
            name = '%s_%02d.%s' % (stem, n, ext)
            os.replace(tmp, os.path.join(work, name))
            w, h = item['w'], item['h']
            thumb = None
            if item['type'] == 'image':
                fw, fh = image_size(os.path.join(work, name))
                if fw:
                    w, h = fw, fh
            elif item.get('cover'):
                thumb = 'thumbs/%02d.jpg' % n
                download(item['cover'], os.path.join(work, thumb), lambda _n: None)
            files.append({'n': n, 'name': name, 'type': item['type'], 'bytes': size, 'w': w, 'h': h, 'thumb': thumb})
            progress(state='downloading', done=n, bytes=got['bytes'])

        progress(state='zipping')
        zip_name = stem + '.zip'
        with zipfile.ZipFile(os.path.join(work, zip_name), 'w', zipfile.ZIP_STORED) as z:
            for f in files:
                z.write(os.path.join(work, f['name']), f['name'])
        manifest = {'code': code, 'kind': kind, 'user': post['user'], 'taken_at': post['taken_at'],
                    'mode': mode, 'files': files,
                    'zip': {'name': zip_name, 'bytes': os.path.getsize(os.path.join(work, zip_name))},
                    'fetched_at': int(time.time()), 'opened_s': opened}
        with open(os.path.join(work, 'manifest.json'), 'w') as f:
            json.dump(manifest, f, ensure_ascii=False, indent=1)
        if os.path.isdir(out_dir):
            shutil.rmtree(out_dir)
        os.rename(work, out_dir)
        return manifest
    except BaseException:
        shutil.rmtree(work, ignore_errors=True)
        raise


if __name__ == '__main__':
    if len(sys.argv) != 4:
        sys.exit(__doc__)
    chrome = os.environ.get('CHROME', 'chromium')
    m = fetch(chrome, sys.argv[1], sys.argv[2], sys.argv[3],
              progress=lambda **kw: print('  ', json.dumps(kw), file=sys.stderr))
    print(json.dumps({k: v for k, v in m.items() if k != 'files'}, ensure_ascii=False))
    for f in m['files']:
        print(' ', f['name'], f['type'], f['bytes'], '%sx%s' % (f['w'], f['h']), f['thumb'] or '')
