#!/bin/bash

# Laravel Forge Recipe: Sync Functionality Fix
# Add this recipe to your Forge dashboard to fix sync issues

# Set the project directory
cd /home/forge/bconnect.64.226.120.45.nip.io

echo "🔧 Starting Sync Functionality Fix..."

# Pull latest code
echo "📥 Pulling latest code..."
git pull origin main

# Clear all caches
echo "🧹 Clearing caches..."
php artisan view:clear
php artisan cache:clear
php artisan config:clear
php artisan route:clear

# Rebuild caches
echo "🔨 Rebuilding caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run migrations
echo "🗄️ Running migrations..."
php artisan migrate --force

# Seed data
echo "🌱 Seeding data..."
php artisan schedules:seed-data

# Restart services
echo "🔄 Restarting services..."
sudo supervisorctl restart all

echo "✅ Sync functionality fix completed!"
echo "🎉 The sync button should now work properly"
