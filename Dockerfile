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

FROM serversideup/php:${PHP_VERSION}-fpm-nginx-alpine AS runtime

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

# The Vite preload Link headers exceed nginx's default 8k FastCGI header buffer (502).
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    HEALTHCHECK_PATH=/up \
    NGINX_FASTCGI_BUFFER_SIZE=32k \
    NGINX_FASTCGI_BUFFERS="16 32k" \
    PHP_OPCACHE_ENABLE=1 \
    PHP_UPLOAD_MAX_FILE_SIZE=100M \
    PHP_POST_MAX_SIZE=100M
