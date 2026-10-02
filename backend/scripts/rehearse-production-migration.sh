# Production migration rehearsal — run this against a DISPOSABLE database only.
#
# It deliberately builds a legacy-inconsistent database, proves the migration
# refuses to touch it, repairs it under explicit operator consent, applies the
# constraints, and verifies the result on a real PostgreSQL engine.
#
#   psql -U postgres -d postgres -c "CREATE DATABASE chrehearsal;"
#   cd backend
#   export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
#          DB_DATABASE=chrehearsal DB_USERNAME=postgres DB_PASSWORD=... DB_SSLMODE=disable
#   ./scripts/rehearse-production-migration.sh
#
# WARNING: this truncates data in the target database. Never point it at a real one.

set -euo pipefail

step() { printf '\n\033[1m=== %s ===\033[0m\n' "$1"; }

step "1. migrate:fresh"
php artisan migrate:fresh --force

step "2. roll back the composite FKs (real pre-constraint state)"
php artisan migrate:rollback --step=1 --force

step "3. load the production-like legacy fixture"
psql -q -f database/rehearsal/production_like_fixture.sql

step "4. tenant:audit (read-only) — must DETECT the legacy rows and exit non-zero"
php artisan tenant:audit || echo "-> exited non-zero as expected"

step "5. migrate — must REFUSE and must not modify any row"
php artisan migrate --force || echo "-> refused as expected"

step "6. tenant:audit --repair --force (explicit operator consent)"
php artisan tenant:audit --repair --force

step "7. tenant:audit — must now be clean"
php artisan tenant:audit

step "8. migrate — must now succeed"
php artisan migrate --force

step "9. tenant:verify-schema"
php artisan tenant:verify-schema

step "10. rollback and re-apply the newest migrations"
php artisan migrate:rollback --step=4 --force
php artisan migrate --force
php artisan tenant:verify-schema

step "REHEARSAL COMPLETE"
