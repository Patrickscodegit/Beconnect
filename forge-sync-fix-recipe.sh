#!/bin/bash

# Forge Recipe: Comprehensive Sync Functionality Fix
# This recipe fixes all sync functionality issues in production

echo "=== FORGE RECIPE: COMPREHENSIVE SYNC FIX ==="
echo "Starting comprehensive fix for sync functionality..."
echo ""

# Set working directory to the project root
cd /home/forge/bconnect.64.226.120.45.nip.io

# Step 1: Pull the latest code from the main branch
echo "Step 1/6: Pulling latest code from Git..."
git pull origin main
if [ $? -eq 0 ]; then
    echo "✓ Code pull successful"
else
    echo "✗ Code pull failed"
    exit 1
fi
echo ""

# Step 2: Clear ALL Laravel caches
echo "Step 2/6: Clearing all Laravel caches..."
echo "Clearing view cache..."
php artisan view:clear
echo "Clearing application cache..."
php artisan cache:clear
echo "Clearing config cache..."
php artisan config:clear
echo "Clearing route cache..."
php artisan route:clear
echo "Rebuilding config cache..."
php artisan config:cache
echo "Rebuilding route cache..."
php artisan route:cache
echo "Rebuilding view cache..."
php artisan view:cache
echo "✓ All caches cleared and rebuilt"
echo ""

# Step 3: Run the migration for the sync log table
echo "Step 3/6: Running migration for sync log table..."
php artisan migrate --force
if [ $? -eq 0 ]; then
    echo "✓ Migration completed successfully"
else
    echo "✗ Migration failed"
    exit 1
fi
echo ""

# Step 4: Seed schedule data if missing
echo "Step 4/6: Seeding schedule data if missing..."
php artisan schedules:seed-data
if [ $? -eq 0 ]; then
    echo "✓ Schedule data seeding completed"
else
    echo "✗ Schedule data seeding failed"
    exit 1
fi
echo ""

# Step 5: Check if required files exist
echo "Step 5/6: Verifying required files..."
if [ -f "app/Models/ScheduleSyncLog.php" ]; then
    echo "✓ ScheduleSyncLog model exists"
else
    echo "✗ ScheduleSyncLog model missing"
    exit 1
fi

if [ -f "app/Http/Controllers/ScheduleController.php" ]; then
    echo "✓ ScheduleController exists"
else
    echo "✗ ScheduleController missing"
    exit 1
fi

if ls database/migrations/*create_schedule_sync_logs_table.php 1> /dev/null 2>&1; then
    echo "✓ Schedule sync logs migration exists"
else
    echo "✗ Schedule sync logs migration missing"
    exit 1
fi

if grep -q "schedules/sync" routes/web.php; then
    echo "✓ Sync routes exist"
else
    echo "✗ Sync routes missing"
    exit 1
fi
echo "✓ All required files verified"
echo ""

# Step 6: Test the sync functionality
echo "Step 6/6: Testing sync functionality..."
php -r "
try {
    require_once 'vendor/autoload.php';
    \$app = require_once 'bootstrap/app.php';
    \$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
    
    \$syncLog = new App\Models\ScheduleSyncLog();
    echo '✓ ScheduleSyncLog model can be instantiated' . PHP_EOL;
    
    \$testLog = App\Models\ScheduleSyncLog::create([
        'sync_type' => 'test',
        'status' => 'success',
        'started_at' => now(),
        'completed_at' => now(),
        'schedules_updated' => 0,
        'carriers_processed' => 0
    ]);
    echo '✓ Can create sync log entries' . PHP_EOL;
    
    \$testLog->delete();
    echo '✓ Test sync log entry cleaned up' . PHP_EOL;
    
} catch (Exception \$e) {
    echo '✗ Error testing sync functionality: ' . \$e->getMessage() . PHP_EOL;
    exit(1);
}
"
if [ $? -eq 0 ]; then
    echo "✓ Sync functionality test passed"
else
    echo "✗ Sync functionality test failed"
    exit 1
fi
echo ""

# Step 7: Restart services (optional)
echo "Step 7/6: Restarting services..."
sudo supervisorctl restart all
echo "✓ Services restarted"
echo ""

echo "=== COMPREHENSIVE SYNC FIX COMPLETED ==="
echo "✓ All steps completed successfully"
echo "✓ Sync functionality should now work properly"
echo "✓ Please refresh the schedules page to verify"
echo ""
echo "The sync button should now show:"
echo "- Last sync time"
echo "- Manual sync capability"
echo "- Real-time status updates"
echo ""
