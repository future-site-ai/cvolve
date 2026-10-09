# CVolve application image: PHP 8.3 + Apache, used for both the web app and the messenger worker.
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev unzip git postgresql-client \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip gd opcache \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/cvolve.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
ENV APP_ENV=prod COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock symfony.lock ./
# The shared template engine is a local Composer path package
COPY packages packages
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
 && APP_SECRET=build php bin/console importmap:install \
 && APP_SECRET=build php bin/console asset-map:compile \
 && APP_SECRET=build php bin/console cache:warmup \
 && mkdir -p var/storage && chown -R www-data:www-data var

COPY docker/entrypoint.sh /usr/local/bin/cvolve-entrypoint
RUN chmod +x /usr/local/bin/cvolve-entrypoint

ENTRYPOINT ["cvolve-entrypoint"]
CMD ["apache2-foreground"]
