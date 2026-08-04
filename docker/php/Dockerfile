# The MySQL client binaries are lifted from the official server image so they
# match the server version exactly.
#
# WHY NOT the distro's mysql-client: on both Alpine and Debian that package is
# MariaDB's client, and MariaDB's client cannot talk to a default MySQL 8.4
# server at all. Two independent blockers, both hit while building this:
#
#   1. MySQL 8.4 enables TLS with a self-signed certificate by default.
#      MariaDB's client verifies it and refuses to connect; MySQL's does not.
#   2. MySQL 8's default auth is caching_sha2_password. MariaDB's client has no
#      such plugin and fails with "could not be loaded" before it can even
#      authenticate.
#
# Teaching the backup tool to work around either would be papering over the
# wrong binary. Backups have to be trustworthy, so we ship the right client.
FROM mysql:8.4 AS mysqlclient

# Debian rather than Alpine, because the client binaries above are glibc-linked
# and will not run against musl.
FROM php:8.4-fpm-bookworm

# ext-zip is required by openspout — an .xlsx file is a zip container, and
# Excel export is a Phase 1 deliverable.
#
# zstd / gzip / xz are the compression backends amitdugar/db-tools selects
# between; without them it silently falls back to an uncompressed dump.
#
# libncurses6 is needed only by the interactive `mysql` client copied below.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libzip-dev \
        libonig-dev \
        libncurses6 \
        gzip \
        zstd \
        xz-utils \
    && docker-php-ext-install pdo_mysql intl opcache bcmath zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=mysqlclient /usr/bin/mysqldump /usr/bin/mysqldump
COPY --from=mysqlclient /usr/bin/mysql /usr/bin/mysql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-spirdt.ini

WORKDIR /var/www/html
