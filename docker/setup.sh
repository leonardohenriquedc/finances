#!/bin/sh
set -eu

mkdir -p storage/app/private storage/app/public storage/logs \
    storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache

if [ ! -f .env ]; then
    cp .env.example .env
fi

# Cached host configuration must not override the container's database configuration.
rm -f bootstrap/cache/config.php
composer install --prefer-dist --no-interaction --no-progress

# Preserve the existing key and sessions across restarts. Never print the key.
if ! grep -Eq '^APP_KEY=.+$' .env; then
    php artisan key:generate --force --no-interaction
fi

php artisan config:clear --no-interaction
php artisan migrate --force --no-interaction
