#!/bin/sh
# Waits for PostgreSQL, then (for the web container) runs migrations and the optional demo seed.
set -e
cd /var/www/html

export OPCACHE_VALIDATE_TIMESTAMPS="${OPCACHE_VALIDATE_TIMESTAMPS:-0}"

if [ "$APP_ENV" = "dev" ] && [ ! -f vendor/autoload_runtime.php ]; then
    composer install --no-interaction --prefer-dist
fi

echo "Waiting for the database..."
until php bin/console dbal:run-sql "SELECT 1" >/dev/null 2>&1; do
    sleep 2
done

if [ "${CVOLVE_ROLE:-web}" = "worker" ]; then
    # The web container owns the migrations; start consuming once they are applied
    until php bin/console doctrine:migrations:up-to-date >/dev/null 2>&1; do
        sleep 2
    done
fi

if [ "${CVOLVE_ROLE:-web}" = "web" ]; then
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    # Installs/updates the preset templates; on an empty database also creates the admin (and the demo user)
    if [ "${CVOLVE_SEED_DEMO:-0}" = "1" ]; then
        php bin/console app:seed-demo --if-empty
    else
        php bin/console app:seed-demo --if-empty --no-demo
    fi
    if [ "$APP_ENV" = "dev" ]; then
        php bin/console importmap:install >/dev/null 2>&1 || true
    fi
    mkdir -p var/storage var/cache var/log
    chown -R www-data:www-data var
fi

exec "$@"
