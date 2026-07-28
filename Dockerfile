FROM serversideup/php:8.5-fpm

WORKDIR /var/www/html

COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data docker/opcache.ini /usr/local/etc/php/conf.d/zzz-opcache.ini
COPY --chown=www-data:www-data docker/cron.sh /usr/local/bin/bbs1-cron

RUN chmod +x /usr/local/bin/bbs1-cron \
    && chown -R www-data:www-data app \
    && chown root:www-data . \
    && chmod 775 .
