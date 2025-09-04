#!/bin/bash

echo "==== BConnect Production Debug & Fix Recipe ===="
echo "Timestamp: $(date)"
echo

# 1. Check current .env configuration
echo "1. Checking current .env configuration..."
echo "SPACES_REGION: $(grep SPACES_REGION /home/forge/your-site/.env | head -1)"
echo "SPACES_BUCKET: $(grep SPACES_BUCKET /home/forge/your-site/.env | head -1)"
echo "SPACES_ENDPOINT: $(grep SPACES_ENDPOINT /home/forge/your-site/.env | head -1)"
echo "FILESYSTEM_DISK: $(grep FILESYSTEM_DISK /home/forge/your-site/.env | head -1)"
echo

# 2. Test DigitalOcean Spaces connectivity
echo "2. Testing DigitalOcean Spaces connectivity..."
SPACES_KEY=$(grep SPACES_KEY /home/forge/your-site/.env | cut -d'=' -f2)
SPACES_SECRET=$(grep SPACES_SECRET /home/forge/your-site/.env | cut -d'=' -f2)
SPACES_BUCKET=$(grep SPACES_BUCKET /home/forge/your-site/.env | cut -d'=' -f2)

# Test connection to Frankfurt endpoint
curl -s -I "https://fra1.digitaloceanspaces.com" && echo "✓ Frankfurt endpoint accessible" || echo "✗ Frankfurt endpoint failed"

# Test bucket access
curl -s -I "https://${SPACES_BUCKET}.fra1.digitaloceanspaces.com" && echo "✓ Bucket accessible" || echo "✗ Bucket access failed"
echo

# 3. Check and create required directories
echo "3. Checking Laravel storage permissions and directories..."
cd /home/forge/your-site

# Storage directories
sudo chown -R forge:forge storage/
sudo chmod -R 775 storage/
sudo chmod -R 775 storage/app/
sudo chmod -R 775 storage/logs/
sudo chmod -R 775 storage/framework/

# Create Livewire temp directories if needed
mkdir -p storage/app/livewire-tmp
sudo chmod 775 storage/app/livewire-tmp
sudo chown forge:forge storage/app/livewire-tmp
echo "✓ Storage directories configured"
echo

# 4. Clear all caches
echo "4. Clearing all caches..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan filament:clear-cached-components
echo "✓ Caches cleared"
echo

# 5. Test Laravel configuration
echo "5. Testing Laravel configuration..."
php artisan config:show filesystem | grep -A 10 'documents'
echo

# 6. Check recent error logs
echo "6. Recent error logs (last 20 lines)..."
tail -n 20 storage/logs/laravel.log
echo

# 7. Test file upload permissions
echo "7. Testing file upload capabilities..."
php -r "
\$config = include 'config/filesystems.php';
\$disk = config('filesystems.default');
echo 'Default disk: ' . \$disk . PHP_EOL;

try {
    \$storage = \\Storage::disk(\$disk);
    \$testContent = 'Test file created at ' . now();
    \$result = \$storage->put('test-connection.txt', \$testContent);
    if (\$result) {
        echo '✓ File upload test successful' . PHP_EOL;
        \$storage->delete('test-connection.txt');
        echo '✓ File deletion test successful' . PHP_EOL;
    } else {
        echo '✗ File upload test failed' . PHP_EOL;
    }
} catch (Exception \$e) {
    echo '✗ Storage test failed: ' . \$e->getMessage() . PHP_EOL;
}
"

# 8. Test Livewire temp file configuration
echo
echo "8. Testing Livewire temporary file configuration..."
php -r "
try {
    \$disk = config('livewire.temporary_file_uploads.disk');
    \$path = config('livewire.temporary_file_uploads.path');
    echo 'Livewire temp disk: ' . (\$disk ?: 'default') . PHP_EOL;
    echo 'Livewire temp path: ' . (\$path ?: 'livewire-tmp') . PHP_EOL;
    
    \$storage = \\Storage::disk(\$disk ?: config('filesystems.default'));
    \$testFile = (\$path ?: 'livewire-tmp') . '/connection-test-' . time() . '.txt';
    \$result = \$storage->put(\$testFile, 'Livewire temp test');
    if (\$result) {
        echo '✓ Livewire temp storage test successful' . PHP_EOL;
        \$storage->delete(\$testFile);
    } else {
        echo '✗ Livewire temp storage test failed' . PHP_EOL;
    }
} catch (Exception \$e) {
    echo '✗ Livewire temp test failed: ' . \$e->getMessage() . PHP_EOL;
}
"

# 9. Restart services
echo
echo "9. Restarting services..."
sudo service nginx reload
sudo supervisorctl restart all
echo "✓ Services restarted"

echo
echo "==== Debug Recipe Complete ===="
echo "If issues persist, check the Laravel logs above for specific error messages."
echo
