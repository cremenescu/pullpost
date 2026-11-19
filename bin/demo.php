<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// The demo's snapshot, from the command line (see "A public demo" in README.md):
//   php bin/demo.php snapshot [folder]
//                               copies the history as it is now into data/demo-seed/, or into folder
//   php bin/demo.php reset      puts the snapshot back now, without waiting for the next period
//   php bin/demo.php status     what the snapshot holds and when the next reset is
// In the container: docker compose exec -u www-data web php bin/demo.php <command>
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/lib/bootstrap.php';

$cmd = $argv[1] ?? 'status';
switch ($cmd) {
    case 'snapshot':
        $to = (string) ($argv[2] ?? '');
        $n = Demo::snapshot($to);
        printf("pullpost: snapshot of %d finished post(s) in %s\n", $n, $to !== '' ? $to : Demo::seedDir());
        if (!Demo::on()) {
            echo "pullpost: the demo starts from it once PULLPOST_DEMO=1 is set\n";
        }
        exit(0);
    case 'reset':
        if (!Demo::hasSeed()) {
            fwrite(STDERR, "pullpost: no snapshot yet; make one with: php bin/demo.php snapshot\n");
            exit(1);
        }
        Demo::reset();
        printf("pullpost: the history is back to the snapshot, %d post(s)\n", count(Posts::all()));
        exit(0);
    case 'status':
        $seed = Demo::hasSeed() ? json_decode((string) file_get_contents(Demo::seedDir() . '/db.json'), true) : null;
        printf("demo mode:  %s\n", Demo::on() ? 'on' : 'off (PULLPOST_DEMO is not 1)');
        printf("password:   %s\n", Demo::password() !== '' ? 'set' : 'not set (PULLPOST_DEMO_PASSWORD)');
        printf("snapshot:   %s\n", is_array($seed)
            ? sprintf('%d post(s), made %s with pullpost %s', count($seed['posts'] ?? []), date('Y-m-d H:i', (int) ($seed['made_at'] ?? 0)), (string) ($seed['version'] ?? '?'))
            : 'none');
        printf("next reset: %s\n", Demo::on() ? date('Y-m-d H:i', Demo::nextReset()) : '-');
        exit(0);
}
fwrite(STDERR, "usage: php bin/demo.php snapshot|reset|status\n");
exit(2);
