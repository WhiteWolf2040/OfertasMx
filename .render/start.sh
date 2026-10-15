#!/bin/bash
set -e
cd /var/www

php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan migrate --force
php artisan net:check || true
if [ "$SEED_DEMO" = "true" ]; then
  php artisan db:seed --class=DemoDealsSeeder --force
fi

chown -R www-data:www-data storage bootstrap/cache

su -s /bin/sh www-data -c "php artisan queue:work --sleep=3 --tries=1 --timeout=90" &
su -s /bin/sh www-data -c "php artisan schedule:work" &

php-fpm -D
nginx -g 'daemon off;'