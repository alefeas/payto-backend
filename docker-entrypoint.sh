#!/bin/bash
set -e

echo "Running database migrations..."
php artisan migrate --force

echo "Starting services..."
service nginx start
php-fpm
