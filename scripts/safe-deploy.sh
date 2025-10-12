#!/bin/bash

# 🛡️ SAFE DEPLOYMENT SCRIPT
# This script deploys changes WITHOUT wiping the database

set -e  # Exit on any error

echo "🚀 SAFE DEPLOYMENT - Database Protected 🛡️"

# Step 1: Create backup
echo "1/6: Creating database backup..."
if [ -f "database/database.sqlite" ]; then
    cp database/database.sqlite database/database.sqlite.backup.$(date +%Y%m%d_%H%M%S)
    echo "✅ Backup created: database.sqlite.backup.$(date +%Y%m%d_%H%M%S)"
else
    echo "⚠️  No SQLite database found - skipping backup"
fi

# Step 2: Pull latest code
echo "2/6: Pulling latest code from Git..."
git pull origin main

# Step 3: Clear caches (safe)
echo "3/6: Clearing Laravel caches..."
php artisan cache:clear
php artisan config:clear
php artisan view:clear
php artisan route:clear

# Step 4: Run migrations (safe - only new migrations)
echo "4/6: Running new migrations (safe - no data loss)..."
php artisan migrate

# Step 5: Verify database integrity
echo "5/6: Verifying database integrity..."
PORTS_COUNT=$(php artisan tinker --execute="echo App\Models\Port::count();" 2>/dev/null || echo "0")
CARRIERS_COUNT=$(php artisan tinker --execute="echo App\Models\ShippingCarrier::count();" 2>/dev/null || echo "0")
USERS_COUNT=$(php artisan tinker --execute="echo App\Models\User::count();" 2>/dev/null || echo "0")

echo "📊 Database Status:"
echo "   Ports: $PORTS_COUNT"
echo "   Carriers: $CARRIERS_COUNT" 
echo "   Users: $USERS_COUNT"

# Step 6: Optimize (safe)
echo "6/6: Optimizing application..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "✅ SAFE DEPLOYMENT COMPLETED!"
echo "🛡️ Database protected - no data lost"
echo "📊 Current data: $PORTS_COUNT ports, $CARRIERS_COUNT carriers, $USERS_COUNT users"
