<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Quotation;
use App\Services\Export\Mappers\RobawsMapper;

$quotation = Quotation::find(11447);

if (!$quotation) {
    echo "Quotation not found\n";
    exit;
}

echo "Testing phone number extraction for Armos BV...\n\n";

// Check raw extraction data
$extraction = $quotation->extraction;
if ($extraction && $extraction->data) {
    $data = is_string($extraction->data) ? json_decode($extraction->data, true) : $extraction->data;
    $rawData = $data['raw_data'] ?? [];
    
    echo "Available raw_data fields:\n";
    foreach ($rawData as $key => $value) {
        if (is_string($value) && (stripos($key, 'phone') !== false || stripos($key, 'tel') !== false || 
            stripos($key, 'mobile') !== false || stripos($key, 'gsm') !== false || 
            stripos($value, '+32') !== false || preg_match('/\d{2,}/', $value))) {
            echo "- $key: $value\n";
        }
    }
    
    echo "\nLooking for phone-like values:\n";
    foreach ($rawData as $key => $value) {
        if (is_string($value) && (preg_match('/\+32|0\d{1,3}[\s\-\.\(\)]*\d/', $value) || 
            preg_match('/^\d{2,4}[\s\-\.\(\)]*\d/', $value))) {
            echo "- $key: $value\n";
        }
    }
}
