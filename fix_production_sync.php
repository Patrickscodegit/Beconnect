<?php

// This script is intended to be run directly on the production server
// to pull the latest code and clear view cache for the sync functionality.

echo "Starting production fix for sync functionality...\n";

// Step 1: Pull the latest code from the main branch
echo "Step 1/3: Pulling latest code from Git...\n";
$output = shell_exec('git pull origin main 2>&1');
echo "<pre>$output</pre>\n";
if (strpos($output, 'Already up to date.') === false && strpos($output, 'Updating') === false) {
    echo "Error pulling code. Please check Git configuration and permissions.\n";
    exit(1);
}
echo "Code pull complete.\n\n";

// Step 2: Clear view cache to remove old compiled views
echo "Step 2/3: Clearing view cache...\n";
$output = shell_exec('php artisan view:clear 2>&1');
echo "<pre>$output</pre>\n";
echo "View cache cleared.\n\n";

// Step 3: Run the migration for the sync log table
echo "Step 3/3: Running migration for sync log table...\n";
$output = shell_exec('php artisan migrate --force 2>&1');
echo "<pre>$output</pre>\n";
if (strpos($output, 'Migrated:') === false && strpos($output, 'Nothing to migrate') === false) {
    echo "Warning: Migration may have failed. Please check the output above.\n";
}
echo "Migration complete.\n\n";

echo "Production fix script finished. The sync functionality should now work properly.\n";

?>
