FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    bash git unzip icu-dev libpq-dev libzip-dev oniguruma-dev libxml2-dev \
    autoconf g++ make linux-headers

RUN docker-php-ext-install \
    pdo pdo_pgsql mbstring bcmath xml zip intl opcache \
 && pecl install redis \
 && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-progress --no-interaction

COPY . .

RUN composer dump-autoload --optimize --no-dev \
 && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm", "-F"]
