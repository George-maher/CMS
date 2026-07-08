#!/bin/sh

# ──────────────────────────────────────────────
#  BOOTSTRAP — always start php-fpm at the end,
#  no matter what. We DO NOT use `set -e` because
#  any failure (grep, artisan crash, etc.) would
#  kill the script and php-fpm would never start,
#  causing a 502 from nginx.
# ──────────────────────────────────────────────

# ──────────────────────────────────────────────
# Ensure required storage directories exist
# ──────────────────────────────────────────────
mkdir -p /var/www/storage/framework/cache/data
mkdir -p /var/www/storage/framework/sessions
mkdir -p /var/www/storage/framework/views
mkdir -p /var/www/storage/logs
chown -R www-data:www-data /var/www/storage 2>/dev/null || true
chmod -R 775 /var/www/storage 2>/dev/null || true

# ──────────────────────────────────────────────
# Ensure bootstrap cache directory is writable
# ──────────────────────────────────────────────
mkdir -p /var/www/bootstrap/cache
chown -R www-data:www-data /var/www/bootstrap/cache 2>/dev/null || true
chmod -R 775 /var/www/bootstrap/cache 2>/dev/null || true

# ──────────────────────────────────────────────
# Ensure .env exists
# ──────────────────────────────────────────────
if [ ! -f .env ]; then
    echo "No .env found, creating from .env.docker..."
    if [ -f .env.docker ]; then
        cp .env.docker .env 2>/dev/null || true
    elif [ -f .env.example ]; then
        cp .env.example .env 2>/dev/null || true
    fi
fi

# ──────────────────────────────────────────────
# Nuke stale bootstrap cache files FIRST.
# ──────────────────────────────────────────────
echo "Deleting stale bootstrap cache files..."
rm -f /var/www/bootstrap/cache/config.php \
      /var/www/bootstrap/cache/packages.php \
      /var/www/bootstrap/cache/services.php

# ──────────────────────────────────────────────
# Generate APP_KEY if missing or invalid
# ──────────────────────────────────────────────
if [ -f .env ]; then
    APP_KEY=$(grep '^APP_KEY=' .env 2>/dev/null | cut -d= -f2) || true
    if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
        echo "Generating APP_KEY..."
        php artisan key:generate --force 2>/dev/null || true
    fi
fi

# ──────────────────────────────────────────────
# Wait for database connection before running migrations
# ──────────────────────────────────────────────
echo "Waiting for database connection..."
DB_READY=false
for i in $(seq 1 30); do
    PGPASSWORD="${DB_PASSWORD}" psql \
        -h "${DB_HOST:-postgres}" \
        -p "${DB_PORT:-5432}" \
        -U "${DB_USERNAME:-postgres}" \
        -d "${DB_DATABASE:-church_management}" \
        -c "SELECT 1" -t -A 2>/dev/null \
    | grep -q "1" && DB_READY=true && break
    sleep 2
done

if [ "$DB_READY" = "true" ]; then
    echo "Running migrations..."
    php artisan migrate --force 2>/dev/null || echo "Migration failed but continuing..."

    # ══════════════════════════════════════════════
    #  SEEDING — only seed on first deploy
    #  Remove storage/.seed-flag to re-seed manually
    # ══════════════════════════════════════════════
    if [ ! -f /var/www/storage/.seed-flag ]; then
        echo "First deploy detected — seeding database..."
        php artisan db:seed --force 2>/dev/null && touch /var/www/storage/.seed-flag || echo "Seeding failed but continuing..."
    else
        echo "Database already seeded — skipping seeder."
    fi
else
    echo "Database not reachable — skipping migrations and seed."
fi

# ──────────────────────────────────────────────
# Create storage symlink (safe: only if not exists or not a symlink)
# ──────────────────────────────────────────────
if [ ! -L /var/www/public/storage ]; then
    if [ ! -e /var/www/public/storage ]; then
        echo "Creating storage symlink..."
        php artisan storage:link 2>/dev/null || echo "Storage link skipped."
    else
        echo "public/storage exists as a real file/directory — not overwriting."
    fi
else
    echo "Storage symlink already exists — skipping."
fi

echo "Checking Supabase storage..."
if [ -n "$SUPABASE_URL" ] && [ -n "$SUPABASE_SERVICE_ROLE_KEY" ]; then
    php artisan supabase:create-buckets --no-interaction 2>/dev/null || echo "Bucket setup failed but continuing..."
else
    echo "Supabase not configured — using local storage."
fi

echo "Caching fresh config for better performance..."
php artisan config:clear --quiet 2>/dev/null || true
php artisan config:cache 2>/dev/null || echo "Config cache skipped."

echo "Application is ready."

# ══════════════════════════════════════════════
#  Drop privileges to www-data for the runtime process
# ══════════════════════════════════════════════
exec "$@"
