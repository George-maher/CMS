#!/bin/sh
# Production bootstrap for Laravel + Nginx + PHP-FPM.

set -e

# Production is determined from APP_ENV and from the foreground process.
PRODUCTION_MODE=0
if [ "${APP_ENV:-}" = "production" ]; then
    PRODUCTION_MODE=1
fi
if echo "$@" | grep -q "supervisord"; then
    PRODUCTION_MODE=1
fi

log_stage() {
    printf '[BOOT] %s\n' "$1"
}

fail_stage() {
    printf '[BOOT][ERROR] %s\n' "$1" >&2
    exit 1
}

log_stage "Preparing writable Laravel directories..."
mkdir -p /var/www/storage/framework/cache/data \
    /var/www/storage/framework/sessions \
    /var/www/storage/framework/views \
    /var/www/storage/logs \
    /var/www/bootstrap/cache

chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# Production configuration comes from Railway. Never create a development
# environment file in the production image.
if [ ! -f /var/www/.env ] && [ "$PRODUCTION_MODE" -eq 0 ]; then
    if [ -f /var/www/.env.docker ]; then
        log_stage "No .env found; using .env.docker for development."
        cp /var/www/.env.docker /var/www/.env
    elif [ -f /var/www/.env.example ]; then
        log_stage "No .env found; using .env.example for development."
        cp /var/www/.env.example /var/www/.env
    fi
fi

if [ "$PRODUCTION_MODE" -eq 1 ]; then
    log_stage "Validating production environment..."

    BLOCKERS=""
    if [ "${APP_ENV:-}" != "production" ]; then
        BLOCKERS="${BLOCKERS}\n  - APP_ENV must be exactly 'production'."
    fi

    case "${APP_DEBUG:-}" in
        false|FALSE|0) ;;
        *) BLOCKERS="${BLOCKERS}\n  - APP_DEBUG must be false." ;;
    esac

    if [ -z "${APP_KEY:-}" ] || ! APP_KEY="${APP_KEY:-}" php -r '
        $key = (string) getenv("APP_KEY");
        if (str_starts_with($key, "base64:")) {
            $decoded = base64_decode(substr($key, 7), true);
            exit(is_string($decoded) && strlen($decoded) === 32 ? 0 : 1);
        }
        exit(strlen($key) === 32 ? 0 : 1);
    '; then
        BLOCKERS="${BLOCKERS}\n  - APP_KEY must be a valid 32-byte Laravel key."
    fi

    DB_CONNECTION_VALUE="${DB_CONNECTION:-pgsql}"
    if [ "$DB_CONNECTION_VALUE" != "pgsql" ]; then
        BLOCKERS="${BLOCKERS}\n  - DB_CONNECTION must be pgsql."
    fi

    # DATABASE_URL is sufficient. Otherwise require the explicit Laravel
    # PostgreSQL connection settings. Values are never printed.
    if [ -z "${DATABASE_URL:-}" ]; then
        if [ -z "${DB_HOST:-}" ] || \
           [ -z "${DB_DATABASE:-}" ] || \
           [ -z "${DB_USERNAME:-}" ] || \
           [ -z "${DB_PASSWORD:-}" ]; then
            BLOCKERS="${BLOCKERS}\n  - Configure DATABASE_URL or DB_HOST, DB_DATABASE, DB_USERNAME, and DB_PASSWORD."
        fi
    fi

    QUEUE_CONNECTION_VALUE="${QUEUE_CONNECTION:-database}"
    case "$QUEUE_CONNECTION_VALUE" in
        database|redis) ;;
        *) BLOCKERS="${BLOCKERS}\n  - QUEUE_CONNECTION must be database or redis." ;;
    esac
    export QUEUE_CONNECTION="$QUEUE_CONNECTION_VALUE"

    if [ -n "$BLOCKERS" ]; then
        printf '%b\n' "$BLOCKERS" >&2
        fail_stage "Production environment validation failed. No secrets were displayed."
    fi

    log_stage "Environment validation passed."
fi

# Rebuild generated caches using the current runtime environment.
rm -f /var/www/bootstrap/cache/config.php \
    /var/www/bootstrap/cache/packages.php \
    /var/www/bootstrap/cache/services.php

if [ "$PRODUCTION_MODE" -eq 1 ]; then
    log_stage "Waiting for PostgreSQL..."

    DB_MAX_ATTEMPTS="${DB_MAX_ATTEMPTS:-30}"
    DB_RETRY_DELAY_SECONDS="${DB_RETRY_DELAY_SECONDS:-2}"

    case "$DB_MAX_ATTEMPTS" in
        ''|*[!0-9]*|0) fail_stage "DB_MAX_ATTEMPTS must be a positive integer." ;;
    esac
    case "$DB_RETRY_DELAY_SECONDS" in
        ''|*[!0-9]*) fail_stage "DB_RETRY_DELAY_SECONDS must be a non-negative integer." ;;
    esac

    attempt=1
    database_ready=0
    while [ "$attempt" -le "$DB_MAX_ATTEMPTS" ]; do
        # Boot Laravel only for this connection probe. Suppress exception text
        # because database URLs may contain credentials.
        if php -d display_errors=0 -r '
            require "/var/www/vendor/autoload.php";
            $app = require "/var/www/bootstrap/app.php";
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            try {
                $app->make("db")->connection()->select("SELECT 1");
                exit(0);
            } catch (Throwable) {
                exit(1);
            }
        ' >/dev/null 2>&1; then
            database_ready=1
            break
        fi

        if [ "$attempt" -lt "$DB_MAX_ATTEMPTS" ]; then
            sleep "$DB_RETRY_DELAY_SECONDS"
        fi
        attempt=$((attempt + 1))
    done

    if [ "$database_ready" -ne 1 ]; then
        fail_stage "Database is unreachable after ${DB_MAX_ATTEMPTS} attempts. Check DATABASE_URL or DB_* settings and PostgreSQL service attachment."
    fi

    log_stage "Database is reachable."
fi

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    log_stage "Running migrations..."
    if ! php /var/www/artisan migrate --force; then
        fail_stage "Laravel migrations failed. The container will not start with an unknown schema state."
    fi
    log_stage "Migrations completed."

    # Preserve the existing idempotent permission bootstrap.
    if [ "${RUN_PERMISSION_SEEDER:-true}" = "true" ]; then
        log_stage "Checking default role permissions..."
        permission_state=""
        if ! permission_state=$(php /var/www/artisan tinker --execute="\App\Models\Permission::clearCache(); echo \App\Models\Permission::getPermissionsForRole('admin') === [] ? 'missing' : 'seeded';" 2>/dev/null); then
            printf '%s\n' '[BOOT][WARN] Permission check failed; application startup will continue for operator review.' >&2
        else
            case "$permission_state" in
                *missing*)
                    if ! php /var/www/artisan db:seed --class=PermissionSeeder --force; then
                        printf '%s\n' '[BOOT][WARN] Permission seeder failed; application startup will continue for operator review.' >&2
                    else
                        log_stage "Permission seeder completed."
                    fi
                    ;;
                *seeded*) log_stage "Default role permissions are present." ;;
                *) printf '%s\n' '[BOOT][WARN] Permission check returned an unexpected result; application startup will continue for operator review.' >&2 ;;
            esac
        fi
    fi
else
    log_stage "Migrations disabled for this service (RUN_MIGRATIONS=${RUN_MIGRATIONS:-unset})."
fi

# Nginx serves public storage through its alias and Laravel has a safe fallback
# route. A symlink failure is therefore reported but is not a boot blocker.
if [ ! -L /var/www/public/storage ] && [ ! -e /var/www/public/storage ]; then
    if ! php /var/www/artisan storage:link >/dev/null 2>&1; then
        printf '%s\n' '[BOOT][WARN] Storage symlink could not be created; public storage will use the configured fallback.' >&2
    fi
fi

if [ "$PRODUCTION_MODE" -eq 1 ]; then
    log_stage "Configuring Nginx for Railway PORT ${PORT:-8080}..."

    PORT="${PORT:-8080}"
    case "$PORT" in
        ''|*[!0-9]*) fail_stage "PORT must be a valid TCP port number." ;;
    esac
    if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
        fail_stage "PORT must be between 1 and 65535."
    fi

    sed -i "s/listen 8080;/listen ${PORT};/g" /etc/nginx/conf.d/default.conf

    log_stage "Building Laravel config cache..."
    if ! php /var/www/artisan config:cache; then
        fail_stage "Laravel config cache failed."
    fi

    log_stage "Building Laravel route cache..."
    if ! php /var/www/artisan route:cache; then
        fail_stage "Laravel route cache failed."
    fi

    log_stage "Building Laravel view cache..."
    if ! php /var/www/artisan view:cache; then
        fail_stage "Laravel view cache failed."
    fi

    log_stage "Laravel caches completed."
fi

if [ "$#" -eq 0 ]; then
    fail_stage "No foreground process was supplied; refusing to start an empty container."
fi

log_stage "Starting Supervisor..."
exec "$@"
