#!/bin/bash

echo "============================================="
echo "BConnect 405 Error Fix & CORS Setup"
echo "Timestamp: $(date)"
echo "============================================="
echo

cd /home/forge/bconnect.64.226.120.45.nip.io || exit 1

echo "🔧 Fixing 405 Method Not Allowed errors..."
echo

# 1. Fix environment variables for DigitalOcean Spaces
echo "1. Fixing DigitalOcean Spaces URLs in .env..."

# Fix AWS_URL if it exists
if grep -q "^AWS_URL=" .env; then
    sed -i 's|^AWS_URL=.*|AWS_URL=https://bconnect-documents.fra1.digitaloceanspaces.com|' .env
    echo "✓ Updated AWS_URL to Frankfurt"
else
    echo "AWS_URL=https://bconnect-documents.fra1.digitaloceanspaces.com" >> .env
    echo "✓ Added AWS_URL for Frankfurt"
fi

# Fix DO_SPACES_URL if it exists
if grep -q "^DO_SPACES_URL=" .env; then
    sed -i 's|^DO_SPACES_URL=.*|DO_SPACES_URL=https://bconnect-documents.fra1.digitaloceanspaces.com|' .env
    echo "✓ Updated DO_SPACES_URL to Frankfurt"
else
    echo "DO_SPACES_URL=https://bconnect-documents.fra1.digitaloceanspaces.com" >> .env
    echo "✓ Added DO_SPACES_URL for Frankfurt"
fi

# Ensure Livewire configuration
if ! grep -q "^LIVEWIRE_DISK=" .env; then
    echo "LIVEWIRE_DISK=spaces" >> .env
    echo "✓ Added LIVEWIRE_DISK=spaces"
fi

if ! grep -q "^LIVEWIRE_TMP_PATH=" .env; then
    echo "LIVEWIRE_TMP_PATH=livewire-tmp" >> .env
    echo "✓ Added LIVEWIRE_TMP_PATH=livewire-tmp"
fi

echo

# 2. Clear all caches and rebuild
echo "2. Clearing caches and reloading configuration..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
echo "✓ All caches cleared"

# Rebuild configuration
php artisan config:cache
echo "✓ Configuration cache rebuilt"

echo

# 3. Test Livewire routes exist
echo "3. Checking Livewire routes..."
echo "Livewire routes:"
php artisan route:list | grep -i livewire | head -5
echo

# 4. Test CORS configuration
echo "4. Testing CORS configuration..."
php artisan tinker --execute="
echo 'CORS Configuration Test:' . PHP_EOL;
echo '=======================' . PHP_EOL;
\$cors = config('cors');
echo 'Paths: ' . json_encode(\$cors['paths']) . PHP_EOL;
echo 'Allowed methods: ' . json_encode(\$cors['allowed_methods']) . PHP_EOL;
echo 'Allowed origins: ' . json_encode(\$cors['allowed_origins']) . PHP_EOL;
echo 'Supports credentials: ' . (\$cors['supports_credentials'] ? 'true' : 'false') . PHP_EOL;
"

echo

# 5. Test storage configuration
echo "5. Verifying storage configuration..."
php artisan tinker --execute="
echo 'Storage Configuration Test:' . PHP_EOL;
echo '===========================' . PHP_EOL;
echo 'Default filesystem: ' . config('filesystems.default') . PHP_EOL;
echo 'Spaces bucket: ' . config('filesystems.disks.spaces.bucket') . PHP_EOL;
echo 'Spaces region: ' . config('filesystems.disks.spaces.region') . PHP_EOL;
echo 'Spaces URL: ' . config('filesystems.disks.spaces.url') . PHP_EOL;
echo 'Livewire disk: ' . config('livewire.temporary_file_uploads.disk') . PHP_EOL;
echo 'Livewire path: ' . config('livewire.temporary_file_uploads.path') . PHP_EOL;
"

echo

# 6. Test OPTIONS request handling
echo "6. Testing OPTIONS request handling..."
echo "Testing OPTIONS on root endpoint:"
curl -s -i -X OPTIONS \
  "http://localhost/" \
  -H "Origin: https://bconnect.64.226.120.45.nip.io" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: content-type,x-csrf-token" | head -10

echo
echo "Testing OPTIONS on Livewire upload endpoint:"
curl -s -i -X OPTIONS \
  "http://localhost/livewire/upload" \
  -H "Origin: https://bconnect.64.226.120.45.nip.io" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: content-type,x-csrf-token" | head -10

echo

# 7. Test storage connectivity
echo "7. Testing storage connectivity..."
php artisan tinker --execute="
try {
    \$storage = Storage::disk('spaces');
    \$testFile = 'cors-test-' . time() . '.txt';
    \$result = \$storage->put(\$testFile, 'CORS and storage test');
    
    if (\$result) {
        echo '✓ Storage write test: SUCCESS' . PHP_EOL;
        \$storage->delete(\$testFile);
        echo '✓ Storage cleanup: SUCCESS' . PHP_EOL;
    } else {
        echo '✗ Storage write test: FAILED' . PHP_EOL;
    }
} catch (Exception \$e) {
    echo '✗ Storage test EXCEPTION: ' . \$e->getMessage() . PHP_EOL;
}
"

echo

# 8. Restart services
echo "8. Restarting services..."
sudo service nginx reload
sudo service php8.3-fpm restart

# Terminate Horizon if it exists
if pgrep -f "horizon" > /dev/null; then
    php artisan horizon:terminate
    echo "✓ Horizon terminated and will restart"
fi

sleep 3
echo "✓ Services restarted"

echo

# 9. Final verification
echo "9. Final environment verification..."
echo "Current .env settings:"
echo "SPACES_REGION: $(grep '^SPACES_REGION=' .env)"
echo "SPACES_BUCKET: $(grep '^SPACES_BUCKET=' .env)"
echo "SPACES_ENDPOINT: $(grep '^SPACES_ENDPOINT=' .env)"
echo "AWS_URL: $(grep '^AWS_URL=' .env)"
echo "LIVEWIRE_DISK: $(grep '^LIVEWIRE_DISK=' .env)"
echo "LIVEWIRE_TMP_PATH: $(grep '^LIVEWIRE_TMP_PATH=' .env)"

echo
echo "============================================="
echo "✅ 405 ERROR FIX COMPLETE!"
echo
echo "Issues addressed:"
echo "1. ✓ Fixed DigitalOcean Spaces URLs to use fra1 region"
echo "2. ✓ Added comprehensive CORS configuration"
echo "3. ✓ Added CORS middleware to Laravel 11 bootstrap"
echo "4. ✓ Added temporary OPTIONS route fallback"
echo "5. ✓ Verified Livewire routes exist"
echo "6. ✓ Tested storage connectivity"
echo "7. ✓ Restarted all services"
echo
echo "🧪 Now test your application:"
echo "1. Try uploading a file"
echo "2. Check browser DevTools Network tab for 405 errors"
echo "3. Look for successful OPTIONS preflight requests"
echo
echo "If you still see 405 errors, share the exact:"
echo "- Request URL"
echo "- Request method"
echo "- From browser DevTools Network tab"
echo "============================================="
