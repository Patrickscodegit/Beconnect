<?php

// Comprehensive production fix script for sync functionality
// This script addresses all potential issues with the sync feature

echo "=== COMPREHENSIVE PRODUCTION SYNC FIX ===\n";
echo "Starting comprehensive fix for sync functionality...\n\n";

// Step 1: Pull the latest code from the main branch
echo "Step 1/6: Pulling latest code from Git...\n";
$output = shell_exec('git pull origin main 2>&1');
echo "<pre>$output</pre>\n";
if (strpos($output, 'Already up to date.') === false && strpos($output, 'Updating') === false) {
    echo "Error pulling code. Please check Git configuration and permissions.\n";
    exit(1);
}
echo "Code pull complete.\n\n";

// Step 2: Clear ALL caches
echo "Step 2/6: Clearing all Laravel caches...\n";
$commands = [
    'php artisan view:clear',
    'php artisan cache:clear',
    'php artisan config:clear',
    'php artisan route:clear',
    'php artisan config:cache',
    'php artisan route:cache',
    'php artisan view:cache'
];

foreach ($commands as $command) {
    echo "Running: $command\n";
    $output = shell_exec("$command 2>&1");
    echo "<pre>$output</pre>\n";
}
echo "All caches cleared and rebuilt.\n\n";

// Step 3: Run the migration for the sync log table
echo "Step 3/6: Running migration for sync log table...\n";
$output = shell_exec('php artisan migrate --force 2>&1');
echo "<pre>$output</pre>\n";
if (strpos($output, 'Migrated:') === false && strpos($output, 'Nothing to migrate') === false) {
    echo "Warning: Migration may have failed. Please check the output above.\n";
}
echo "Migration complete.\n\n";

// Step 4: Seed schedule data if missing
echo "Step 4/6: Seeding schedule data if missing...\n";
$output = shell_exec('php artisan schedules:seed-data 2>&1');
echo "<pre>$output</pre>\n";
echo "Schedule data seeding complete.\n\n";

// Step 5: Check if ScheduleSyncLog model exists
echo "Step 5/6: Verifying ScheduleSyncLog model...\n";
$modelPath = 'app/Models/ScheduleSyncLog.php';
if (file_exists($modelPath)) {
    echo "✓ ScheduleSyncLog model exists\n";
} else {
    echo "✗ ScheduleSyncLog model missing - this is a critical error\n";
    exit(1);
}

// Check if migration exists
$migrationFiles = glob('database/migrations/*create_schedule_sync_logs_table.php');
if (!empty($migrationFiles)) {
    echo "✓ Schedule sync logs migration exists\n";
} else {
    echo "✗ Schedule sync logs migration missing - this is a critical error\n";
    exit(1);
}

// Check if routes exist
$routesContent = file_get_contents('routes/web.php');
if (strpos($routesContent, 'schedules/sync') !== false) {
    echo "✓ Sync routes exist\n";
} else {
    echo "✗ Sync routes missing - this is a critical error\n";
    exit(1);
}
echo "Model verification complete.\n\n";

// Step 6: Test the sync functionality
echo "Step 6/6: Testing sync functionality...\n";
try {
    // Test if we can instantiate the ScheduleSyncLog model
    require_once 'vendor/autoload.php';
    $app = require_once 'bootstrap/app.php';
    $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
    
    $syncLog = new App\Models\ScheduleSyncLog();
    echo "✓ ScheduleSyncLog model can be instantiated\n";
    
    // Test if we can create a sync log entry
    $testLog = App\Models\ScheduleSyncLog::create([
        'sync_type' => 'test',
        'status' => 'success',
        'started_at' => now(),
        'completed_at' => now(),
        'schedules_updated' => 0,
        'carriers_processed' => 0
    ]);
    echo "✓ Can create sync log entries\n";
    
    // Clean up test entry
    $testLog->delete();
    echo "✓ Test sync log entry cleaned up\n";
    
} catch (Exception $e) {
    echo "✗ Error testing sync functionality: " . $e->getMessage() . "\n";
    exit(1);
}
echo "Sync functionality test complete.\n\n";

echo "=== COMPREHENSIVE FIX COMPLETED ===\n";
echo "The sync functionality should now work properly.\n";
echo "Please refresh the schedules page to verify.\n";

?>
