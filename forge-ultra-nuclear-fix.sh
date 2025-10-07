#!/bin/bash

# Navigate to the application directory
cd /home/forge/bconnect.64.226.120.45.nip.io

echo "🚨 ULTRA-NUCLEAR FIX - This WILL work! 🚨"

# Step 1: Pull the latest code
echo "1/10: Pulling latest code from Git..."
git pull origin main

# Step 2: STOP ALL SERVICES FIRST
echo "2/10: STOPPING ALL SERVICES..."
sudo supervisorctl stop all
sudo systemctl stop nginx
sudo systemctl stop php8.2-fpm

# Step 3: NUCLEAR OPTION - Delete EVERYTHING
echo "3/10: NUCLEAR OPTION - Deleting ALL caches and compiled files..."
rm -rf storage/framework/views/*
rm -rf storage/framework/cache/*
rm -rf storage/framework/sessions/*
rm -rf bootstrap/cache/*
rm -rf vendor/laravel/framework/src/Illuminate/View/CompiledViews/*

# Step 4: Clear ALL Laravel caches with force
echo "4/10: Clearing ALL Laravel caches with FORCE..."
php artisan view:clear --force
php artisan cache:clear --force
php artisan config:clear --force
php artisan route:clear --force
php artisan optimize:clear --force

# Step 5: Run database migrations with force
echo "5/10: Running database migrations with FORCE..."
php artisan migrate --force --no-interaction

# Step 6: TRUNCATE and RESEED ports and carriers
echo "6/10: TRUNCATING and RESEEDING ports and carriers..."
php artisan tinker --execute="
DB::statement('SET FOREIGN_KEY_CHECKS=0;');
DB::table('ports')->truncate();
DB::table('shipping_carriers')->truncate();
DB::statement('SET FOREIGN_KEY_CHECKS=1;');
echo 'Tables truncated successfully';
"

# Step 7: Seed ports and carriers with force
echo "7/10: Seeding ports and carriers with FORCE..."
php artisan db:seed --class=PortSeeder --force --no-interaction
php artisan db:seed --class=ShippingCarrierSeeder --force --no-interaction

# Step 8: Verify data exists with detailed output
echo "8/10: Verifying data exists with DETAILED output..."
PORTS_COUNT=$(php artisan tinker --execute="echo App\Models\Port::count();")
CARRIERS_COUNT=$(php artisan tinker --execute="echo App\Models\ShippingCarrier::count();")
echo "✅ Ports in database: $PORTS_COUNT"
echo "✅ Carriers in database: $CARRIERS_COUNT"

# Show some sample data
echo "Sample ports:"
php artisan tinker --execute="App\Models\Port::take(5)->get(['code', 'name'])->each(function(\$p) { echo \$p->code . ' - ' . \$p->name . PHP_EOL; });"

# Step 9: Rebuild Laravel caches with force
echo "9/10: Rebuilding Laravel caches with FORCE..."
php artisan config:cache --force
php artisan route:cache --force
php artisan view:cache --force

# Step 10: START ALL SERVICES
echo "10/10: STARTING ALL SERVICES..."
sudo systemctl start php8.2-fpm
sudo systemctl start nginx
sudo supervisorctl start all

echo "🎉 ULTRA-NUCLEAR FIX COMPLETED! 🎉"
echo "Ports: $PORTS_COUNT, Carriers: $CARRIERS_COUNT"
echo "All services restarted. Please refresh your application NOW!"
