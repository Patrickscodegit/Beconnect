#!/bin/bash

echo "============================================="
echo "BConnect 405 Error Diagnostic"
echo "Timestamp: $(date)"
echo "============================================="
echo

cd /home/forge/bconnect.64.226.120.45.nip.io || exit 1

echo "🔍 Diagnosing 405 Method Not Allowed errors..."
echo

# 1. Check all available routes
echo "1. Available routes (focusing on Livewire and upload routes)..."
echo "Livewire routes:"
php artisan route:list | grep -i livewire
echo
echo "Upload/file routes:"
php artisan route:list | grep -i -E "upload|file"
echo
echo "API routes:"
php artisan route:list | grep -i api | head -5
echo

# 2. Test common endpoints that might cause 405
echo "2. Testing common endpoints for 405 errors..."

# Test GET on Livewire upload (should be POST only)
echo "Testing GET on /livewire/upload (should return 405):"
curl -s -i -X GET "http://localhost/livewire/upload" | head -5
echo

# Test OPTIONS on Livewire upload
echo "Testing OPTIONS on /livewire/upload:"
curl -s -i -X OPTIONS "http://localhost/livewire/upload" | head -5
echo

# Test OPTIONS on root
echo "Testing OPTIONS on root (/):"
curl -s -i -X OPTIONS "http://localhost/" | head -5
echo

# Test a few other common endpoints
echo "Testing GET on /admin (should work):"
curl -s -i -X GET "http://localhost/admin" | head -5
echo

# 3. Check middleware configuration
echo "3. Checking middleware configuration..."
php artisan tinker --execute="
echo 'Global middleware:' . PHP_EOL;
\$app = app();
\$middleware = \$app->make('Illuminate\Contracts\Http\Kernel');
// Can't easily inspect middleware in tinker, but we can test CORS
echo 'CORS config exists: ' . (config('cors') ? 'YES' : 'NO') . PHP_EOL;
"

# 4. Test specific CORS headers
echo
echo "4. Testing CORS headers on different endpoints..."

echo "CORS test - OPTIONS with origin header:"
curl -s -i -X OPTIONS \
  "http://localhost/" \
  -H "Origin: https://bconnect.64.226.120.45.nip.io" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: content-type,x-csrf-token" | grep -i -E "status|access-control|allow"

echo
echo "CORS test - POST with origin header:"
curl -s -i -X POST \
  "http://localhost/livewire/update" \
  -H "Origin: https://bconnect.64.226.120.45.nip.io" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-TOKEN: test" | head -5

echo

# 5. Check for route conflicts
echo "5. Checking for route conflicts..."
echo "Routes that might conflict with Livewire:"
php artisan route:list | grep -E "/livewire|/{any}"

echo
echo "============================================="
echo "📋 DIAGNOSTIC SUMMARY"
echo "============================================="
echo
echo "Look for these patterns in the output above:"
echo "1. 405 on GET /livewire/upload = NORMAL (Livewire uses POST)"
echo "2. 405 on OPTIONS anywhere = CORS ISSUE"
echo "3. Missing Livewire routes = LIVEWIRE NOT LOADED"
echo "4. No Access-Control headers = CORS MIDDLEWARE NOT WORKING"
echo
echo "Next steps:"
echo "- If you see 405 on OPTIONS: CORS middleware issue"
echo "- If you see missing Livewire routes: Livewire not installed/loaded"
echo "- If you see conflicting routes: Route conflict"
echo
echo "Share the specific 405 error from browser DevTools:"
echo "- Exact URL"
echo "- HTTP method (GET/POST/OPTIONS/PUT)"
echo "- Any error details"
echo "============================================="
