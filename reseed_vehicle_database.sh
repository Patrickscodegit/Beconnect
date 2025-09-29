#!/bin/bash

# Quick script to reseed vehicle database after git operations
echo "🌱 Reseeding vehicle database..."

php artisan db:seed --class=VinWmiSeeder
php artisan db:seed --class=VehicleSpecsSeeder
php artisan db:seed --class=PopularLuxuryVehiclesSeeder

echo "✅ Vehicle database reseeded"
echo "BMW 7 Series dimensions: 5.39 x 1.95 x 1.54 m // 16.19 Cbm"
echo "Popular luxury vehicles added: BMW 3/5 Series, Mercedes C/E-Class, Audi A4/A6, Porsche 911/Cayenne, Lexus ES/IS/RX"
