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

KINGDOM_COUNT=$(php artisan tinker --execute="echo \App\Models\Kingdom::count();" 2>/dev/null | grep -oE '[0-9]+' | tail -1)
if [ -z "$KINGDOM_COUNT" ] || [ "$KINGDOM_COUNT" = "0" ]; then
    echo "Map data empty, seeding..."
    php artisan db:seed --class=Database\\Seeders\\MapSeeder --force
fi

USER_COUNT=$(php artisan tinker --execute="echo \App\Models\User::count();" 2>/dev/null | grep -oE '[0-9]+' | tail -1)
if [ -z "$USER_COUNT" ] || [ "$USER_COUNT" = "0" ]; then
    echo "No users, seeding..."
    php artisan db:seed --class=Database\\Seeders\\UserSeeder --force
fi

echo "Publishing Spatie Permission config..."
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" 2>/dev/null || true

echo "Clearing caches..."
php artisan config:clear
php artisan route:clear

echo "Starting Laravel server on :8000..."
exec php artisan serve --host=0.0.0.0 --port=8000
