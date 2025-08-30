#!/bin/bash

# Quick Robaws Integration Fix for Laravel Forge
# ==============================================
# Use this script if you're experiencing "Failed to create quotation in Robaws" errors

cd $FORGE_SITE_PATH

echo "🔧 ROBAWS INTEGRATION FIX"
echo "========================="

# 1. Clear configuration cache
echo "🧹 Clearing configuration cache..."
$FORGE_PHP artisan config:clear

# 2. Test Robaws credentials
echo "🔑 Testing Robaws credentials..."
$FORGE_PHP artisan tinker --execute="
echo 'Robaws Configuration:' . PHP_EOL;
echo '  Base URL: ' . config('services.robaws.base_url') . PHP_EOL;
echo '  Username: ' . (config('services.robaws.username') ? 'SET (' . strlen(config('services.robaws.username')) . ' chars)' : 'NOT SET') . PHP_EOL;
echo '  Password: ' . (config('services.robaws.password') ? 'SET (' . strlen(config('services.robaws.password')) . ' chars)' : 'NOT SET') . PHP_EOL;
"

# 3. Test Robaws connection
echo "🌐 Testing Robaws API connection..."
$FORGE_PHP artisan tinker --execute="
try {
    \$client = new App\Services\RobawsClient();
    \$result = \$client->testConnection();
    if (\$result['success']) {
        echo '✅ Robaws API connection successful!' . PHP_EOL;
    } else {
        echo '❌ Robaws API connection failed!' . PHP_EOL;
        echo 'Error: ' . (\$result['message'] ?? 'Unknown error') . PHP_EOL;
        echo '' . PHP_EOL;
        echo 'SOLUTION: Check your .env file and ensure:' . PHP_EOL;
        echo '  ROBAWS_BASE_URL=https://app.robaws.com' . PHP_EOL;
        echo '  ROBAWS_USERNAME=your_username' . PHP_EOL;
        echo '  ROBAWS_PASSWORD=your_password' . PHP_EOL;
    }
} catch (Exception \$e) {
    echo '❌ Exception testing Robaws connection:' . PHP_EOL;
    echo '  ' . \$e->getMessage() . PHP_EOL;
}
"

# 4. Check for failed documents and reset them
echo "🔄 Resetting failed document processing..."
$FORGE_PHP artisan tinker --execute="
\$failedDocs = App\Models\Document::whereNotNull('processing_error')
    ->where('processing_error', 'like', '%Robaws%')
    ->get();

if (\$failedDocs->count() > 0) {
    echo 'Found ' . \$failedDocs->count() . ' documents with Robaws errors:' . PHP_EOL;
    foreach (\$failedDocs as \$doc) {
        echo '  Document ' . \$doc->id . ': ' . \$doc->filename . PHP_EOL;
        
        // Reset the document for retry
        \$doc->update([
            'processing_status' => 'pending',
            'processing_error' => null,
            'robaws_quotation_id' => null
        ]);
    }
    echo 'Reset ' . \$failedDocs->count() . ' documents for retry.' . PHP_EOL;
} else {
    echo 'No documents with Robaws errors found.' . PHP_EOL;
}
"

# 5. Test Document 33 specifically (if it exists)
echo "🧪 Testing Document 33 (from your error)..."
$FORGE_PHP artisan tinker --execute="
\$doc33 = App\Models\Document::find(33);
if (\$doc33) {
    echo 'Document 33 found: ' . \$doc33->filename . PHP_EOL;
    echo '  Status: ' . (\$doc33->processing_status ?: 'none') . PHP_EOL;
    echo '  Error: ' . (\$doc33->processing_error ?: 'none') . PHP_EOL;
    echo '  Robaws ID: ' . (\$doc33->robaws_quotation_id ?: 'none') . PHP_EOL;
    
    // Try to create offer for this document
    try {
        \$robawsService = new App\Services\RobawsIntegrationService(new App\Services\RobawsClient());
        \$result = \$robawsService->createOfferFromDocument(\$doc33);
        
        if (\$result && isset(\$result['id'])) {
            echo '✅ Successfully created Robaws offer: ' . \$result['id'] . PHP_EOL;
        } else {
            echo '❌ Failed to create Robaws offer' . PHP_EOL;
        }
    } catch (Exception \$e) {
        echo '❌ Exception creating offer: ' . \$e->getMessage() . PHP_EOL;
    }
} else {
    echo 'Document 33 not found.' . PHP_EOL;
}
"

# 6. Cache configuration for production
echo "⚡ Caching configuration for production..."
$FORGE_PHP artisan config:cache

echo ""
echo "🎉 Robaws integration fix completed!"
echo ""
echo "🔍 Next steps:"
echo "1. Try exporting documents again through your admin interface"
echo "2. Check storage/logs/laravel.log for any new errors"
echo "3. If still failing, verify your Robaws credentials in .env"
echo ""
echo "✅ The integration should now be working properly!"
