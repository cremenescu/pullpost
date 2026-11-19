# Security policy

## Reporting a vulnerability

pullpost keeps a password for its page and, if you set one, the session cookie of an Instagram
account. If you find a vulnerability (anything that gets past the password, leaks that session,
lets someone read files outside the data folder, reaches the engine from outside, or runs code),
**please do not open a public issue**.

Email me instead:

**razvan@cremenescu.ro**

Use the subject line `[pullpost security]`. If you want to use PGP, ask in the first message and
I'll send a key.

I'll acknowledge within a few days, work on a fix, and credit you in the release notes unless you
prefer to stay anonymous.

## Scope

In scope:

- The web app (`public/`, `lib/`, `bin/`): sign-in, the setup code, CSRF, the rate limit, the file
  downloads, the delete actions.
- The engine (`engine/`): the hosts it accepts media from, how it starts Chromium, its HTTP API.
- The images and the compose file as shipped.

Out of scope:

- Instagram changing its pages so that a post no longer downloads: that is a bug, please open an
  issue.
- The engine's API having no authentication when you publish its port yourself: the compose file
  keeps it on the internal network on purpose.
- The app served over plain HTTP on a network you do not trust: put it behind a proxy with HTTPS
  (see the README).
- Scenarios that already assume access to the host, the Docker socket or the data volume.

## Supported versions

Only the latest release receives fixes.

| Version | Supported |
|---|---|
| latest release | yes |
| anything else | no |
