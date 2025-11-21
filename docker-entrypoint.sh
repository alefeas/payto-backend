#!/bin/bash

echo "Running database migrations..."
php artisan migrate --force || true

echo "Starting services..."
service nginx start
php-fpm
