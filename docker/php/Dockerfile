FROM php:8.4-fpm-alpine

# ext-zip is required by openspout — an .xlsx file is a zip container, and
# Excel export is a Phase 1 deliverable, so it is not optional.
RUN apk add --no-cache git unzip icu-dev oniguruma-dev libzip-dev linux-headers \
    && docker-php-ext-install pdo_mysql intl opcache bcmath zip \
    && rm -rf /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-spirdt.ini

WORKDIR /var/www/html
