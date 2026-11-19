#!/bin/sh
# SPDX-License-Identifier: GPL-2.0-or-later
# Runs as root before Apache: gives the data folder to www-data (the web app and the engine both
# run as that user), then, while no password is set, writes the setup code to the log
# (docker compose logs web).
set -eu
data="${PULLPOST_DATA:-/data}"
mkdir -p "$data"
# the folder itself only: what a bind-mounted folder already holds keeps its owners
if [ "$(stat -c %u "$data")" != "$(id -u www-data)" ]; then
    chown www-data:www-data "$data"
fi
runuser -u www-data -- php /app/bin/setup-code.php || true
exec docker-php-entrypoint "$@"
