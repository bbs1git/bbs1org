FROM php:8.3-fpm-alpine

RUN docker-php-ext-install pdo_sqlite

WORKDIR /var/www/html
COPY . .
RUN mkdir -p data && chown -R www-data:www-data data
