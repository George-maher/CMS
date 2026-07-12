#!/bin/sh
# ──────────────────────────────────────────────────────
# docker-entrypoint.sh — Church Management System
# Production-grade bootstrap for Laravel + Nginx + PHP-FPM
# ──────────────────────────────────────────────────────

set -e

# ──────────────────────────────────────────────────────
# Phase 1: Storage & Cache Directories
# ──────────────────────────────────────────────────────
mkdir -p /var/www/storage/framework/cache/data \
    /var/www/storage/framework/sessions \
    /var/www/storage/framework/views \
    /var/www/storage/logs \
    /var/www/bootstrap/cache

chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# ──────────────────────────────────────────────────────
# Phase 2: Environment File
# ──────────────────────────────────────────────────────
if [ ! -f /var/www/.env ]; then
    if [ -f /var/www/.env.docker ]; then
        echo "No .env found — copying from .env.docker"
        cp /var/www/.env.docker /var/www/.env
    elif [ -f /var/www/.env.example ]; then
        echo "No .env found — copying from .env.example"
        cp /var/www/.env.example /var/www/.env
    fi
fi

# ──────────────────────────────────────────────────────
# Phase 3: Clear Stale Bootstrap Cache
# ──────────────────────────────────────────────────────
rm -f /var/www/bootstrap/cache/config.php \
    /var/www/bootstrap/cache/packages.php \
    /var/www/bootstrap/cache/services.php

# ──────────────────────────────────────────────────────
# Phase 4: Ensure APP_KEY is Set
# ──────────────────────────────────────────────────────
# Priority: Railway env var > .env file > generate new
ENV_APP_KEY="${APP_KEY:-}"
if [ -n "$ENV_APP_KEY" ]; then
    echo "APP_KEY found in environment (Railway) — ensuring .env is in sync"
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
        echo "No APP_KEY in environment or .env — generating..."
        php /var/www/artisan key:generate --force
    fi
else
    echo "No .env file found — generating APP_KEY..."
    php /var/www/artisan key:generate --force
fi

# ──────────────────────────────────────────────────────
# Phase 5: Database Migration (if DB is reachable)
# ──────────────────────────────────────────────────────
php /var/www/artisan migrate --force 2>/dev/null && echo "Migrations complete." || echo "Migrations skipped."

# ──────────────────────────────────────────────────────
# Phase 6: Storage Symlink
# ──────────────────────────────────────────────────────
if [ ! -L /var/www/public/storage ] && [ ! -e /var/www/public/storage ]; then
    php /var/www/artisan storage:link 2>/dev/null || true
fi

# ──────────────────────────────────────────────────────
# Phase 7: Production Runtime Configuration
# ──────────────────────────────────────────────────────
if echo "$@" | grep -q "supervisord"; then
    echo "Production mode detected — configuring Nginx for PORT ${PORT:-8080}"

    PORT="${PORT:-8080}"
    case "$PORT" in
        ''|*[!0-9]*) PORT=8080 ;;
    esac

    sed -i "s/listen 8080;/listen ${PORT};/g" /etc/nginx/conf.d/default.conf

    # Cache Laravel config for optimal performance
    # NOT silenced — failures must surface in container logs
    php /var/www/artisan config:cache || echo "WARNING: config:cache failed"
    php /var/www/artisan route:cache || echo "WARNING: route:cache failed"
    php /var/www/artisan view:cache || echo "WARNING: view:cache failed"
fi

echo "Application is ready."

exec "$@"
