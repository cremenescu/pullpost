# SPDX-License-Identifier: GPL-2.0-or-later
# Copyright (C) 2026 Răzvan Cremenescu
"""The pullpost download engine: one post at a time, over a tiny HTTP API.

GET  /health          -> {"ok": true, "version": "...", "chromium": "...", "busy": code|null,
                          "queued": [...], "session": bool}
POST /fetch           body {"code": "...", "kind": "p|reel|tv"} -> 202 {"queued": true}
                      The job's progress is written to <data>/jobs/<code>.json, the files and
                      manifest.json to <data>/posts/<code>/; the web app reads both from disk.

The Instagram session (for posts that need a login) is read from <data>/ig-session.json, written
by the web app's Settings page, and used only when a post does not show without it.

The API has no authentication: it must be reachable only by the web app (in the compose file the
engine publishes no port).

Environment: DATA (the app's data folder), CHROME (the browser binary), PORT, BIND, UMASK,
TZ (dates in file names), USER_AGENT (for the CDN downloads).
"""
import json
import os
import queue
import sys
import threading
import time
import traceback
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import igfetch  # noqa: E402

DATA = os.environ.get('DATA', '/data')
CHROME = os.environ.get('CHROME', 'chromium')
PORT = int(os.environ.get('PORT', '8898'))
BIND = os.environ.get('BIND', '0.0.0.0')
if os.environ.get('UMASK'):
    os.umask(int(os.environ['UMASK'], 8))

JOBS = os.path.join(DATA, 'jobs')
POSTS = os.path.join(DATA, 'posts')
SESSION_FILE = os.path.join(DATA, 'ig-session.json')

work = queue.Queue()
lock = threading.Lock()
state = {'busy': None, 'queued': []}
VERSION = igfetch.chrome_version(CHROME)


def write_json(path, data):
    tmp = path + '.tmp'
    with open(tmp, 'w') as f:
        json.dump(data, f, ensure_ascii=False)
    os.replace(tmp, path)


def read_session():
    try:
        with open(SESSION_FILE) as f:
            value = (json.load(f) or {}).get('sessionid', '')
    except (OSError, ValueError):
        return None
    return value if igfetch.SESSION_RE.match(value or '') else None


def run(code, kind):
    path = os.path.join(JOBS, code + '.json')
    job = {'code': code, 'kind': kind, 'state': 'opening', 'started_at': int(time.time())}

    def progress(**kw):
        job.update(kw)
        job['updated_at'] = int(time.time())
        write_json(path, job)

    try:
        manifest = igfetch.fetch(CHROME, code, kind, os.path.join(POSTS, code), read_session(), progress)
        progress(state='done', finished_at=int(time.time()), total=len(manifest['files']), done=len(manifest['files']))
    except igfetch.FetchError as err:
        progress(state='error', error_kind=err.kind, error=str(err), finished_at=int(time.time()))
    except Exception as err:
        traceback.print_exc()
        progress(state='error', error_kind='failed', error='%s: %s' % (type(err).__name__, err), finished_at=int(time.time()))


def worker():
    while True:
        code, kind = work.get()
        with lock:
            state['queued'].remove(code)
            state['busy'] = code
        try:
            run(code, kind)
        finally:
            with lock:
                state['busy'] = None


class Handler(BaseHTTPRequestHandler):
    server_version = 'pullpost-engine/' + igfetch.VERSION

    def log_message(self, fmt, *args):
        sys.stderr.write('%s %s\n' % (time.strftime('%Y-%m-%d %H:%M:%S'), fmt % args))

    def reply(self, code, data):
        body = json.dumps(data, ensure_ascii=False).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path not in ('/', '/health'):
            return self.reply(404, {'error': 'not found'})
        with lock:
            snap = {'busy': state['busy'], 'queued': list(state['queued'])}
        self.reply(200, {'ok': True, 'version': igfetch.VERSION, 'chromium': VERSION, 'session': read_session() is not None, **snap})

    def do_POST(self):
        if self.path != '/fetch':
            return self.reply(404, {'error': 'not found'})
        try:
            length = int(self.headers.get('Content-Length') or 0)
            body = json.loads(self.rfile.read(min(length, 4096)) or b'{}')
        except ValueError:
            return self.reply(400, {'error': 'bad json'})
        code, kind = str(body.get('code', '')), str(body.get('kind', 'p'))
        if not igfetch.CODE_RE.match(code) or kind not in igfetch.KINDS:
            return self.reply(400, {'error': 'bad code or kind'})
        with lock:
            if code == state['busy'] or code in state['queued']:
                return self.reply(202, {'queued': True, 'already': True})
            state['queued'].append(code)
        write_json(os.path.join(JOBS, code + '.json'),
                   {'code': code, 'kind': kind, 'state': 'queued', 'queued_at': int(time.time()), 'updated_at': int(time.time())})
        work.put((code, kind))
        self.reply(202, {'queued': True})


def main():
    os.makedirs(JOBS, exist_ok=True)
    os.makedirs(POSTS, exist_ok=True)
    # A job cut short by a restart would otherwise show as working for ever.
    for name in os.listdir(JOBS):
        if not name.endswith('.json'):
            continue
        path = os.path.join(JOBS, name)
        try:
            with open(path) as f:
                job = json.load(f)
        except (OSError, ValueError):
            continue
        if job.get('state') not in ('done', 'error'):
            job.update(state='error', error_kind='failed', error='The engine restarted during the download.',
                       finished_at=int(time.time()))
            write_json(path, job)
    threading.Thread(target=worker, daemon=True).start()
    print('pullpost engine %s on %s:%d, %s, data %s' % (igfetch.VERSION, BIND, PORT, VERSION, DATA), file=sys.stderr, flush=True)
    ThreadingHTTPServer((BIND, PORT), Handler).serve_forever()


if __name__ == '__main__':
    main()
