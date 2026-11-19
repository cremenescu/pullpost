<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Prints the code asked for on the first sign-in, making one if there is none yet:
//   docker compose exec -u www-data web php bin/setup-code.php
// The container runs it at start too, so the code is in `docker compose logs web`.
// Once the password is chosen there is no code any more, and this says so.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/lib/bootstrap.php';

// announced on stderr, which is the container log when the entrypoint runs it
if (Auth::setupCode(true) === null) {
    echo "pullpost: the password is already set; to change it, run bin/set-password.php\n";
}
