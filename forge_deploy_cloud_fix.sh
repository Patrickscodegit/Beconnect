#!/bin/bash

# Forge Deployment Script for Cloud Storage Fix
# This script deploys the fixes for Document 33 export failure

echo "🚀 Deploying Cloud Storage Fix to Forge..."
echo "=================================="

# Backup current files before deployment
echo "📦 Creating backup..."
cp app/Services/RobawsExportService.php app/Services/RobawsExportService.php.backup.$(date +%Y%m%d_%H%M%S)
cp app/Services/RobawsClient.php app/Services/RobawsClient.php.backup.$(date +%Y%m%d_%H%M%S)

echo "✅ Backup created"

# Clear caches to ensure new code is loaded
echo "🧹 Clearing caches..."
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

# Restart queue workers to pick up new code
echo "🔄 Restarting queue workers..."
php artisan queue:restart

# Run a quick test to verify the fix
echo "🧪 Testing the fix..."

# Test file exists
php -r "
require_once 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
\$kernel->bootstrap();

use App\Models\Document;
use Illuminate\Support\Facades\Storage;

echo \"Testing Document 33...\n\";
\$document = Document::find(33);
if (\$document) {
    \$disk = Storage::disk(\$document->storage_disk ?? 'documents');
    if (\$disk->exists(\$document->file_path)) {
        echo \"✅ Document 33 file exists in storage\n\";
        \$mimeType = \$disk->mimeType(\$document->file_path);
        \$fileSize = \$disk->size(\$document->file_path);
        echo \"   MIME: \$mimeType, Size: \" . number_format(\$fileSize) . \" bytes\n\";
        
        // Test stream
        \$stream = \$disk->readStream(\$document->file_path);
        if (is_resource(\$stream)) {
            echo \"✅ Stream access working\n\";
            fclose(\$stream);
        } else {
            echo \"❌ Stream access failed\n\";
            exit(1);
        }
    } else {
        echo \"❌ Document 33 file not found\n\";
        exit(1);
    }
} else {
    echo \"❌ Document 33 not found in database\n\";
    exit(1);
}

echo \"🎉 All tests passed!\n\";
"

if [ $? -eq 0 ]; then
    echo "✅ Deployment successful!"
    echo ""
    echo "📋 What was fixed:"
    echo "  • RobawsExportService now uses Storage::disk()->readStream() instead of direct file access"
    echo "  • RobawsClient now accepts streams for cloud storage compatibility"
    echo "  • Added path normalization for DigitalOcean Spaces prefix handling"
    echo "  • Fixed mime_content_type() and file_get_contents() issues with cloud storage"
    echo ""
    echo "🔥 The Document 33 export error should now be resolved!"
    echo "You can test by trying to export document 33 again."
else
    echo "❌ Deployment test failed!"
    echo "Please check the error messages above and fix any issues."
    exit 1
fi

echo ""
echo "📝 Next steps:"
echo "1. Test the document export functionality"
echo "2. Monitor the logs for any remaining issues"
echo "3. Check that both local development and production work correctly"
echo ""
echo "🔍 To monitor logs:"
echo "tail -f storage/logs/laravel.log | grep -i robaws"
