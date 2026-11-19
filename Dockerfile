# SPDX-License-Identifier: GPL-2.0-or-later
# The web app: PHP with Apache. It shares the data volume with the engine (engine/Dockerfile) and
# runs as the same user, www-data (33).
FROM php:8.3-apache

# GD, for the small pictures in the lists. The -dev packages are needed only to build it: what the
# extension links against is kept, the rest removed (the pattern of the official images).
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev; \
    docker-php-ext-configure gd --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" gd; \
    apt-mark auto '.*' > /dev/null; \
    [ -z "$savedAptMark" ] || apt-mark manual $savedAptMark; \
    extDir="$(php -r 'echo ini_get("extension_dir");')"; \
    ldd "$extDir"/*.so \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
        | sort -u \
        | xargs -r dpkg-query --search \
        | grep -v '^diversion ' \
        | cut -d: -f1 \
        | sort -u \
        | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    printf 'expose_php = Off\nmemory_limit = 256M\n' > "$PHP_INI_DIR/conf.d/pullpost.ini"; \
    mkdir -p /data; \
    chown www-data:www-data /data

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/pullpost-entrypoint
COPY bin /app/bin
COPY config /app/config
COPY lang /app/lang
COPY lib /app/lib
COPY public /app/public

ENV PULLPOST_DATA=/data \
    PULLPOST_LOG=stderr
WORKDIR /app
VOLUME /data
EXPOSE 80
ENTRYPOINT ["pullpost-entrypoint"]
CMD ["apache2-foreground"]
