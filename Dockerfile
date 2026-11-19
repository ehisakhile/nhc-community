# NHC Community: PHP-FPM + nginx in one container (serversideup/php), listening on 8080.
# Put HTTPS in front of it (a reverse proxy, or the hub's own network for a clinic install).
FROM serversideup/php:8.5-fpm-nginx

# Settings come from the container's environment (.env), never a file in the image.
ENV PHP_OPCACHE_ENABLE=1 \
    AUTORUN_ENABLED=false \
    SSL_MODE=off \
    LOG_CHANNEL=stderr

WORKDIR /var/www/html

# Dependencies first, so code-only changes reuse this layer.
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY --chown=www-data:www-data . .
RUN composer dump-autoload --optimize --no-dev \
 && php artisan package:discover --ansi \
 && mkdir -p storage/loggerai && chown www-data:www-data storage/loggerai
