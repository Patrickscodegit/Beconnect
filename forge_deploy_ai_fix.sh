#!/bin/bash

# Forge Recipe: Deploy AI Fix and Restart Services
# Run this recipe in Laravel Forge after the git deployment

echo "🚀 Starting deployment of AI extraction fix..."

# Navigate to project directory
cd /home/forge/bconnect.64.226.120.45.nip.io

echo "📦 Pulling latest changes..."
git pull origin main

echo "🔄 Restarting queue workers (critical for job changes)..."
php artisan queue:restart
php artisan horizon:terminate

echo "🧹 Clearing application caches..."
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "🧪 Testing AI extraction..."
php artisan ai:test

echo "📊 Checking current job status..."
php artisan queue:work --once --timeout=30

echo "✅ Deployment complete!"
echo "📝 Next steps:"
echo "1. Upload a test document through the web interface"
echo "2. Check if extraction shows confidence > 0% and real data"
echo "3. Monitor logs: tail -f storage/logs/laravel.log"
