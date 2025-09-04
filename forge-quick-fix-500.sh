#!/bin/bash

echo "============================================="
echo "BConnect Quick 500 Error Fix Recipe"
echo "Timestamp: $(date)"
echo "============================================="
echo

# Navigate to site directory
cd /home/forge/bconnect.64.226.120.45.nip.io || exit 1

echo "🔧 Applying common 500 error fixes..."
echo

# 1. Fix permissions
echo "1. Fixing file permissions..."
sudo chown -R forge:forge storage/
sudo chown -R forge:forge bootstrap/cache/
sudo chmod -R 775 storage/
sudo chmod -R 775 bootstrap/cache/
echo "✓ Permissions fixed"

# 2. Clear all caches
echo
echo "2. Clearing all caches and compiled files..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan clear-compiled

# Clear Filament cache if it exists
php artisan filament:clear-cached-components 2>/dev/null || echo "Filament cache not found"

echo "✓ All caches cleared"

# 3. Regenerate autoloader
echo
echo "3. Regenerating autoloader..."
composer dump-autoload --optimize
echo "✓ Autoloader regenerated"

# 4. Rebuild configuration cache
echo
echo "4. Rebuilding configuration cache..."
php artisan config:cache
echo "✓ Configuration cache rebuilt"

# 5. Create missing directories
echo
echo "5. Creating missing directories..."
mkdir -p storage/app/public
mkdir -p storage/app/livewire-tmp
mkdir -p storage/logs
mkdir -p storage/framework/cache
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p bootstrap/cache

# Set proper permissions on new directories
sudo chmod -R 775 storage/
sudo chown -R forge:forge storage/
echo "✓ Missing directories created"

# 6. Test basic Laravel functionality
echo
echo "6. Testing Laravel application..."
php artisan about | head -n 10

# 7. Quick storage test
echo
echo "7. Quick storage connectivity test..."
php -r "
try {
    echo 'Testing storage...' . PHP_EOL;
    \$result = file_put_contents(storage_path('logs/quick-test.log'), 'Test: ' . date('Y-m-d H:i:s'));
    if (\$result) {
        echo '✓ Local storage: OK' . PHP_EOL;
        unlink(storage_path('logs/quick-test.log'));
    }
} catch (Exception \$e) {
    echo '✗ Storage test failed: ' . \$e->getMessage() . PHP_EOL;
}
"

# 8. Restart services
echo
echo "8. Restarting services..."
sudo service nginx reload
sudo service php8.3-fpm restart

# Wait a moment for services to restart
sleep 2

echo "✓ Services restarted"

echo
echo "============================================="
echo "✅ Quick fixes applied!"
echo
echo "Now test your application:"
echo "1. Try accessing the homepage"
echo "2. Try uploading a file"
echo "3. Check storage/logs/laravel.log for any new errors"
echo
echo "If 500 error persists, run the analyzer script:"
echo "bash forge-500-error-analyzer.sh"
echo "============================================="
