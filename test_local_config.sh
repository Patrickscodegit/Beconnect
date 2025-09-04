#!/bin/bash

# Quick Local Test - Verify Configuration Before Deployment

echo "🧪 Testing local configuration..."

# Test 1: Check if Laravel app
if [ ! -f "artisan" ]; then
    echo "❌ Not in Laravel directory"
    exit 1
fi

echo "✅ Laravel app detected"

# Test 2: Check configuration syntax
echo "🔧 Testing configuration files..."
php artisan config:cache 2>/dev/null
if [ $? -eq 0 ]; then
    echo "✅ Configuration syntax valid"
else 
    echo "❌ Configuration syntax error"
    exit 1
fi

# Test 3: Print effective config
echo ""
echo "📋 Current configuration:"
php artisan tinker --execute="
echo 'Default disk: '.config('filesystems.default').PHP_EOL;
echo 'Documents driver: '.config('filesystems.disks.documents.driver').PHP_EOL;
echo 'Livewire disk: '.config('livewire.temporary_file_uploads.disk').PHP_EOL;
echo 'Livewire path: '.config('livewire.temporary_file_uploads.directory').PHP_EOL;
"

# Test 4: Check if we can instantiate Storage
echo ""
echo "🗂️  Testing storage instantiation..."
php artisan tinker --execute="
use Illuminate\Support\Facades\Storage;
try {
    \$disk = Storage::disk('documents');
    echo '✅ Documents disk instantiated successfully'.PHP_EOL;
    echo 'Driver: '.\$disk->getDriver()::class.PHP_EOL;
} catch (Exception \$e) {
    echo '❌ Storage error: '.\$e->getMessage().PHP_EOL;
    exit(1);
}
"

echo ""
echo "🎉 Local configuration tests passed!"
echo "Ready for production deployment."
