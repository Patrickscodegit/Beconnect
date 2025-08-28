<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\SimpleRobawsIntegration;

// Sample extracted data structure (simulating what AI extraction would provide)
$sampleExtractionData = [
    'email_metadata' => [
        'subject' => 'Vehicle Transport Quote Request - BMW 7 Series from Brussels to Jeddah',
        'from' => 'john.smith@example.com',
        'to' => 'sales@belgaco.be',
        'date' => '2024-12-23T10:30:00+01:00'
    ],
    'contact' => [
        'name' => 'John Smith',
        'phone' => '+32 472 123 456',
        'email' => 'john.smith@example.com',
        'address' => 'Avenue Louise 123, 1050 Brussels, Belgium'
    ],
    'shipment' => [
        'origin' => 'Brussels, Belgium',
        'destination' => 'Jeddah, Saudi Arabia'
    ],
    'vehicle' => [
        'brand' => 'BMW',
        'make' => 'BMW',
        'model' => '7 Series',
        'year' => '2020',
        'color' => 'Black',
        'condition' => 'Used',
        'vin' => 'WBAGW51040DX12345',
        'fuel_type' => 'Diesel',
        'engine_cc' => '3000',
        'weight_kg' => 1950,
        'dimensions' => [
            'length_m' => 5.12,
            'width_m' => 1.90,
            'height_m' => 1.48
        ]
    ],
    'messages' => [
        [
            'sender' => 'John Smith',
            'text' => 'I need a quote for shipping my vehicle from Brussels, Belgium to Jeddah, Saudi Arabia.',
            'timestamp' => '2024-12-23T10:30:00+01:00'
        ]
    ],
    'metadata' => [
        'confidence_score' => 0.95
    ]
];

echo "Testing Enhanced Field Mapping for Robaws Integration\n";
echo "==================================================\n\n";

// Test the formatForRobaws method
$robawsService = new SimpleRobawsIntegration();
$formattedData = $robawsService->formatForRobaws($sampleExtractionData);

echo "Original Data Structure:\n";
echo json_encode($sampleExtractionData, JSON_PRETTY_PRINT) . "\n\n";

echo "Formatted for Robaws:\n";
echo json_encode($formattedData, JSON_PRETTY_PRINT) . "\n\n";

echo "Key Robaws Fields Check:\n";
echo "========================\n";
echo "Customer: " . ($formattedData['customer'] ?? 'NOT SET') . "\n";
echo "Customer Reference: " . ($formattedData['customer_reference'] ?? 'NOT SET') . "\n";
echo "POR (Port of Receipt): " . ($formattedData['por'] ?? 'NOT SET') . "\n";
echo "POL (Port of Loading): " . ($formattedData['pol'] ?? 'NOT SET') . "\n";
echo "POD (Port of Discharge): " . ($formattedData['pod'] ?? 'NOT SET') . "\n";
echo "Cargo: " . ($formattedData['cargo'] ?? 'NOT SET') . "\n";
echo "Dimensions: " . ($formattedData['dim_bef_delivery'] ?? 'NOT SET') . "\n";
echo "Volume: " . ($formattedData['volume_m3'] ?? 'NOT SET') . " Cbm\n";
echo "Vehicle Brand: " . ($formattedData['vehicle_brand'] ?? 'NOT SET') . "\n";
echo "Vehicle Model: " . ($formattedData['vehicle_model'] ?? 'NOT SET') . "\n";
echo "Vehicle Year: " . ($formattedData['vehicle_year'] ?? 'NOT SET') . "\n";
echo "Contact: " . ($formattedData['contact'] ?? 'NOT SET') . "\n";
echo "Email: " . ($formattedData['client_email'] ?? 'NOT SET') . "\n";
echo "Internal Remarks: " . ($formattedData['internal_remarks'] ?? 'NOT SET') . "\n";
