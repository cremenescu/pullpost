<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Sets the sign-in password from the command line, for when it is forgotten:
//   docker compose exec -u www-data web php bin/set-password.php
// Asks for it twice (hidden) and stores only the bcrypt hash. Every browser signed in is signed out.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/lib/bootstrap.php';

// Asked once, before anything is read: stream_isatty() after a read drops what is buffered, so
// with the passwords piped in the second one would be lost.
$tty = stream_isatty(STDIN);

function ask(string $prompt, bool $tty): string
{
    fwrite(STDOUT, $prompt);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $v = rtrim((string) fgets(STDIN), "\r\n");
    if ($tty) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }
    return $v;
}

$a = ask('New password: ', $tty);
$b = ask('Once more: ', $tty);
if ($a !== $b) {
    fwrite(STDERR, "The two passwords differ. Nothing was changed.\n");
    exit(1);
}
if (mb_strlen($a) < Auth::MIN_PASSWORD) {
    fwrite(STDERR, 'The password needs at least ' . Auth::MIN_PASSWORD . " characters. Nothing was changed.\n");
    exit(1);
}
Auth::setPassword($a);
echo "The password was changed. Every browser was signed out.\n";
