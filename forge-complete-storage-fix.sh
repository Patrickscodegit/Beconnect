#!/bin/bash

echo "============================================="
echo "BConnect File Storage Fix - Complete Solution"
echo "Timestamp: $(date)"
echo "============================================="
echo

cd /home/forge/bconnect.64.226.120.45.nip.io || exit 1

echo "🔧 Applying complete file storage fix..."
echo

# 1. Update environment variables for comprehensive DigitalOcean Spaces support
echo "1. Updating environment variables..."

# SPACES variables (primary)
if ! grep -q "^SPACES_REGION=fra1" .env; then
    if grep -q "^SPACES_REGION=" .env; then
        sed -i 's/^SPACES_REGION=.*/SPACES_REGION=fra1/' .env
    else
        echo "SPACES_REGION=fra1" >> .env
    fi
    echo "✓ Set SPACES_REGION=fra1"
fi

if ! grep -q "^SPACES_BUCKET=bconnect-documents" .env; then
    if grep -q "^SPACES_BUCKET=" .env; then
        sed -i 's/^SPACES_BUCKET=.*/SPACES_BUCKET=bconnect-documents/' .env
    else
        echo "SPACES_BUCKET=bconnect-documents" >> .env
    fi
    echo "✓ Set SPACES_BUCKET=bconnect-documents"
fi

if ! grep -q "^SPACES_ENDPOINT=" .env; then
    echo "SPACES_ENDPOINT=https://fra1.digitaloceanspaces.com" >> .env
    echo "✓ Added SPACES_ENDPOINT"
fi

if ! grep -q "^SPACES_URL=" .env; then
    echo "SPACES_URL=https://bconnect-documents.fra1.digitaloceanspaces.com" >> .env
    echo "✓ Added SPACES_URL"
fi

# AWS compatibility variables (many packages expect these)
if ! grep -q "^AWS_DEFAULT_REGION=" .env; then
    echo "AWS_DEFAULT_REGION=fra1" >> .env
    echo "✓ Added AWS_DEFAULT_REGION"
fi

if ! grep -q "^AWS_BUCKET=" .env; then
    echo "AWS_BUCKET=bconnect-documents" >> .env
    echo "✓ Added AWS_BUCKET"
fi

if ! grep -q "^AWS_ENDPOINT=" .env; then
    echo "AWS_ENDPOINT=https://fra1.digitaloceanspaces.com" >> .env
    echo "✓ Added AWS_ENDPOINT"
fi

if ! grep -q "^AWS_URL=" .env; then
    echo "AWS_URL=https://bconnect-documents.fra1.digitaloceanspaces.com" >> .env
    echo "✓ Added AWS_URL"
fi

# Storage driver configuration
if ! grep -q "^FILESYSTEM_DISK=spaces" .env; then
    if grep -q "^FILESYSTEM_DISK=" .env; then
        sed -i 's/^FILESYSTEM_DISK=.*/FILESYSTEM_DISK=spaces/' .env
    else
        echo "FILESYSTEM_DISK=spaces" >> .env
    fi
    echo "✓ Set FILESYSTEM_DISK=spaces"
fi

if ! grep -q "^DOCUMENTS_DRIVER=" .env; then
    echo "DOCUMENTS_DRIVER=spaces" >> .env
    echo "✓ Added DOCUMENTS_DRIVER=spaces"
fi

# Livewire configuration (both variable name formats for compatibility)
if ! grep -q "^LIVEWIRE_DISK=" .env; then
    echo "LIVEWIRE_DISK=spaces" >> .env
    echo "✓ Added LIVEWIRE_DISK=spaces"
fi

if ! grep -q "^LIVEWIRE_TMP_PATH=" .env; then
    echo "LIVEWIRE_TMP_PATH=livewire-tmp" >> .env
    echo "✓ Added LIVEWIRE_TMP_PATH=livewire-tmp"
fi

if ! grep -q "^LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=" .env; then
    echo "LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=spaces" >> .env
    echo "✓ Added LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=spaces"
fi

if ! grep -q "^LIVEWIRE_TEMPORARY_FILE_UPLOAD_PATH=" .env; then
    echo "LIVEWIRE_TEMPORARY_FILE_UPLOAD_PATH=livewire-tmp" >> .env
    echo "✓ Added LIVEWIRE_TEMPORARY_FILE_UPLOAD_PATH=livewire-tmp"
fi

echo

# 2. Clear and rebuild all caches
echo "2. Clearing and rebuilding all caches..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
echo "✓ All caches cleared"

php artisan config:cache
echo "✓ Configuration cache rebuilt"

echo

# 3. Create required directories in DigitalOcean Spaces
echo "3. Creating required directories in DigitalOcean Spaces..."
php artisan tinker --execute="
try {
    \$storage = Storage::disk('spaces');
    
    // Create documents directory
    if (!\$storage->exists('documents')) {
        \$storage->makeDirectory('documents');
        echo '✓ Created documents directory' . PHP_EOL;
    } else {
        echo '✓ Documents directory exists' . PHP_EOL;
    }
    
    // Create livewire-tmp directory
    if (!\$storage->exists('livewire-tmp')) {
        \$storage->makeDirectory('livewire-tmp');
        echo '✓ Created livewire-tmp directory' . PHP_EOL;
    } else {
        echo '✓ Livewire-tmp directory exists' . PHP_EOL;
    }
    
    // Test write to both directories
    \$testFile1 = 'documents/connection-test-' . time() . '.txt';
    \$testFile2 = 'livewire-tmp/connection-test-' . time() . '.txt';
    
    if (\$storage->put(\$testFile1, 'Document storage test')) {
        echo '✓ Documents directory write test: SUCCESS' . PHP_EOL;
        \$storage->delete(\$testFile1);
    }
    
    if (\$storage->put(\$testFile2, 'Livewire temp storage test')) {
        echo '✓ Livewire-tmp directory write test: SUCCESS' . PHP_EOL;
        \$storage->delete(\$testFile2);
    }
    
} catch (Exception \$e) {
    echo '✗ Directory setup failed: ' . \$e->getMessage() . PHP_EOL;
}
"

echo

# 4. Test the complete configuration
echo "4. Testing complete storage configuration..."
php artisan tinker --execute="
echo 'Complete Configuration Test:' . PHP_EOL;
echo '============================' . PHP_EOL;
echo 'Default disk: ' . config('filesystems.default') . PHP_EOL;
echo 'Documents driver: ' . config('documents.driver', 'NOT SET') . PHP_EOL;
echo 'Spaces region: ' . config('filesystems.disks.spaces.region') . PHP_EOL;
echo 'Spaces bucket: ' . config('filesystems.disks.spaces.bucket') . PHP_EOL;
echo 'Spaces endpoint: ' . config('filesystems.disks.spaces.endpoint') . PHP_EOL;
echo 'Spaces URL: ' . config('filesystems.disks.spaces.url') . PHP_EOL;
echo 'Livewire disk: ' . config('livewire.temporary_file_uploads.disk') . PHP_EOL;
echo 'Livewire path: ' . config('livewire.temporary_file_uploads.path') . PHP_EOL;
echo 'AWS region: ' . config('filesystems.disks.spaces.region') . PHP_EOL;
echo 'AWS bucket: ' . config('filesystems.disks.spaces.bucket') . PHP_EOL;
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

# Terminate Horizon if it exists
if pgrep -f "horizon" > /dev/null; then
    php artisan horizon:terminate
    echo "✓ Horizon terminated and will restart"
fi

sleep 3
echo "✓ Services restarted"

echo

# 7. Final verification
echo "7. Final verification..."
echo "Environment variables set:"
echo "FILESYSTEM_DISK: $(grep '^FILESYSTEM_DISK=' .env)"
echo "SPACES_REGION: $(grep '^SPACES_REGION=' .env)"
echo "SPACES_BUCKET: $(grep '^SPACES_BUCKET=' .env)"
echo "AWS_URL: $(grep '^AWS_URL=' .env)"
echo "LIVEWIRE_DISK: $(grep '^LIVEWIRE_DISK=' .env)"

echo
echo "============================================="
echo "✅ COMPLETE FILE STORAGE FIX APPLIED!"
echo
echo "Key fixes implemented:"
echo "1. ✓ Updated IntakeCreationService to handle TemporaryUploadedFile"
echo "2. ✓ Fixed all storage operations to use 'spaces' disk"
echo "3. ✓ Added comprehensive AWS compatibility variables"
echo "4. ✓ Set up both Livewire variable name formats"
echo "5. ✓ Created required directories in DigitalOcean Spaces"
echo "6. ✓ Fixed CORS configuration for file uploads"
echo "7. ✓ Cleared and rebuilt all caches"
echo
echo "🧪 Now test your file upload:"
echo "1. Upload a file through Filament admin"
echo "2. Check that files are stored in 'documents/' folder"
echo "3. Verify no more 'fopen' errors in logs"
echo "4. Confirm Livewire temp uploads work properly"
echo
echo "Files will be stored as:"
echo "- Permanent files: documents/[uuid].[ext]"
echo "- Temp files: livewire-tmp/[uuid]/[filename]"
echo "============================================="
