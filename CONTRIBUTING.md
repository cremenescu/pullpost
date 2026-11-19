# Contributing to pullpost

Thanks for your interest! pullpost is a small self-hosted app that saves the photos and videos of
an Instagram post as files. Help is welcome.

## Reporting bugs

Open a GitHub issue using the **Bug report** template. The most useful things to include are:

- the pullpost version (Settings, Version);
- how you run it (the compose file as it is, or changed) and `docker compose version`;
- the kind of post (photo, carousel, reel, video) and whether it shows without an account;
- what you expected and what happened instead, with the message the page showed.

**Do not paste** the address of a private post, your `sessionid` or anything from
`ig-session.json`. Logs (`docker compose logs`) can contain post addresses and, before the first
sign-in, the setup code: read them before you paste them.

Instagram changes its pages without notice, so "a public post stopped downloading" is a welcome
report: include the date and, if the post is public, its address.

For security issues, do **not** open a public issue. See [SECURITY.md](SECURITY.md).

## Suggesting a feature

Use the **Feature request** template. Be concrete about the problem you are trying to solve.
Features that make the tool collect more than one post at a time (whole profiles, hashtags,
scheduled runs) are out of scope.

## Sending a pull request

1. Run it locally first: `docker compose up -d --build`, or the PHP and Python commands in the
   README's Development section.
2. Keep the change focused: one feature or fix per PR.
3. Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/):
   `feat: ...`, `fix: ...`, `docs: ...`, `build: ...`.
4. Follow the existing conventions:
   - Identifiers and comments in English.
   - An SPDX header at the top of every new source file:
     `// SPDX-License-Identifier: GPL-2.0-or-later` (or `#` in Python, shell and YAML).
   - 4-space indent, no tabs.
   - Every user-visible string goes through `t('key')` (or `tn()` for counts) and is added to
     **both** `lang/en.php` and `lang/ro.php`.
   - The engine uses the Python standard library only; the web app needs no Composer packages.
5. `php bin/smoke.php` must pass. If you change what the page shows, check both themes (Settings,
   Theme), in light and dark, and at phone width.
6. Open the PR against `main` with a short description of what changed and why.

I review PRs personally and won't always be fast. If a PR sits for more than a couple of weeks,
ping me on the PR or by email (`razvan@cremenescu.ro`).

## Translating

1. Copy `lang/en.php` to `lang/<code>.php`, where `<code>` is the two-letter language code.
2. Translate the values; leave the keys alone. Placeholders in braces (`{n}`, `{code}`) must stay
   as they are.
3. Counts are arrays of plural forms: English has `one` and `other`; Romanian adds `few`. Use the
   forms your language needs; `I18n::pluralForm()` in `lib/helpers.php` picks them, so a language
   with other rules needs a line there too.
4. Add the language to `I18n::LANGS` in `lib/helpers.php`, then run `php bin/smoke.php`: it checks
   that every language has the same keys as English.

A partial translation is fine to open as a draft PR.

## License

By contributing, you agree that your contribution is released under the project's license,
[GPL-2.0-or-later](LICENSE).
