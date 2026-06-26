# Church Manager — Backend

Laravel 12 API backend for the Church Management System.

## Requirements

- PHP 8.2+
- PostgreSQL 15+
- Composer 2.x

## Setup

```bash
cp .env.example .env
# Edit .env with your database credentials
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

## Queue

```bash
php artisan queue:work database --sleep=3 --tries=3
```

## Production

```bash
composer install --no-dev --optimize-autoloader
php artisan optimize
php artisan config:cache
php artisan route:cache
```
