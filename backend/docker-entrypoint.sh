#!/bin/sh

echo "Using DATABASE_URL for PostgreSQL connection."

if [ ! -f .env ]; then
    echo "No .env found, creating from .env.docker..."
    if [ -f .env.docker ]; then
        cp .env.docker .env
    elif [ -f .env.example ]; then
        cp .env.example .env
    fi
fi

APP_KEY=$(grep '^APP_KEY=' .env | cut -d= -f2)
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
    echo "Generating APP_KEY..."
    php artisan key:generate --force
fi

echo "Running migrations..."
php artisan migrate --force || echo "Migration failed, continuing..."

echo "Running seeders..."
php artisan db:seed --force || echo "Seeding skipped or completed."

echo "Creating storage symlink..."
rm -rf public/storage
php artisan storage:link || echo "Storage link skipped."

echo "Ensuring Supabase storage buckets..."
php artisan supabase:create-buckets --no-interaction || echo "Bucket setup skipped."

echo "Clearing cache..."
php artisan optimize:clear || echo "Cache clear skipped."

echo "Application is ready."

exec "$@"
