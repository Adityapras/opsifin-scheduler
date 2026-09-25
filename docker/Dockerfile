# syntax=docker/dockerfile:1

# ---- Base: PHP 8.4 + Apache dengan extension yang dipakai Laravel/Filament ----
FROM php:8.4-apache AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions bcmath intl opcache pcntl pdo_mysql zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

ENV TZ=Asia/Jakarta \
    APACHE_DOCUMENT_ROOT=/var/www/html/public

COPY docker/php/opsifin.ini /usr/local/etc/php/conf.d/zz-opsifin.ini
COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

# ---- Vendor: composer install tanpa dev dependency ----
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist

# ---- Assets: Vite build. Theme Filament meng-import CSS dari vendor/filament ----
FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY --from=vendor /var/www/html/vendor ./vendor
COPY vite.config.js ./
COPY resources ./resources
COPY app ./app
RUN npm run build

# ---- Runtime ----
FROM base AS runtime

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=vendor /var/www/html/vendor ./vendor
COPY --chown=www-data:www-data . .
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data bootstrap/cache storage

COPY docker/entrypoint.sh /usr/local/bin/opsifin-entrypoint
RUN chmod +x /usr/local/bin/opsifin-entrypoint

ENTRYPOINT ["opsifin-entrypoint"]
CMD ["apache2-foreground"]
