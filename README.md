# pullpost

[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

**English** · [Română](README.ro.md) · [Project page](https://cremenescu.ro/en/pullpost/)

A small self-hosted web app that saves the photos and videos of one Instagram post as files:
every image of a carousel and every video of a reel, at the highest resolution Instagram serves,
each file on its own plus one ZIP with all of them.

Not affiliated with, endorsed by or sponsored by Instagram or Meta Platforms, Inc. "Instagram" is a
trademark of Meta Platforms, Inc. and appears here only to say what the tool works with.

## What it does

- You paste the address of a post (`/p/`), a reel (`/reel/`) or `/tv/`. Query strings such as
  `?img_index=` are fine, and so is the bare post code.
- The server opens the post in a headless Chromium, logged out, reads the post's data from the
  page and downloads each photo and video from Instagram's CDN, choosing the largest version.
- You get every file separately and a ZIP. File names start with the date of the post, so they
  sort by date in a folder: `2026-11-18_<account>_<code>_01.jpg`.
- Everything stays in the history until you delete it, a whole post or a single file (the ZIP is
  made again without it). One post is fetched at a time; the page follows the progress live.
- Two looks, chosen in Settings: a light table with the files laid out as slides, or a receipt
  printer. Both follow the light or dark setting of your system.
- The interface is in English and Romanian, picked from the browser and switchable on every page.

## How it works

Two containers that share one data volume:

- **web**: PHP 8.3 with Apache and SQLite. The pages, the history, the files and the ZIPs.
- **engine**: Python 3 (standard library only) and Debian's Chromium. It drives Chromium through
  the DevTools protocol over a pipe (`--remote-debugging-pipe`), so no debugging port is opened.
  Media is downloaded only from Instagram's CDN hosts (`*.fbcdn.net`, `*.cdninstagram.com`); any
  other address, or a redirect elsewhere, is refused.

The engine's small HTTP API has no authentication of its own, so the compose file publishes no
port for it: only the web container reaches it. Chromium runs without its own sandbox inside a
container with no capabilities, no new privileges and a memory cap.

## Install

You need Docker with Compose v2. The engine image includes Chromium, so it is the larger of the two.

```sh
git clone https://github.com/cremenescu/pullpost.git
cd pullpost
docker compose up -d --build
docker compose logs web
```

The last command shows a line like `pullpost: setup code for the first sign-in: ABCD-EFGH`. Open
`http://<this machine>:8080`, enter that code, choose the password for the page (8 characters or
more) and confirm you have read the notice. The code proves the server is yours; it is deleted
once the password is set.

### Settings

Put them in a `.env` file next to `compose.yaml`, then run `docker compose up -d` again.

| Variable | Default | What it does |
|---|---|---|
| `PULLPOST_PORT` | `8080` | The port of the page. `127.0.0.1:8080` keeps it on this machine only. |
| `TZ` | `UTC` | Time zone for the dates in file names and on the pages, e.g. `Europe/Bucharest`. |
| `PULLPOST_TRUST_PROXY` | `0` | `1` behind a reverse proxy: see below. |

Two more are read by the containers and can be added to `compose.yaml` if you need them:
`PULLPOST_THEME` (`a` or `b`, the look before one is chosen in Settings) on `web`, and `USER_AGENT`
(the header of the CDN downloads, `pullpost/<version>` by default) on `engine`.

### Behind a reverse proxy

The app has its own password, but it is meant for a home network or behind a proxy that adds
HTTPS, not for a port open to the internet. Behind a proxy, set `PULLPOST_PORT=127.0.0.1:8080`
and `PULLPOST_TRUST_PROXY=1`, and have the proxy send `X-Forwarded-Proto` and `X-Real-IP` (or
`X-Forwarded-For`). The session cookie is then marked `Secure`, and the limit on wrong passwords
counts the visitor's address instead of the proxy's.

The app's links are relative, so it also works under a path (`/pullpost/`); have the proxy rewrite
the path of the cookies to it. With one path per language, the proxy can name the language of the
path in an `X-Pullpost-Lang` header (`en` or `ro`, read only with `PULLPOST_TRUST_PROXY=1`): the
first page a visitor opens is in that language, and the EN / RO buttons change it from there.

### Everyday commands

```sh
# a forgotten password: choose a new one (signs out every browser)
docker compose exec -u www-data web php bin/set-password.php

# update: your data stays in the volume
git pull && docker compose up -d --build

# stop, keeping the data
docker compose down

# remove everything, the downloads included
docker compose down -v
```

The data volume is `pullpost_data`. To back it up:

```sh
docker run --rm -v pullpost_data:/data -v "$PWD":/backup busybox tar czf /backup/pullpost-data.tgz -C /data .
```

### A public demo

`compose.demo.yaml` runs pullpost as a demo anyone can try. It is the web container alone, without
the engine, so the demo never talks to Instagram. Visitors sign in with a password that the
sign-in page shows, open the posts of a history you prepared, download their files and ZIPs,
delete, and change the look and the language. Settings keep only the look, which is each
visitor's own (a cookie). Every hour the history goes back to its snapshot. Every page has a band
saying it is a demo, with a link to this repository, and asks search engines not to index it.

1. In an ordinary install, fetch the posts the demo should show (posts you have the right to
   publish) and take the snapshot:

   ```sh
   docker compose exec -u www-data web php bin/demo.php snapshot
   ```

2. In a clone of its own for the demo, put the snapshot next to `compose.demo.yaml`, as a folder
   named `demo-seed` that the container's `www-data` user can read (from the install of step 1:
   `docker compose cp web:/data/demo-seed <the demo's clone>/demo-seed`).

3. Choose the demo's password in a `.env` file next to `compose.demo.yaml` and start it:

   ```sh
   docker compose -f compose.demo.yaml up -d --build
   ```

| Variable | Default | What it does |
|---|---|---|
| `PULLPOST_DEMO_PASSWORD` | none, required | The password; the sign-in page shows it. |
| `PULLPOST_DEMO_PORT` | `127.0.0.1:8081` | The demo's port, and the address it listens on. |
| `PULLPOST_DEMO_RESET_MINUTES` | `60` | How often the history goes back to the snapshot. |
| `PULLPOST_DEMO_ABOUT` | empty | A page about the demo, linked from the band; `{lang}` becomes `en` or `ro`. |
| `PULLPOST_TRUST_PROXY` | `0` | `1` behind a reverse proxy, as above. |

`docker compose -f compose.demo.yaml exec -u www-data web php bin/demo.php reset` puts the snapshot
back at once, and `status` instead of `reset` tells what the snapshot holds and when the next reset
is. The demo mode itself is the `PULLPOST_DEMO=1` setting of the web container.

## Your Instagram account (optional)

Logged out, the tool sees only what anyone sees without an account. Some posts show only to a
signed-in user. For those you can paste the `sessionid` cookie of your own Instagram account in
Settings (in a browser signed in to instagram.com: developer tools, Application or Storage,
Cookies, `sessionid`). The engine still tries every post logged out first and uses the session
only when Instagram asks for a login.

Read the risk before you do it: that is automated access while logged in, which Instagram's terms
forbid and the ruling below does not cover. Instagram may ask for checks, limit or disable that
account, and the cookie is a full login to it. It is kept in the data volume, readable only by the
app's user, and Settings can remove it at any time.

## Responsible use

- **For personal use.** The photos and videos belong to their authors. Keep only content you have
  the right to keep: your own posts, content shared with you for that purpose, or what the law in
  your country allows. Do not republish what you download.
- **Instagram's Terms of Use** forbid collecting content by automated means without Instagram's
  permission. Using this tool may breach those terms, and that is your responsibility.
- **Logged out**, the tool sees only what anyone sees without an account. In *Meta Platforms v.
  Bright Data* (N.D. Cal., January 2024) the court held that Meta's terms did not prohibit
  logged-off scraping of publicly available data. It decided nothing about logged-in scraping.
  This is not legal advice, and courts elsewhere may see it differently.
- Similar tools, such as [instaloader](https://github.com/instaloader/instaloader),
  [gallery-dl](https://github.com/mikf/gallery-dl) and [yt-dlp](https://github.com/yt-dlp/yt-dlp),
  have been public and maintained for years.
- Instagram changes its pages without notice. The tool can stop working at any time, and it comes
  with no warranty.

## Privacy

The app talks only to Instagram: the post page and the CDN. There is no telemetry, no update check
and no third-party font or script; the fonts are in the repository. Downloads, the history and the
Instagram session (if you set one) stay in the data volume on your machine.

## Development

The web app runs on any PHP 8.2 or newer with the `gd` and `pdo_sqlite` extensions. Its checks run
offline, with no engine and no network, on a throwaway data folder:

```sh
php bin/smoke.php
```

The engine runs on its own with a local Chrome or Chromium:

```sh
DATA=/tmp/pullpost-data CHROME=/usr/bin/chromium BIND=127.0.0.1 PORT=8898 python3 -I engine/server.py
```

Then point the web app at it with `PULLPOST_ENGINE_URL=http://127.0.0.1:8898` and
`PULLPOST_DATA=/tmp/pullpost-data`, for example with PHP's own server:
`php -S 127.0.0.1:8080 -t public`.

See [CONTRIBUTING.md](CONTRIBUTING.md) for pull requests and translations, and
[SECURITY.md](SECURITY.md) for reporting a vulnerability.

## License

Copyright (C) 2026 Răzvan Cremenescu. pullpost is free software, released under the
[GNU General Public License v2.0 or later](LICENSE).

The fonts in `public/assets/fonts/` (Schibsted Grotesk, Courier Prime, Martian Mono) are under the
SIL Open Font License 1.1; their license files are next to them.
