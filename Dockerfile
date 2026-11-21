# syntax=docker/dockerfile:1

ARG PHP_VERSION=8.3

# Assets are platform independent, so they are built natively even for arm64 images.
FROM --platform=$BUILDPLATFORM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
# VITE_* values are baked into the bundle at build time, runtime env cannot change them.
ENV VITE_APP_ENV=production
RUN npx vite build

FROM serversideup/php:${PHP_VERSION}-frankenphp-alpine AS runtime

USER root
RUN install-php-extensions intl bcmath gd exif \
    && apk add --no-cache postgresql17-client

USER www-data
WORKDIR /var/www/html

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build

# storage/ is excluded from the build context (local recovery dumps, imports, ...),
# so the skeleton is recreated here. A fresh named volume copies it on first start.
RUN mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --optimize --no-dev

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    HEALTHCHECK_PATH=/up \
    OCTANE_SERVER=frankenphp \
    OCTANE_MAX_REQUESTS=500 \
    PHP_OPCACHE_ENABLE=1 \
    PHP_UPLOAD_MAX_FILE_SIZE=100M \
    PHP_POST_MAX_SIZE=100M

# Octane worker mode with the image's Caddyfile (keeps trusted proxies, security headers
# and the health check). The base image's CMD runs FrankenPHP in classic mode instead,
# a self-hoster can switch back to it by overriding `command` in docker-compose.yml.
CMD ["sh", "-c", "exec php artisan octane:start --server=frankenphp --port=8080 --caddyfile=/etc/frankenphp/Caddyfile --max-requests=${OCTANE_MAX_REQUESTS}"]
