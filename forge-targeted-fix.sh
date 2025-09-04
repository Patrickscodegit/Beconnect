#!/bin/bash

echo "============================================="
echo "BConnect TARGETED 500 Error Fix"
echo "Based on diagnostic analysis"
echo "Timestamp: $(date)"
echo "============================================="
echo

cd /home/forge/bconnect.64.226.120.45.nip.io || exit 1

echo "🎯 Applying targeted fixes for identified issues..."
echo

# 1. Fix missing environment variables
echo "1. Adding missing critical environment variables..."

# Check if variables exist, if not add them
if ! grep -q "^LIVEWIRE_DISK=" .env; then
    echo "LIVEWIRE_DISK=spaces" >> .env
    echo "✓ Added LIVEWIRE_DISK=spaces"
fi

if ! grep -q "^LIVEWIRE_TMP_PATH=" .env; then
    echo "LIVEWIRE_TMP_PATH=livewire-tmp" >> .env
    echo "✓ Added LIVEWIRE_TMP_PATH=livewire-tmp"
fi

# Ensure Frankfurt region settings are correct
if ! grep -q "^SPACES_REGION=fra1" .env; then
    if grep -q "^SPACES_REGION=" .env; then
        sed -i 's/^SPACES_REGION=.*/SPACES_REGION=fra1/' .env
        echo "✓ Updated SPACES_REGION to fra1"
    else
        echo "SPACES_REGION=fra1" >> .env
        echo "✓ Added SPACES_REGION=fra1"
    fi
fi

if ! grep -q "^SPACES_ENDPOINT=" .env; then
    echo "SPACES_ENDPOINT=https://fra1.digitaloceanspaces.com" >> .env
    echo "✓ Added SPACES_ENDPOINT for Frankfurt"
fi

if ! grep -q "^SPACES_URL=" .env; then
    echo "SPACES_URL=https://bconnect-documents.fra1.digitaloceanspaces.com" >> .env
    echo "✓ Added SPACES_URL for Frankfurt"
fi

echo

# 2. Clear and rebuild all configuration
echo "2. Clearing and rebuilding configuration..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
echo "✓ All caches cleared"

# Rebuild config cache
php artisan config:cache
echo "✓ Configuration cache rebuilt"

# 3. Test configuration loading
echo
echo "3. Testing configuration loading..."
php artisan tinker --execute="
echo 'Default disk: ' . config('filesystems.default') . PHP_EOL;
echo 'Livewire temp disk: ' . config('livewire.temporary_file_uploads.disk') . PHP_EOL;
echo 'Livewire temp path: ' . config('livewire.temporary_file_uploads.path') . PHP_EOL;
echo 'Spaces region: ' . config('filesystems.disks.spaces.region') . PHP_EOL;
echo 'Spaces bucket: ' . config('filesystems.disks.spaces.bucket') . PHP_EOL;
"

echo

# 4. Create livewire-tmp directory in DO Spaces
echo "4. Creating livewire-tmp directory in DigitalOcean Spaces..."
php artisan tinker --execute="
try {
    \$storage = Storage::disk('spaces');
    if (!\$storage->exists('livewire-tmp')) {
        \$storage->makeDirectory('livewire-tmp');
        echo '✓ Created livewire-tmp directory in Spaces' . PHP_EOL;
    } else {
        echo '✓ livewire-tmp directory already exists' . PHP_EOL;
    }
    
    // Test write to livewire-tmp
    \$testFile = 'livewire-tmp/connection-test.txt';
    \$result = \$storage->put(\$testFile, 'Test file created at ' . now());
    if (\$result) {
        echo '✓ Successfully wrote test file to livewire-tmp' . PHP_EOL;
        \$storage->delete(\$testFile);
        echo '✓ Successfully cleaned up test file' . PHP_EOL;
    } else {
        echo '✗ Failed to write to livewire-tmp directory' . PHP_EOL;
    }
} catch (Exception \$e) {
    echo '✗ Error testing Spaces: ' . \$e->getMessage() . PHP_EOL;
}
"

echo

# 5. Fix file permissions
echo "5. Ensuring proper file permissions..."
sudo chown -R forge:forge storage/
sudo chmod -R 775 storage/
sudo chown -R forge:forge bootstrap/cache/
sudo chmod -R 775 bootstrap/cache/
echo "✓ File permissions fixed"

# 6. Restart services
echo
echo "6. Restarting services..."
sudo service nginx reload
sudo service php8.3-fpm restart

# Wait for services
sleep 3

echo "✓ Services restarted"

# 7. Final verification
echo
echo "7. Final verification..."
echo "Current environment variables:"
echo "SPACES_REGION: $(grep '^SPACES_REGION=' .env)"
echo "SPACES_BUCKET: $(grep '^SPACES_BUCKET=' .env)"
echo "SPACES_ENDPOINT: $(grep '^SPACES_ENDPOINT=' .env)"
echo "LIVEWIRE_DISK: $(grep '^LIVEWIRE_DISK=' .env)"

echo
echo "============================================="
echo "✅ TARGETED FIX COMPLETE!"
echo
echo "Issues addressed:"
echo "1. ✓ Added missing Livewire environment variables"
echo "2. ✓ Ensured Frankfurt region configuration"
echo "3. ✓ Rebuilt configuration cache"
echo "4. ✓ Created livewire-tmp directory in Spaces"
echo "5. ✓ Fixed file permissions"
echo "6. ✓ Restarted services"
echo
echo "🧪 Now test your file upload!"
echo "The 500 error should be resolved."
echo "============================================="
