#!/bin/bash

echo "============================================="
echo "BConnect 500 Error Analysis Recipe"
echo "Timestamp: $(date)"
echo "============================================="
echo

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Navigate to the site directory
cd /home/forge/bconnect.64.226.120.45.nip.io || {
    print_error "Cannot navigate to site directory"
    exit 1
}

print_status "Analyzing 500 Server Error..."
echo

# 1. Check Laravel logs for recent errors
print_status "1. Checking recent Laravel error logs..."
if [ -f "storage/logs/laravel.log" ]; then
    echo "Last 50 lines of Laravel log:"
    echo "================================"
    tail -n 50 storage/logs/laravel.log
    echo
    
    # Check for specific error patterns
    print_status "Analyzing error patterns..."
    grep -i "exception\|error\|fatal\|livewire\|spaces\|storage" storage/logs/laravel.log | tail -n 10
    echo
else
    print_warning "Laravel log file not found"
fi

# 2. Check Nginx error logs
print_status "2. Checking Nginx error logs..."
if [ -f "/var/log/nginx/bconnect.64.226.120.45.nip.io-error.log" ]; then
    echo "Last 20 lines of Nginx error log:"
    echo "===================================="
    sudo tail -n 20 /var/log/nginx/bconnect.64.226.120.45.nip.io-error.log
    echo
else
    print_warning "Nginx error log not found"
fi

# 3. Check PHP-FPM logs
print_status "3. Checking PHP-FPM logs..."
if [ -f "/var/log/php8.3-fpm.log" ]; then
    echo "Last 20 lines of PHP-FPM log:"
    echo "==============================="
    sudo tail -n 20 /var/log/php8.3-fpm.log
    echo
else
    print_warning "PHP-FPM log not found"
fi

# 4. Test Laravel application status
print_status "4. Testing Laravel application status..."
php artisan about 2>&1 | head -n 20
echo

# 5. Check environment configuration
print_status "5. Checking critical environment variables..."
echo "SPACES Configuration:"
echo "SPACES_REGION: $(grep '^SPACES_REGION=' .env 2>/dev/null || echo 'NOT SET')"
echo "SPACES_BUCKET: $(grep '^SPACES_BUCKET=' .env 2>/dev/null || echo 'NOT SET')"
echo "SPACES_ENDPOINT: $(grep '^SPACES_ENDPOINT=' .env 2>/dev/null || echo 'NOT SET')"
echo "FILESYSTEM_DISK: $(grep '^FILESYSTEM_DISK=' .env 2>/dev/null || echo 'NOT SET')"
echo "LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK: $(grep '^LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=' .env 2>/dev/null || echo 'NOT SET')"
echo

# 6. Test storage connectivity
print_status "6. Testing storage connectivity..."
php -r "
try {
    // Test default disk
    \$disk = config('filesystems.default');
    echo 'Default filesystem disk: ' . \$disk . PHP_EOL;
    
    \$storage = Storage::disk(\$disk);
    
    // Test basic connectivity
    \$testFile = 'debug-test-' . time() . '.txt';
    \$result = \$storage->put(\$testFile, 'Debug test at ' . now());
    
    if (\$result) {
        echo '✓ Storage write test: SUCCESS' . PHP_EOL;
        \$storage->delete(\$testFile);
        echo '✓ Storage delete test: SUCCESS' . PHP_EOL;
    } else {
        echo '✗ Storage write test: FAILED' . PHP_EOL;
    }
    
} catch (Exception \$e) {
    echo '✗ Storage test EXCEPTION: ' . \$e->getMessage() . PHP_EOL;
    echo 'Stack trace:' . PHP_EOL;
    echo \$e->getTraceAsString() . PHP_EOL;
}
"
echo

# 7. Test Livewire temp storage
print_status "7. Testing Livewire temporary file storage..."
php -r "
try {
    \$tempDisk = config('livewire.temporary_file_uploads.disk', config('filesystems.default'));
    \$tempPath = config('livewire.temporary_file_uploads.path', 'livewire-tmp');
    
    echo 'Livewire temp disk: ' . \$tempDisk . PHP_EOL;
    echo 'Livewire temp path: ' . \$tempPath . PHP_EOL;
    
    \$storage = Storage::disk(\$tempDisk);
    \$testFile = \$tempPath . '/livewire-debug-' . time() . '.txt';
    
    \$result = \$storage->put(\$testFile, 'Livewire temp test');
    if (\$result) {
        echo '✓ Livewire temp storage: SUCCESS' . PHP_EOL;
        \$storage->delete(\$testFile);
    } else {
        echo '✗ Livewire temp storage: FAILED' . PHP_EOL;
    }
    
} catch (Exception \$e) {
    echo '✗ Livewire temp storage EXCEPTION: ' . \$e->getMessage() . PHP_EOL;
    echo 'Stack trace:' . PHP_EOL;
    echo \$e->getTraceAsString() . PHP_EOL;
}
"
echo

# 8. Check file permissions
print_status "8. Checking file permissions..."
echo "Storage directory permissions:"
ls -la storage/
echo
echo "Bootstrap cache permissions:"
ls -la bootstrap/cache/
echo

# 9. Test specific route that's causing 500 error
print_status "9. Testing application routes..."
echo "Testing home route:"
curl -s -I http://localhost/ | head -n 5
echo
echo "Testing admin route:"
curl -s -I http://localhost/admin | head -n 5
echo

# 10. Check for missing dependencies or configuration
print_status "10. Checking Laravel configuration cache..."
if [ -f "bootstrap/cache/config.php" ]; then
    print_success "Configuration cache exists"
else
    print_warning "Configuration cache missing - generating..."
    php artisan config:cache
fi

if [ -f "bootstrap/cache/routes.php" ]; then
    print_success "Route cache exists"
else
    print_warning "Route cache missing"
fi
echo

# 11. Check database connectivity
print_status "11. Testing database connectivity..."
php artisan db:show --counts 2>&1 | head -n 10
echo

# 12. Check for recent changes that might cause issues
print_status "12. Checking recent deployment information..."
echo "Last git commit:"
git log --oneline -n 3
echo
echo "Files changed in last commit:"
git diff --name-only HEAD~1 HEAD
echo

# 13. Test a simple PHP script to isolate the issue
print_status "13. Creating isolated PHP test..."
cat > debug_test.php << 'EOF'
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "PHP Version: " . PHP_VERSION . "\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";

// Test basic Laravel bootstrap
try {
    require_once __DIR__ . '/vendor/autoload.php';
    echo "✓ Autoloader loaded successfully\n";
    
    $app = require_once __DIR__ . '/bootstrap/app.php';
    echo "✓ Laravel app created successfully\n";
    
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    echo "✓ HTTP Kernel created successfully\n";
    
    // Test basic config loading
    $config = $app['config'];
    echo "✓ Configuration loaded successfully\n";
    echo "App environment: " . $config->get('app.env') . "\n";
    echo "App debug: " . ($config->get('app.debug') ? 'true' : 'false') . "\n";
    
} catch (Throwable $e) {
    echo "✗ Laravel bootstrap FAILED: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
EOF

php debug_test.php
rm debug_test.php
echo

# 14. Final recommendations
print_status "14. Analysis complete. Generating recommendations..."
echo
echo "============================================="
echo "TROUBLESHOOTING RECOMMENDATIONS"
echo "============================================="
echo

# Check if we found common issues
if grep -q "Class.*not found\|Fatal error" storage/logs/laravel.log 2>/dev/null; then
    print_error "AUTOLOAD ISSUE DETECTED"
    echo "  → Run: composer dump-autoload"
    echo "  → Run: php artisan clear-compiled"
fi

if grep -q "storage.*permission\|failed to open stream" storage/logs/laravel.log 2>/dev/null; then
    print_error "PERMISSION ISSUE DETECTED"
    echo "  → Run: sudo chown -R forge:forge storage/ bootstrap/cache/"
    echo "  → Run: sudo chmod -R 775 storage/ bootstrap/cache/"
fi

if grep -q "spaces\|s3\|storage" storage/logs/laravel.log 2>/dev/null; then
    print_error "STORAGE ISSUE DETECTED"
    echo "  → Check DigitalOcean Spaces credentials"
    echo "  → Verify SPACES_REGION=fra1 in .env"
    echo "  → Verify SPACES_BUCKET=bconnect-documents in .env"
fi

echo
print_status "Check the output above for specific error messages and stack traces."
print_status "Focus on the Laravel log entries and PHP exceptions for root cause."
echo
