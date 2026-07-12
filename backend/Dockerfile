FROM php:8.3-fpm AS base

RUN set -eux; \
    apt-get update; \
    apt-get install -y \
        git curl libpng-dev libpq-dev libonig-dev libxml2-dev libicu-dev \
        zip unzip libzip-dev postgresql-client procps; \
    docker-php-ext-install intl pdo_pgsql mbstring exif pcntl bcmath gd zip; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www

RUN mkdir -p storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]

FROM base AS development

COPY . .

RUN composer install --no-interaction --prefer-dist --optimize-autoloader

RUN if [ ! -f .env ]; then \
        if [ -f .env.docker ]; then cp .env.docker .env; \
        elif [ -f .env.example ]; then cp .env.example .env; fi; \
    fi; \
    if [ -f "artisan" ] && [ ! -f ".env" ]; then php artisan key:generate; fi

RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache && \
    chmod -R 775 /var/www/storage /var/www/bootstrap/cache

FROM base AS production

RUN set -eux; \
    apt-get update; \
    apt-get install -y nginx supervisor; \
    rm -rf /var/lib/apt/lists/*

COPY . .

RUN composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader && \
    php artisan optimize

RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache && \
    chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# Harden PHP-FPM: listen on localhost only (not exposed to network)
RUN sed -i 's/^listen = .*/listen = 127.0.0.1:9000/' /usr/local/etc/php-fpm.d/www.conf && \
    sed -i 's/^;listen.allowed_clients/listen.allowed_clients/' /usr/local/etc/php-fpm.d/www.conf && \
    sed -i 's/^listen.allowed_clients = .*/listen.allowed_clients = 127.0.0.1/' /usr/local/etc/php-fpm.d/www.conf && \
    # Ensure PHP-FPM runs in foreground under Supervisor
    sed -i 's/^;daemonize = yes/daemonize = no/' /usr/local/etc/php-fpm.conf

# Copy production Nginx and Supervisor configs
COPY production/nginx.conf /etc/nginx/conf.d/default.conf
COPY production/supervisord.conf /etc/supervisor/supervisord.conf

# Remove default nginx site
RUN rm -f /etc/nginx/sites-enabled/default

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://127.0.0.1:${PORT:-8080}/healthcheck.txt || exit 1

CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]
