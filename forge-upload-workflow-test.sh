#!/bin/bash

echo "============================================="
echo "BConnect File Upload Workflow Test"
echo "Timestamp: $(date)"
echo "============================================="
echo

cd /home/forge/bconnect.64.226.120.45.nip.io || exit 1

echo "🧪 Testing file upload workflow that causes 500 error..."
echo

# 1. Test Livewire temporary file upload configuration
echo "1. Testing Livewire configuration..."
php -r "
echo 'Livewire Config Check:' . PHP_EOL;
echo '======================' . PHP_EOL;

// Check Livewire config
\$config = config('livewire.temporary_file_uploads');
echo 'Temp disk: ' . (\$config['disk'] ?? 'NOT SET') . PHP_EOL;
echo 'Temp path: ' . (\$config['path'] ?? 'NOT SET') . PHP_EOL;
echo 'Max upload time: ' . (\$config['max_upload_time'] ?? 'NOT SET') . ' minutes' . PHP_EOL;
echo 'Rules: ' . implode(', ', \$config['rules'] ?? []) . PHP_EOL;
echo PHP_EOL;

// Check filesystem config
\$filesystems = config('filesystems');
echo 'Default disk: ' . \$filesystems['default'] . PHP_EOL;

if (isset(\$filesystems['disks']['spaces'])) {
    \$spaces = \$filesystems['disks']['spaces'];
    echo 'Spaces region: ' . (\$spaces['region'] ?? 'NOT SET') . PHP_EOL;
    echo 'Spaces bucket: ' . (\$spaces['bucket'] ?? 'NOT SET') . PHP_EOL;
    echo 'Spaces endpoint: ' . (\$spaces['endpoint'] ?? 'NOT SET') . PHP_EOL;
} else {
    echo 'Spaces disk not configured!' . PHP_EOL;
}
"

echo

# 2. Test storage disk connectivity
echo "2. Testing storage disk connectivity..."
php -r "
try {
    \$disk = config('livewire.temporary_file_uploads.disk', config('filesystems.default'));
    echo 'Testing disk: ' . \$disk . PHP_EOL;
    
    \$storage = Storage::disk(\$disk);
    
    // Test basic operations
    \$testFile = 'livewire-tmp/upload-test-' . time() . '.txt';
    \$testContent = 'Upload test at ' . now() . PHP_EOL;
    
    echo 'Attempting to write test file...' . PHP_EOL;
    \$result = \$storage->put(\$testFile, \$testContent);
    
    if (\$result) {
        echo '✓ File write: SUCCESS' . PHP_EOL;
        
        // Test file exists
        if (\$storage->exists(\$testFile)) {
            echo '✓ File exists check: SUCCESS' . PHP_EOL;
        } else {
            echo '✗ File exists check: FAILED' . PHP_EOL;
        }
        
        // Test file read
        \$content = \$storage->get(\$testFile);
        if (\$content === \$testContent) {
            echo '✓ File read: SUCCESS' . PHP_EOL;
        } else {
            echo '✗ File read: FAILED' . PHP_EOL;
        }
        
        // Clean up
        \$storage->delete(\$testFile);
        echo '✓ File cleanup: SUCCESS' . PHP_EOL;
        
    } else {
        echo '✗ File write: FAILED' . PHP_EOL;
    }
    
} catch (Exception \$e) {
    echo '✗ Storage test EXCEPTION: ' . \$e->getMessage() . PHP_EOL;
    echo 'File: ' . \$e->getFile() . ' Line: ' . \$e->getLine() . PHP_EOL;
    
    // Print more details for common exceptions
    if (strpos(\$e->getMessage(), 'credentials') !== false) {
        echo PHP_EOL . 'CREDENTIAL ERROR - Check:' . PHP_EOL;
        echo '- SPACES_KEY is set and valid' . PHP_EOL;
        echo '- SPACES_SECRET is set and valid' . PHP_EOL;
        echo '- Credentials have proper permissions' . PHP_EOL;
    }
    
    if (strpos(\$e->getMessage(), 'region') !== false || strpos(\$e->getMessage(), 'endpoint') !== false) {
        echo PHP_EOL . 'REGION/ENDPOINT ERROR - Check:' . PHP_EOL;
        echo '- SPACES_REGION=fra1' . PHP_EOL;
        echo '- SPACES_ENDPOINT=https://fra1.digitaloceanspaces.com' . PHP_EOL;
        echo '- SPACES_BUCKET=bconnect-documents' . PHP_EOL;
    }
}
"

echo

# 3. Test specific Livewire temp upload directory
echo "3. Testing Livewire temp directory access..."
php -r "
try {
    \$disk = config('livewire.temporary_file_uploads.disk', config('filesystems.default'));
    \$path = config('livewire.temporary_file_uploads.path', 'livewire-tmp');
    
    \$storage = Storage::disk(\$disk);
    
    // Ensure directory exists
    if (!\$storage->exists(\$path)) {
        echo 'Creating livewire-tmp directory...' . PHP_EOL;
        \$storage->makeDirectory(\$path);
    }
    
    // Test directory listing
    \$files = \$storage->files(\$path);
    echo 'Files in ' . \$path . ': ' . count(\$files) . PHP_EOL;
    
    // Test write in temp directory
    \$tempFile = \$path . '/temp-test-' . time() . '.txt';
    \$result = \$storage->put(\$tempFile, 'Temporary upload test');
    
    if (\$result) {
        echo '✓ Temp directory write: SUCCESS' . PHP_EOL;
        \$storage->delete(\$tempFile);
        echo '✓ Temp file cleanup: SUCCESS' . PHP_EOL;
    } else {
        echo '✗ Temp directory write: FAILED' . PHP_EOL;
    }
    
} catch (Exception \$e) {
    echo '✗ Temp directory test EXCEPTION: ' . \$e->getMessage() . PHP_EOL;
}
"

echo

# 4. Test CORS configuration for uploads
echo "4. Testing CORS configuration..."
SPACES_BUCKET=$(grep '^SPACES_BUCKET=' .env | cut -d'=' -f2)
SPACES_REGION=$(grep '^SPACES_REGION=' .env | cut -d'=' -f2 | tr -d ' ')

if [ -n "$SPACES_BUCKET" ] && [ -n "$SPACES_REGION" ]; then
    echo "Testing CORS for bucket: $SPACES_BUCKET in region: $SPACES_REGION"
    
    # Test CORS preflight request
    curl -s -I -X OPTIONS \
        "https://${SPACES_BUCKET}.${SPACES_REGION}.digitaloceanspaces.com/livewire-tmp/test" \
        -H "Origin: https://bconnect.64.226.120.45.nip.io" \
        -H "Access-Control-Request-Method: PUT" \
        -H "Access-Control-Request-Headers: content-type" | head -n 10
else
    echo "Missing SPACES_BUCKET or SPACES_REGION in .env"
fi

echo

# 5. Check for recent upload errors in logs
echo "5. Checking for recent upload-related errors..."
if [ -f "storage/logs/laravel.log" ]; then
    echo "Recent upload/livewire errors:"
    grep -i -A 3 -B 3 "livewire\|upload\|temporary.*file\|spaces\|s3.*exception" storage/logs/laravel.log | tail -n 20
else
    echo "No Laravel log file found"
fi

echo

# 6. Test specific route that handles file uploads
echo "6. Testing file upload routes..."
echo "Available routes containing 'upload' or 'file':"
php artisan route:list | grep -i -E "upload|file|livewire" | head -n 10

echo
echo "============================================="
echo "🔍 Workflow test complete!"
echo
echo "Key things to check:"
echo "1. Livewire temp disk configuration"
echo "2. DigitalOcean Spaces connectivity"
echo "3. CORS configuration"
echo "4. File permissions and directories"
echo "5. Recent error logs"
echo "============================================="
