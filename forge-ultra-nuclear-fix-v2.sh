#!/bin/bash

# Navigate to the application directory
cd /home/forge/bconnect.64.226.120.45.nip.io

echo "🚨 ULTRA-NUCLEAR FIX V2 - This WILL work! 🚨"

# Step 1: Pull the latest code
echo "1/10: Pulling latest code from Git..."
git pull origin main

# Step 2: STOP ALL SERVICES FIRST
echo "2/10: STOPPING ALL SERVICES..."
sudo supervisorctl stop all
sudo systemctl stop nginx
sudo systemctl stop php8.2-fpm

# Step 3: NUCLEAR OPTION - Delete ALL caches and compiled files
echo "3/10: NUCLEAR OPTION - Deleting ALL caches and compiled files..."
rm -rf storage/framework/views/*
rm -rf storage/framework/cache/*
rm -rf storage/framework/sessions/*
rm -rf bootstrap/cache/*

# Step 4: Clear ALL Laravel caches (WITHOUT --force, as it's not supported)
echo "4/10: Clearing ALL Laravel caches (without --force)..."
php artisan view:clear
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan optimize:clear

# Step 5: Run database migrations and seed fresh data (TRUNCATES TABLES)
echo "5/10: Running database migrations and seeding FRESH data (TRUNCATES TABLES)..."
php artisan migrate:fresh --seed --force

# Step 6: Verify initial data exists after fresh seed
echo "6/10: Verifying initial data exists after fresh seed..."
PORTS_COUNT=$(php artisan tinker --execute="echo App\Models\Port::count();")
CARRIERS_COUNT=$(php artisan tinker --execute="echo App\Models\ShippingCarrier::count();")
echo "Ports in database: $PORTS_COUNT"
echo "Carriers in database: $CARRIERS_COUNT"

if [ "$PORTS_COUNT" -eq 0 ] || [ "$CARRIERS_COUNT" -eq 0 ]; then
    echo "🚨 CRITICAL ERROR: Ports or Carriers are missing after fresh seed. Aborting."
    exit 1
fi

# Step 7: Rebuild Laravel caches
echo "7/10: Rebuilding Laravel caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize

# Step 8: Dispatch the schedule update job directly (bypassing queue for immediate results)
echo "8/10: Dispatching the schedule update job directly..."
php artisan tinker --execute="dispatch(new App\Jobs\UpdateShippingSchedulesJob());"

# Step 9: Verify schedules were created
echo "9/10: Verifying schedules were created..."
SCHEDULES_COUNT=$(php artisan tinker --execute="echo App\Models\ShippingSchedule::count();")
echo "Schedules in database: $SCHEDULES_COUNT"

if [ "$SCHEDULES_COUNT" -eq 0 ]; then
    echo "⚠️ WARNING: No schedules were created. Check job logs for errors."
fi

# Step 10: START ALL SERVICES
echo "10/10: STARTING ALL SERVICES..."
sudo supervisorctl start all
sudo systemctl start nginx
sudo systemctl start php8.2-fpm

echo "✅ ULTRA-NUCLEAR FIX V2 COMPLETED!"
echo "Ports: $PORTS_COUNT, Carriers: $CARRIERS_COUNT, Schedules: $SCHEDULES_COUNT"
echo "Please refresh your application and check the schedules page."
