FROM php:8.3-fpm-alpine

RUN docker-php-ext-install pdo_sqlite opcache

COPY docker/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www/html
COPY . .
RUN mkdir -p data cache && chown -R www-data:www-data /var/www/html
