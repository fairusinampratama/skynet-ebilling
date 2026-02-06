#!/bin/sh

# Exit immediately if a command exits with a non-zero status
set -e

echo "🚀 Starting Deployment Script..."

echo "📂 Fixing permissions..."
chmod -R 777 storage bootstrap/cache

echo "🔗 Linking storage..."
php artisan storage:link || true

echo "⚡ Optimizing application..."
php artisan optimize:clear
php artisan config:cache
php artisan event:cache
php artisan route:cache
php artisan view:cache

echo "📦 Running migrations..."
php artisan migrate --force

echo "✅ Deployment tasks completed."

echo "🚀 Starting services..."

# Find concurrently executable
if [ -f "./node_modules/.bin/concurrently" ]; then
    CONCURRENTLY="./node_modules/.bin/concurrently"
else
    CONCURRENTLY="npx concurrently"
fi

# Run Laravel Serve, Scheduler, and Queue Worker in parallel
$CONCURRENTLY -c "#93c5fd,#c4b5fd,#fb7185" \
    "php artisan serve --host=0.0.0.0 --port=8000" \
    "php artisan schedule:work" \
    "php artisan queue:work --tries=3 --timeout=90"
