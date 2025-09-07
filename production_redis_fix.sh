#!/bin/bash

# Production Redis Setup and Service Restart Script
# This script fixes Redis connection issues for Laravel Horizon queues

set -e  # Exit on any error

echo "🔧 PRODUCTION REDIS SETUP & SERVICE RESTART"
echo "============================================="

# Install Redis Server
echo "📦 Installing Redis server..."
sudo apt update
sudo apt install -y redis-server

# Start and enable Redis service
echo "🚀 Starting Redis service..."
sudo systemctl start redis-server
sudo systemctl enable redis-server

# Verify Redis is running
echo "✅ Checking Redis status..."
sudo systemctl status redis-server --no-pager -l

# Test Redis connection
echo "🔌 Testing Redis connection..."
redis-cli ping

# Clear application caches and restart services
echo "🧹 Clearing Laravel caches..."
cd /home/forge/bconnect-dev.bconnect.fr

# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Restart queue worker and Horizon
echo "🔄 Restarting Horizon and queue workers..."
php artisan horizon:terminate || true
php artisan queue:restart

# Start Horizon in background
echo "🚀 Starting Horizon..."
nohup php artisan horizon > /dev/null 2>&1 &

# Verify Horizon is running
sleep 5
echo "✅ Checking Horizon status..."
php artisan horizon:status || echo "⚠️  Horizon may need manual start"

# Show Redis configuration
echo "📋 Redis Configuration:"
redis-cli config get maxmemory
redis-cli config get maxmemory-policy

# Show current queue status
echo "📊 Queue Status:"
php artisan queue:monitor --once 2>/dev/null || echo "Queue monitor not available"

echo ""
echo "✅ Redis setup complete!"
echo "📝 Key fixes applied:"
echo "  • Redis server installed and running"
echo "  • Laravel caches cleared"
echo "  • Horizon restarted"
echo "  • Queue workers restarted"
echo ""
echo "🔍 To monitor:"
echo "  • Redis: sudo systemctl status redis-server"
echo "  • Horizon: php artisan horizon:status"
echo "  • Logs: tail -f storage/logs/laravel.log"
