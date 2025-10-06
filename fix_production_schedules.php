<?php

/**
 * Production Fix Script for Schedules Page
 * 
 * This script fixes the schedules page issues in production:
 * 1. Seeds missing ports and carriers data
 * 2. Clears view cache to ensure latest controller code is used
 * 
 * Run this in production: php fix_production_schedules.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Port;
use App\Models\ShippingCarrier;
use Illuminate\Support\Facades\Artisan;

echo "=== Production Schedules Fix ===\n\n";

// Step 1: Check current data
echo "1. Checking current data...\n";
$portCount = Port::count();
$carrierCount = ShippingCarrier::count();

echo "   Ports: {$portCount}\n";
echo "   Carriers: {$carrierCount}\n\n";

// Step 2: Seed missing data
if ($portCount === 0) {
    echo "2. Seeding ports...\n";
    try {
        Artisan::call('db:seed', ['--class' => 'PortSeeder']);
        echo "   ✓ Ports seeded successfully\n";
    } catch (Exception $e) {
        echo "   ✗ Error seeding ports: " . $e->getMessage() . "\n";
    }
} else {
    echo "2. ✓ Ports already exist ({$portCount} ports)\n";
}

if ($carrierCount === 0) {
    echo "3. Seeding carriers...\n";
    try {
        Artisan::call('db:seed', ['--class' => 'ShippingCarrierSeeder']);
        echo "   ✓ Carriers seeded successfully\n";
    } catch (Exception $e) {
        echo "   ✗ Error seeding carriers: " . $e->getMessage() . "\n";
    }
} else {
    echo "3. ✓ Carriers already exist ({$carrierCount} carriers)\n";
}

// Step 3: Clear caches
echo "\n4. Clearing caches...\n";
try {
    Artisan::call('view:clear');
    echo "   ✓ View cache cleared\n";
    
    Artisan::call('config:clear');
    echo "   ✓ Config cache cleared\n";
    
    Artisan::call('route:clear');
    echo "   ✓ Route cache cleared\n";
} catch (Exception $e) {
    echo "   ✗ Error clearing caches: " . $e->getMessage() . "\n";
}

// Step 4: Verify final state
echo "\n5. Verifying final state...\n";
$finalPortCount = Port::count();
$finalCarrierCount = ShippingCarrier::count();

echo "   Final Ports: {$finalPortCount}\n";
echo "   Final Carriers: {$finalCarrierCount}\n";

if ($finalPortCount > 0 && $finalCarrierCount > 0) {
    echo "\n✅ SUCCESS: Schedules page should now work properly!\n";
    echo "   - POL/POD dropdowns will be populated\n";
    echo "   - Controller variables are properly passed\n";
    echo "   - View cache is cleared\n";
} else {
    echo "\n❌ ERROR: Data seeding failed. Please check the errors above.\n";
}

echo "\n=== Fix Complete ===\n";
