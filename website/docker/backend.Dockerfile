FROM php:8.3-cli

# Laravel + MySQL runtime requirements only.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libonig-dev \
    && docker-php-ext-install pdo_mysql mbstring bcmath opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Bake vendor/ into the image so the container never depends on a
# host bind-mounted vendor directory.
COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-interaction --prefer-dist \
    && composer dump-autoload --optimize --no-scripts

EXPOSE 8002

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8002"]
