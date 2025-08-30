#!/bin/bash

# Laravel Forge Deployment Recipe for Robaws Integration
# =====================================================
# This script handles the deployment and configuration for the Robaws integration

cd $FORGE_SITE_PATH

# 1. Update composer dependencies
echo "📦 Installing Composer dependencies..."
$FORGE_COMPOSER install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# 2. Clear all caches to ensure fresh configuration
echo "🧹 Clearing application caches..."
$FORGE_PHP artisan config:clear
$FORGE_PHP artisan cache:clear
$FORGE_PHP artisan route:clear
$FORGE_PHP artisan view:clear

# 3. Run database migrations
echo "🗄️ Running database migrations..."
$FORGE_PHP artisan migrate --force

# 4. Cache configuration for production
echo "⚡ Caching configuration..."
$FORGE_PHP artisan config:cache
$FORGE_PHP artisan route:cache
$FORGE_PHP artisan view:cache

# 5. Set proper storage permissions
echo "🔐 Setting storage permissions..."
chmod -R 775 storage
chmod -R 775 bootstrap/cache

# 6. Ensure storage disk column exists and is populated
echo "📊 Updating document storage disk values..."
$FORGE_PHP artisan tinker --execute="
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

// Ensure storage_disk column exists
if (!Schema::hasColumn('documents', 'storage_disk')) {
    echo 'Adding storage_disk column...' . PHP_EOL;
    Schema::table('documents', function (\$table) {
        \$table->string('storage_disk')->default('local')->after('file_size');
    });
}

// Update existing documents to have storage_disk value based on environment
\$disk = app()->environment('production') ? 'spaces' : 'local';
\$updated = DB::table('documents')
    ->whereNull('storage_disk')
    ->orWhere('storage_disk', '')
    ->update(['storage_disk' => \$disk]);
    
echo 'Updated ' . \$updated . ' documents with storage_disk = ' . \$disk . PHP_EOL;
"

# 7. Test Robaws connection
echo "🌐 Testing Robaws connection..."
$FORGE_PHP artisan tinker --execute="
try {
    \$client = new App\Services\RobawsClient();
    \$result = \$client->testConnection();
    if (\$result['success']) {
        echo '✅ Robaws connection successful' . PHP_EOL;
    } else {
        echo '❌ Robaws connection failed: ' . (\$result['message'] ?? 'Unknown error') . PHP_EOL;
        echo 'Check your ROBAWS_* environment variables in .env' . PHP_EOL;
    }
} catch (Exception \$e) {
    echo '❌ Robaws connection exception: ' . \$e->getMessage() . PHP_EOL;
}
"

# 8. Restart queue workers if they exist
echo "🔄 Restarting queue workers..."
if $FORGE_PHP artisan queue:restart >/dev/null 2>&1; then
    echo "✅ Queue workers restarted"
else
    echo "ℹ️ No queue workers to restart"
fi

# 9. Run a quick system health check
echo "🏥 Running system health check..."
$FORGE_PHP artisan tinker --execute="
echo '🔍 System Health Check:' . PHP_EOL;
echo '  Environment: ' . config('app.env') . PHP_EOL;
echo '  Debug Mode: ' . (config('app.debug') ? 'ON' : 'OFF') . PHP_EOL;
echo '  Storage Disk: ' . config('filesystems.default') . PHP_EOL;
echo '  Robaws URL: ' . config('services.robaws.base_url') . PHP_EOL;
echo '  Robaws Username: ' . (config('services.robaws.username') ? 'SET' : 'NOT SET') . PHP_EOL;
echo '  Robaws Password: ' . (config('services.robaws.password') ? 'SET' : 'NOT SET') . PHP_EOL;

\$documentCount = App\Models\Document::count();
\$pendingCount = App\Models\Document::where('processing_status', 'pending')->count();
\$robawsCount = App\Models\Document::whereNotNull('robaws_quotation_id')->count();

echo '  Total Documents: ' . \$documentCount . PHP_EOL;
echo '  Pending Processing: ' . \$pendingCount . PHP_EOL;
echo '  With Robaws ID: ' . \$robawsCount . PHP_EOL;
"

echo ""
echo "🎉 Deployment completed successfully!"
echo ""
echo "🔍 If you're still experiencing issues:"
echo "1. Check storage/logs/laravel.log for detailed error messages"
echo "2. Verify your .env file has correct ROBAWS_* variables"
echo "3. Ensure queue workers are running if using background jobs"
echo "4. Test the integration manually through the admin interface"
echo ""
echo "✅ Your Robaws integration should now be working on production!"
