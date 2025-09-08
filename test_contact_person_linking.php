<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Intake;
use App\Services\Robaws\RobawsExportService;

echo "=== Contact Person Linking Test ===\n\n";

// Create test intake if none exists
$intake = Intake::first();
if (!$intake) {
    echo "Creating test intake...\n";
    $intake = Intake::create([
        'customer_name' => 'Smitma BV',
        'contact_email' => 'info@smitma.nl',
        'contact_phone' => '+31 20 1234567',
        'vehicle_make' => 'BMW',
        'vehicle_model' => 'Serie 7',
        'origin_port' => 'BRUSSEL',
        'destination_port' => 'JEDDAH',
        'shipping_method' => 'RoRo',
        'status' => 'pending',
        'export_attempt_count' => 0, // Initialize the counter
        'extraction_data' => json_encode([
            'contact' => [
                'name' => 'John Smith',
                'email' => 'info@smitma.nl',
                'phone' => '+31 20 1234567',
                'company' => 'Smitma BV'
            ],
            'vehicle' => [
                'brand' => 'BMW',
                'model' => 'Serie 7',
                'year' => 2024
            ],
            'shipment' => [
                'origin' => 'Brussels, Belgium',
                'destination' => 'Jeddah, Saudi Arabia',
                'method' => 'RoRo'
            ]
        ])
    ]);
    echo "✓ Created test intake {$intake->id}\n\n";
} else {
    // Update existing intake to ensure proper fields
    echo "Using existing intake {$intake->id}...\n";
    $intake->update([
        'export_attempt_count' => 0,
        'customer_email' => 'info@smitma.nl' // Fix the empty email issue
    ]);
    echo "✓ Updated intake fields\n\n";
}

echo "Test intake details:\n";
echo "  ID: {$intake->id}\n";
echo "  Customer: {$intake->customer_name}\n";
echo "  Email: {$intake->contact_email}\n";
echo "  Phone: {$intake->contact_phone}\n";

$extractionData = $intake->extraction_data ?? [];
if (!empty($extractionData['contact'])) {
    echo "  Contact person: {$extractionData['contact']['name']}\n";
    echo "  Contact email: {$extractionData['contact']['email']}\n";
}

echo "\nTesting enhanced contact person linking...\n";

try {
    $exportService = app(RobawsExportService::class);
    
    // Test the export with our enhanced contact person linking
    $result = $exportService->exportIntake($intake);
    
    if ($result['success']) {
        echo "✓ Export successful!\n";
        echo "  Action: {$result['action']}\n";
        echo "  Quotation ID: {$result['quotation_id']}\n";
        echo "  Duration: {$result['duration_ms']}ms\n";
        
        // Check if contact person was linked (this would be in the logs)
        echo "\nCheck the logs above for contact person linking messages.\n";
        echo "The quotation should now have the contact person 'John Smith' linked.\n";
        
        // Update intake to have the quotation ID for future tests
        $intake->update(['robaws_offer_id' => $result['quotation_id']]);
        
    } else {
        echo "✗ Export failed: " . ($result['error'] ?? 'unknown error') . "\n";
    }
    
} catch (Exception $e) {
    echo "✗ Exception during export: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== Test Complete ===\n";
