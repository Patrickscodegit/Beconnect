#!/bin/bash

# Navigate to the application directory
cd /home/forge/bconnect.64.226.120.45.nip.io

echo "🚨 REAL DATA ONLY FIX - No mock data! 🚨"

# Step 1: Pull the latest code
echo "1/8: Pulling latest code from Git..."
git pull origin main

# Step 2: STOP ALL SERVICES FIRST
echo "2/8: STOPPING ALL SERVICES..."
sudo supervisorctl stop all
sudo systemctl stop nginx
sudo systemctl stop php8.2-fpm

# Step 3: NUCLEAR OPTION - Delete ALL caches and compiled files
echo "3/8: NUCLEAR OPTION - Deleting ALL caches and compiled files..."
rm -rf storage/framework/views/*
rm -rf storage/framework/cache/*
rm -rf storage/framework/sessions/*
rm -rf bootstrap/cache/*

# Step 4: Clear ALL Laravel caches (WITHOUT --force, as it's not supported)
echo "4/8: Clearing ALL Laravel caches (without --force)..."
php artisan view:clear
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan optimize:clear

# Step 5: Run database migrations and seed fresh data (TRUNCATES TABLES)
echo "5/8: Running database migrations and seeding FRESH data (TRUNCATES TABLES)..."
php artisan migrate:fresh --seed --force

# Step 6: Verify initial data exists after fresh seed
echo "6/8: Verifying initial data exists after fresh seed..."
PORTS_COUNT=$(php artisan tinker --execute="echo App\Models\Port::count();")
CARRIERS_COUNT=$(php artisan tinker --execute="echo App\Models\ShippingCarrier::count();")
echo "Ports in database: $PORTS_COUNT"
echo "Carriers in database: $CARRIERS_COUNT"

if [ "$PORTS_COUNT" -eq 0 ] || [ "$CARRIERS_COUNT" -eq 0 ]; then
    echo "🚨 CRITICAL ERROR: Ports or Carriers are missing after fresh seed. Aborting."
    exit 1
fi

# Step 7: Rebuild Laravel caches
echo "7/8: Rebuilding Laravel caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize

# Step 8: START ALL SERVICES
echo "8/8: STARTING ALL SERVICES..."
sudo supervisorctl start all
sudo systemctl start nginx
sudo systemctl start php8.2-fpm

echo "✅ REAL DATA ONLY FIX COMPLETED!"
echo "Ports: $PORTS_COUNT, Carriers: $CARRIERS_COUNT"
echo "Now you can manually trigger real data extraction from the UI."
echo "The system will extract REAL schedules from carrier websites, not mock data."
