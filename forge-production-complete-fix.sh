#!/bin/bash

# Navigate to the application directory
cd /home/forge/bconnect.64.226.120.45.nip.io

echo "Starting COMPLETE production fix for schedules..."

# Step 1: Pull the latest code
echo "1/8: Pulling latest code from Git..."
git pull origin main

# Step 2: NUCLEAR OPTION - Delete ALL compiled views
echo "2/8: NUCLEAR OPTION - Deleting ALL compiled views..."
rm -rf storage/framework/views/*
rm -rf storage/framework/cache/*
rm -rf bootstrap/cache/*

# Step 3: Clear ALL Laravel caches
echo "3/8: Clearing ALL Laravel caches..."
php artisan view:clear
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan optimize:clear

# Step 4: Run database migrations
echo "4/8: Running database migrations..."
php artisan migrate --force

# Step 5: Seed ports and carriers (CRITICAL - this is missing!)
echo "5/8: Seeding ports and carriers..."
php artisan db:seed --class=PortSeeder --force
php artisan db:seed --class=ShippingCarrierSeeder --force

# Step 6: Verify data exists
echo "6/8: Verifying data exists..."
PORTS_COUNT=$(php artisan tinker --execute="echo App\Models\Port::count();")
CARRIERS_COUNT=$(php artisan tinker --execute="echo App\Models\ShippingCarrier::count();")
echo "Ports in database: $PORTS_COUNT"
echo "Carriers in database: $CARRIERS_COUNT"

# Step 7: Rebuild Laravel caches
echo "7/8: Rebuilding Laravel caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Step 8: Restart Supervisor processes
echo "8/8: Restarting Supervisor processes..."
sudo supervisorctl restart all

echo "COMPLETE production fix finished!"
echo "Ports: $PORTS_COUNT, Carriers: $CARRIERS_COUNT"
echo "Please refresh your application and try the sync again."