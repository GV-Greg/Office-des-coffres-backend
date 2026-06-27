#!/bin/sh
set -e

cd /var/www/html

git config --global --add safe.directory /var/www/html 2>/dev/null || true

if [ ! -f vendor/autoload.php ]; then
    echo "Running composer install..."
    composer install --no-interaction --prefer-dist 2>&1 || {
        echo "composer install failed, trying composer update..."
        composer update --no-interaction --prefer-dist
    }
fi

if [ -z "$(grep '^APP_KEY=base64:' .env 2>/dev/null)" ]; then
    echo "Generating APP_KEY..."
    php artisan key:generate --force
fi

echo "Running migrations..."
php artisan migrate --force --no-interaction

echo "Publishing Spatie Permission config..."
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" 2>/dev/null || true

echo "Clearing caches..."
php artisan config:clear
php artisan route:clear

echo "Starting Laravel server on :8000..."
exec php artisan serve --host=0.0.0.0 --port=8000
