FROM serversideup/php:8.5-fpm

# Keep OPcache enabled in the FPM image. The base image can otherwise disable
# it depending on its runtime defaults.
ENV PHP_OPCACHE_ENABLE=1

WORKDIR /var/www/html

COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data container/opcache.ini /usr/local/etc/php/conf.d/zzz-opcache.ini
COPY --chown=www-data:www-data container/cron.sh /usr/local/bin/bbs1-cron

USER root

RUN chmod +x /usr/local/bin/bbs1-cron \
    && mkdir -p /usr/local/share/bbs1org-template \
    && cp -a /var/www/html/. /usr/local/share/bbs1org-template/ \
    && chown -R www-data:www-data app \
    && chown root:www-data . \
    && chmod 775 .

USER www-data
