<!--
Thanks for the PR! A few quick checks before you submit.
Delete sections that don't apply.
-->

## What changed and why

<!-- One short paragraph. What problem does this solve? -->

## Type of change

- [ ] Bug fix (non-breaking change that fixes an issue)
- [ ] New feature (non-breaking change that adds functionality)
- [ ] Breaking change (would change existing behaviour or the data folder)
- [ ] Build / packaging / docs only (no runtime code change)

## How I tested

<!-- Did you run it and try it? With which kind of post (photo, carousel, reel)? Do not paste
     private post addresses or a sessionid. -->

- How it ran: <!-- docker compose / PHP + Python locally -->
- Steps to verify:

## Checklist

- [ ] My commits follow [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, `build:`, ...).
- [ ] New source files have the SPDX header (`GPL-2.0-or-later`).
- [ ] Comments in code are in English.
- [ ] Any new user-visible string goes through `t('key')` and is in **both** `lang/en.php` and `lang/ro.php`.
- [ ] `php bin/smoke.php` passes.
- [ ] If the page changed, I checked both themes, light and dark, and at phone width.

## Related issues

<!-- Closes #123 / Refs #456 -->
