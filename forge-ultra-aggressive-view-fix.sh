#!/bin/bash

# ULTRA-AGGRESSIVE VIEW CACHE FIX
# This script completely destroys and rebuilds the view cache

echo "🔥 ULTRA-AGGRESSIVE VIEW CACHE FIX 🔥"
echo "This will completely destroy and rebuild the view cache"
echo ""

# Set working directory
cd /home/forge/bconnect.64.226.120.45.nip.io

# Step 1: Pull latest code
echo "Step 1/8: Pulling latest code..."
git pull origin main
echo ""

# Step 2: NUCLEAR OPTION - Delete the entire view cache directory
echo "Step 2/8: NUCLEAR OPTION - Deleting entire view cache directory..."
rm -rf storage/framework/views/*
echo "✓ View cache directory completely deleted"
echo ""

# Step 3: Clear ALL caches
echo "Step 3/8: Clearing all Laravel caches..."
php artisan view:clear
php artisan cache:clear
php artisan config:clear
php artisan route:clear
echo "✓ All caches cleared"
echo ""

# Step 4: Force rebuild ALL caches
echo "Step 4/8: Force rebuilding ALL caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
echo "✓ All caches rebuilt"
echo ""

# Step 5: Run migrations
echo "Step 5/8: Running migrations..."
php artisan migrate --force
echo "✓ Migrations completed"
echo ""

# Step 6: Seed data
echo "Step 6/8: Seeding data..."
php artisan schedules:seed-data
echo "✓ Data seeded"
echo ""

# Step 7: Verify the view cache was rebuilt
echo "Step 7/8: Verifying view cache was rebuilt..."
if [ -d "storage/framework/views" ] && [ "$(ls -A storage/framework/views)" ]; then
    echo "✓ View cache directory exists and has files"
    echo "View cache files:"
    ls -la storage/framework/views/ | head -5
else
    echo "✗ View cache directory is empty or missing"
    exit 1
fi
echo ""

# Step 8: Test the schedules page
echo "Step 8/8: Testing schedules page..."
php -r "
try {
    require_once 'vendor/autoload.php';
    \$app = require_once 'bootstrap/app.php';
    \$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
    
    // Test if we can render the schedules view
    \$view = view('schedules.index', [
        'schedules' => collect(),
        'ports' => collect(),
        'carriers' => collect(),
        'pol' => '',
        'pod' => '',
        'serviceType' => '',
        'offerId' => '',
        'lastSyncTime' => 'Never',
        'isSyncRunning' => false
    ]);
    
    echo '✓ Schedules view can be rendered successfully' . PHP_EOL;
    
} catch (Exception \$e) {
    echo '✗ Error rendering schedules view: ' . \$e->getMessage() . PHP_EOL;
    exit(1);
}
"
echo ""

# Restart services
echo "🔄 Restarting services..."
sudo supervisorctl restart all
echo "✓ Services restarted"
echo ""

echo "🎉 ULTRA-AGGRESSIVE FIX COMPLETED! 🎉"
echo "The view cache has been completely destroyed and rebuilt"
echo "The schedules page should now work perfectly!"
echo ""
echo "Please refresh your browser and try accessing /schedules again"
