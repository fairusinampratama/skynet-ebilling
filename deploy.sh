#!/bin/sh

# Skynet E-Billing - Coolify Deployment Script
# This script runs on container startup

set -e

echo "🚀 Starting Skynet E-Billing deployment..."

# 1. Run migrations
echo "📦 Running database migrations..."
php artisan migrate --force --isolated

# 2. Create storage link (ignore if exists)
echo "🔗 Creating storage symlink..."
php artisan storage:link || true

# 3. Cache optimization
echo "⚡ Optimizing configuration cache..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 4. Start queue worker in background
echo "📮 Starting queue worker..."
php artisan queue:work --queue=network-enforcement --tries=3 --timeout=90 --sleep=3 &

# 5. Start scheduler (cron simulation)
echo "⏰ Starting scheduler..."
while true; do
    php artisan schedule:run >> /dev/null 2>&1
    sleep 60
done &

# 6. Start PHP built-in server (Coolify expects this)
echo "🌐 Starting web server on port 3001..."
php artisan serve --host=0.0.0.0 --port=3001
