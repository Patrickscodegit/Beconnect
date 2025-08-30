#!/bin/bash

echo "🚀 FORGE DEPLOYMENT DIAGNOSTICS"
echo "==============================="
echo ""

echo "📁 Directory Structure:"
echo "  Current directory: $(pwd)"
echo "  Storage directory exists: $(test -d storage && echo 'YES' || echo 'NO')"
echo "  Storage writable: $(test -w storage && echo 'YES' || echo 'NO')"
echo "  App directory exists: $(test -d app && echo 'YES' || echo 'NO')"
echo ""

echo "🔧 Laravel Status:"
if [ -f artisan ]; then
    echo "  Artisan found: YES"
    
    echo "  Config cache status:"
    php artisan config:show app.env 2>/dev/null || echo "    Config cache may need clearing"
    
    echo "  Queue status:"
    php artisan queue:work --help >/dev/null 2>&1 && echo "    Queue commands available" || echo "    Queue commands not available"
    
    echo "  Environment:"
    php artisan tinker --execute="echo '    APP_ENV: ' . config('app.env'); echo PHP_EOL; echo '    Robaws URL: ' . config('services.robaws.base_url'); echo PHP_EOL;" 2>/dev/null
else
    echo "  Artisan found: NO - Not a Laravel directory?"
fi
echo ""

echo "🌐 Network Connectivity:"
echo "  Can reach Robaws API:"
if command -v curl >/dev/null 2>&1; then
    if curl -s --connect-timeout 5 https://app.robaws.com/api/v2/ping >/dev/null 2>&1; then
        echo "    YES - API endpoint reachable"
    else
        echo "    NO - Cannot reach Robaws API"
    fi
else
    echo "    CURL not available for testing"
fi
echo ""

echo "📊 File Permissions:"
echo "  Storage directory: $(ls -ld storage 2>/dev/null | awk '{print $1}' || echo 'NOT FOUND')"
echo "  Bootstrap cache: $(ls -ld bootstrap/cache 2>/dev/null | awk '{print $1}' || echo 'NOT FOUND')"
echo ""

echo "🔍 Common Issues to Check:"
echo "1. Ensure .env file has correct ROBAWS_* variables"
echo "2. Run 'php artisan config:clear' after env changes"
echo "3. Check storage/logs/laravel.log for detailed errors"
echo "4. Verify queue workers are running if using queues"
echo "5. Ensure proper file permissions (755 for directories, 644 for files)"
echo ""

echo "🚨 Quick Error Check:"
if [ -f storage/logs/laravel.log ]; then
    echo "  Recent Laravel errors:"
    tail -n 20 storage/logs/laravel.log | grep -i "error\|exception\|robaws" | tail -n 5 || echo "    No recent Robaws-related errors found"
else
    echo "  Laravel log file not found"
fi
echo ""

echo "🏁 Diagnostics complete!"
echo "   Run this script on your Forge server to check for deployment issues."
