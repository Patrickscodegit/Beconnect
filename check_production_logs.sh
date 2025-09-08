#!/bin/bash

# Production Log Checker Script
# Run this on your production server to diagnose the offline issue

echo "🔍 Checking Production Logs for Bconnect..."
echo "=================================================="

echo ""
echo "1️⃣ LARAVEL APPLICATION LOGS:"
echo "------------------------------"
if [ -f "/home/forge/bconnect.64.226.120.45.nip.io/storage/logs/laravel.log" ]; then
    echo "📋 Last 50 lines from Laravel log:"
    tail -50 /home/forge/bconnect.64.226.120.45.nip.io/storage/logs/laravel.log
else
    echo "❌ Laravel log file not found"
fi

echo ""
echo "2️⃣ NGINX ERROR LOGS:"
echo "---------------------"
if [ -f "/var/log/nginx/bconnect.64.226.120.45.nip.io-error.log" ]; then
    echo "📋 Last 30 lines from Nginx error log:"
    tail -30 /var/log/nginx/bconnect.64.226.120.45.nip.io-error.log
else
    echo "❌ Nginx error log not found"
fi

echo ""
echo "3️⃣ FORGE DEPLOYMENT LOGS:"
echo "--------------------------"
if [ -f "/home/forge/.forge/bconnect.64.226.120.45.nip.io.log" ]; then
    echo "📋 Last 30 lines from Forge deployment log:"
    tail -30 /home/forge/.forge/bconnect.64.226.120.45.nip.io.log
else
    echo "❌ Forge deployment log not found"
fi

echo ""
echo "4️⃣ PHP-FPM LOGS:"
echo "-----------------"
if [ -f "/var/log/php8.2-fpm.log" ]; then
    echo "📋 Last 20 lines from PHP-FPM log:"
    tail -20 /var/log/php8.2-fpm.log
else
    echo "❌ PHP-FPM log not found"
fi

echo ""
echo "5️⃣ SYSTEM STATUS:"
echo "------------------"
echo "🔧 Nginx status:"
sudo systemctl status nginx --no-pager -l

echo ""
echo "🔧 PHP-FPM status:"
sudo systemctl status php8.2-fpm --no-pager -l

echo ""
echo "6️⃣ DISK SPACE:"
echo "---------------"
df -h

echo ""
echo "7️⃣ RECENT SYSTEM LOGS:"
echo "-----------------------"
echo "📋 Last 10 system errors:"
sudo journalctl -p err -n 10 --no-pager

echo ""
echo "=================================================="
echo "✅ Log check complete!"
echo ""
echo "🚨 COMMON ISSUES TO CHECK:"
echo "- Look for PHP fatal errors in Laravel log"
echo "- Check for 'Permission denied' in Nginx log"
echo "- Look for 'Connection refused' errors"
echo "- Verify disk space is not full"
echo "- Check if services are running"
