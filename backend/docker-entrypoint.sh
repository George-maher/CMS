#!/bin/sh
# Production bootstrap for Laravel + Nginx + PHP-FPM

set -e

PRODUCTION_MODE=0
if echo "$@" | grep -q "supervisord"; then
    PRODUCTION_MODE=1
fi

mkdir -p /var/www/storage/framework/cache/data \
    /var/www/storage/framework/sessions \
    /var/www/storage/framework/views \
    /var/www/storage/logs \
    /var/www/bootstrap/cache

chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
chmod -R 775 /var/www/storage /var/www/bootstrap/cache

if [ ! -f /var/www/.env ]; then
    if [ "$PRODUCTION_MODE" -eq 1 ]; then
        echo "Production requires runtime environment variables; refusing development .env fallback."
    elif [ -f /var/www/.env.docker ]; then
        echo "No .env found — copying from .env.docker"
        cp /var/www/.env.docker /var/www/.env
    elif [ -f /var/www/.env.example ]; then
        echo "No .env found — copying from .env.example"
        cp /var/www/.env.example /var/www/.env
    fi
fi

if [ "$PRODUCTION_MODE" -eq 1 ]; then
    if [ "${APP_ENV:-}" != "production" ]; then
        echo "APP_ENV must be production in production mode."
        exit 1
    fi
    if [ "${APP_DEBUG:-false}" = "true" ] || [ "${APP_DEBUG:-false}" = "1" ]; then
        echo "APP_DEBUG must be false in production mode."
        exit 1
    fi
    if [ -z "${APP_KEY:-}" ]; then
        echo "APP_KEY must be supplied in production mode."
        exit 1
    fi
    if [ "${MAIL_MAILER:-}" != "smtp" ] || [ -z "${MAIL_HOST:-}" ] || [ -z "${MAIL_PORT:-}" ] || [ -z "${MAIL_USERNAME:-}" ] || [ -z "${MAIL_PASSWORD:-}" ] || [ -z "${MAIL_FROM_ADDRESS:-}" ]; then
        echo "Production requires authenticated SMTP mail configuration."
        exit 1
    fi
fi

rm -f /var/www/bootstrap/cache/config.php \
    /var/www/bootstrap/cache/packages.php \
    /var/www/bootstrap/cache/services.php

ENV_APP_KEY="${APP_KEY:-}"
if [ -n "$ENV_APP_KEY" ]; then
    echo "APP_KEY found in environment — ensuring .env is in sync"
    if [ -f /var/www/.env ]; then
        if grep -q '^APP_KEY=' /var/www/.env; then
            sed -i "s|^APP_KEY=.*|APP_KEY=$ENV_APP_KEY|" /var/www/.env
        else
            echo "APP_KEY=$ENV_APP_KEY" >> /var/www/.env
        fi
    fi
elif [ -f /var/www/.env ]; then
    FILE_APP_KEY=$(grep '^APP_KEY=' /var/www/.env | cut -d= -f2-)
    if [ -z "$FILE_APP_KEY" ] || [ "$FILE_APP_KEY" = "APP_KEY=" ] || [ "$FILE_APP_KEY" = "base64:" ]; then
        if [ "$PRODUCTION_MODE" -eq 1 ]; then
            echo "APP_KEY is missing in production mode."
            exit 1
        fi
        echo "No APP_KEY in environment or .env — generating..."
        php /var/www/artisan key:generate --force
    fi
else
    if [ "$PRODUCTION_MODE" -eq 1 ]; then
        echo "No .env file found and APP_KEY is not available in production mode."
        exit 1
    fi
    echo "No .env file found — generating APP_KEY..."
    php /var/www/artisan key:generate --force
fi

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    if php /var/www/artisan migrate --force 2>/dev/null; then
        echo "Migrations complete."
    elif [ "$PRODUCTION_MODE" -eq 1 ]; then
        echo "Production migrations failed."
        exit 1
    else
        echo "Migrations skipped."
    fi

    if php /var/www/artisan tinker --execute="\App\Models\Permission::clearCache(); echo \App\Models\Permission::getPermissionsForRole('admin') === [] ? 'missing' : 'seeded';" 2>/dev/null | grep -q "missing"; then
        echo "Admin role permissions missing — seeding default roles and permissions..."
        php /var/www/artisan db:seed --class=PermissionSeeder --force 2>/dev/null && echo "Permission seeder complete." || echo "Permission seeder skipped."
    fi
else
    # Multi-service stacks (compose: worker/scheduler) must not race the app
    # service's migrator — only one service migrates/seeds.
    echo "Migrations disabled for this service (RUN_MIGRATIONS=${RUN_MIGRATIONS:-unset})."
fi

if [ ! -L /var/www/public/storage ] && [ ! -e /var/www/public/storage ]; then
    php /var/www/artisan storage:link 2>/dev/null || true
fi

if [ "$PRODUCTION_MODE" -eq 1 ]; then
    echo "Production mode detected — configuring Nginx for PORT ${PORT:-8080}"

    PORT="${PORT:-8080}"
    case "$PORT" in
        ''|*[!0-9]*) PORT=8080 ;;
    esac

    sed -i "s/listen 8080;/listen ${PORT};/g" /etc/nginx/conf.d/default.conf

    php /var/www/artisan config:cache
    php /var/www/artisan route:cache
    php /var/www/artisan view:cache
fi

echo "Application is ready."
exec "$@"
