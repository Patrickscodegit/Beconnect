#!/bin/bash

# Forge Deployment Script with Horizon Management
# This script should be run after deployment to ensure Horizon is running

echo "🚀 Starting post-deployment Horizon setup..."

# Navigate to the application directory
cd /home/forge/bconnect.64.226.120.45.nip.io || {
    echo "❌ Failed to navigate to application directory"
    exit 1
}

# Check if Horizon is currently running
if pgrep -f "horizon" > /dev/null; then
    echo "⚡ Horizon is running, terminating gracefully..."
    php artisan horizon:terminate
    sleep 3
else
    echo "ℹ️  Horizon is not running"
fi

# Start Horizon in background
echo "🚀 Starting Horizon queue processor..."
nohup php artisan horizon > storage/logs/horizon.log 2>&1 &
HORIZON_PID=$!

# Wait a moment for Horizon to start
sleep 5

# Verify Horizon is running
if pgrep -f "horizon" > /dev/null; then
    echo "✅ Horizon started successfully (PID: $HORIZON_PID)"
    
    # Check Horizon status
    echo "📊 Checking Horizon status..."
    php artisan horizon:status
    
    # Reset any stuck sync operations
    echo "🔄 Resetting any stuck sync operations..."
    php artisan schedules:reset-stuck-sync --force
    
    echo ""
    echo "🎉 Horizon deployment complete!"
    echo "📊 Monitor Horizon dashboard: https://bconnect.64.226.120.45.nip.io/horizon"
    echo "📋 Check logs: tail -f storage/logs/horizon.log"
    
else
    echo "❌ Failed to start Horizon"
    echo "🔍 Check logs: tail -f storage/logs/horizon.log"
    exit 1
fi
