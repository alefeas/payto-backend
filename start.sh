#!/bin/bash
set -e

php artisan config:clear
php artisan cache:clear
php artisan migrate --force

sed -i "s/Listen 80/Listen ${PORT:-80}/g" /etc/apache2/ports.conf
sed -i "s/:80/:${PORT:-80}/g" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
