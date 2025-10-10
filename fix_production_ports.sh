#!/bin/bash

# Fix Production Port Dropdowns
# This script ensures the production database has the correct port data

echo "🚀 Fixing production port dropdowns..."

# Navigate to the application directory
cd /home/forge/bconnect.64.226.120.45.nip.io || {
    echo "❌ Failed to navigate to application directory"
    exit 1
}

echo "📊 Current port count in production:"
php artisan tinker --execute="echo 'Total ports: ' . App\Models\Port::count();"

echo ""
echo "🌱 Seeding port data..."
php artisan db:seed --class=PortSeeder --force

echo ""
echo "✅ Port data seeded successfully!"

echo ""
echo "📊 Updated port count:"
php artisan tinker --execute="echo 'Total ports: ' . App\Models\Port::count();"

echo ""
echo "🧹 Clearing caches..."
php artisan cache:clear
php artisan view:clear
php artisan config:clear

echo ""
echo "🎉 Production port dropdowns should now display correctly!"
echo "   Format: 'City, Country (Code)'"
echo ""
echo "🔍 Test at: http://bconnect.64.226.120.45.nip.io/schedules"
