#!/bin/bash

echo "============================================="
echo "BConnect File Upload Test"
echo "Timestamp: $(date)"
echo "============================================="
echo

cd /home/forge/bconnect.64.226.120.45.nip.io || exit 1

echo "🧪 Testing file upload functionality..."
echo

# Test 1: Configuration verification
echo "1. Verifying configuration..."
php artisan tinker --execute="
echo 'Configuration Test:' . PHP_EOL;
echo '==================' . PHP_EOL;
echo 'App Environment: ' . config('app.env') . PHP_EOL;
echo 'Default Disk: ' . config('filesystems.default') . PHP_EOL;
echo 'Livewire Temp Disk: ' . config('livewire.temporary_file_uploads.disk') . PHP_EOL;
echo 'Livewire Temp Path: ' . config('livewire.temporary_file_uploads.path') . PHP_EOL;
echo 'Spaces Region: ' . config('filesystems.disks.spaces.region') . PHP_EOL;
echo 'Spaces Bucket: ' . config('filesystems.disks.spaces.bucket') . PHP_EOL;
echo 'Spaces Endpoint: ' . config('filesystems.disks.spaces.endpoint') . PHP_EOL;
"

echo

# Test 2: Storage connectivity
echo "2. Testing storage connectivity..."
php artisan tinker --execute="
try {
    echo 'Storage Connectivity Test:' . PHP_EOL;
    echo '=========================' . PHP_EOL;
    
    \$storage = Storage::disk('spaces');
    
    // Test basic write
    \$testFile = 'test-upload-' . time() . '.txt';
    \$result = \$storage->put(\$testFile, 'Upload test at ' . now());
    
    if (\$result) {
        echo '✓ Basic storage write: SUCCESS' . PHP_EOL;
        
        // Test file exists
        if (\$storage->exists(\$testFile)) {
            echo '✓ File exists check: SUCCESS' . PHP_EOL;
        }
        
        // Test file read
        \$content = \$storage->get(\$testFile);
        if (strpos(\$content, 'Upload test') !== false) {
            echo '✓ File read verification: SUCCESS' . PHP_EOL;
        }
        
        // Clean up
        \$storage->delete(\$testFile);
        echo '✓ File cleanup: SUCCESS' . PHP_EOL;
        
    } else {
        echo '✗ Basic storage write: FAILED' . PHP_EOL;
    }
    
} catch (Exception \$e) {
    echo '✗ Storage test FAILED: ' . \$e->getMessage() . PHP_EOL;
}
"

echo

# Test 3: Livewire temp directory
echo "3. Testing Livewire temporary upload directory..."
php artisan tinker --execute="
try {
    echo 'Livewire Temp Directory Test:' . PHP_EOL;
    echo '=============================' . PHP_EOL;
    
    \$storage = Storage::disk('spaces');
    \$tempPath = 'livewire-tmp';
    
    // Ensure directory exists
    if (!\$storage->exists(\$tempPath)) {
        \$storage->makeDirectory(\$tempPath);
        echo '✓ Created livewire-tmp directory' . PHP_EOL;
    } else {
        echo '✓ livewire-tmp directory exists' . PHP_EOL;
    }
    
    // Test temp file upload
    \$tempFile = \$tempPath . '/temp-' . time() . '.txt';
    \$result = \$storage->put(\$tempFile, 'Temporary file test');
    
    if (\$result) {
        echo '✓ Temp file upload: SUCCESS' . PHP_EOL;
        \$storage->delete(\$tempFile);
        echo '✓ Temp file cleanup: SUCCESS' . PHP_EOL;
    } else {
        echo '✗ Temp file upload: FAILED' . PHP_EOL;
    }
    
} catch (Exception \$e) {
    echo '✗ Livewire temp test FAILED: ' . \$e->getMessage() . PHP_EOL;
}
"

echo

# Test 4: Application routes
echo "4. Testing application response..."
echo "Home page status:"
curl -s -o /dev/null -w "%{http_code}" http://localhost/
echo

echo "Admin page status:"
curl -s -o /dev/null -w "%{http_code}" http://localhost/admin
echo

echo

# Test 5: Check for new errors
echo "5. Checking for new errors in logs..."
if [ -f "storage/logs/laravel.log" ]; then
    echo "Recent log entries (last 5 lines):"
    tail -n 5 storage/logs/laravel.log
else
    echo "No log file found (good sign!)"
fi

echo
echo "============================================="
echo "📋 TEST SUMMARY"
echo "============================================="
echo
echo "If all tests show SUCCESS, your file upload should work!"
echo "If any test shows FAILED, check the error messages above."
echo
echo "Next steps:"
echo "1. Open your application in browser"
echo "2. Try uploading a file"
echo "3. Check if 500 error is resolved"
echo
echo "If you still get 500 errors, check storage/logs/laravel.log"
echo "============================================="
