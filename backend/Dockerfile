FROM php:8.3-fpm AS base

RUN apt-get update && apt-get install -y \
    git curl libpng-dev libpq-dev libonig-dev libxml2-dev zip unzip libzip-dev postgresql-client procps \
    && docker-php-ext-install pdo_pgsql mbstring exif pcntl bcmath gd zip

COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]

FROM base AS development

COPY . .

RUN composer install --no-interaction --prefer-dist --optimize-autoloader

RUN if [ ! -f .env ]; then \
        if [ -f .env.example ]; then cp .env.example .env; fi; \
    fi; \
    if [ -f "artisan" ] && [ ! -f ".env" ]; then php artisan key:generate; fi

RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache && \
    chmod -R 775 /var/www/storage /var/www/bootstrap/cache

USER www-data

FROM base AS production

COPY . .

RUN if [ -f "artisan" ]; then \
        composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader --ignore-platform-reqs && \
        php artisan key:generate && \
        php artisan optimize; \
    fi

RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

USER www-data
