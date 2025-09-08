<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Intake;

echo "=== Checking Extraction Data Structure ===\n";

$intake = Intake::find(7); // Check the most recent one
if ($intake && $intake->extraction_data) {
    echo "Intake 7 extraction data structure:\n";
    echo json_encode($intake->extraction_data, JSON_PRETTY_PRINT) . "\n";
    
    // Check what keys are present
    echo "\nTop-level keys in extraction_data:\n";
    foreach (array_keys($intake->extraction_data) as $key) {
        echo "  - $key\n";
    }
} else {
    echo "No extraction data found for intake 7\n";
}

// Let's also check the working intake (ID 1) for comparison
echo "\n=== Comparing with Working Intake 1 ===\n";
$workingIntake = Intake::find(1);
if ($workingIntake && $workingIntake->extraction_data) {
    echo "Intake 1 extraction data structure (first 500 chars):\n";
    $extractionDataStr = json_encode($workingIntake->extraction_data, JSON_PRETTY_PRINT);
    echo substr($extractionDataStr, 0, 500) . "...\n";
    
    echo "\nTop-level keys in working intake extraction_data:\n";
    foreach (array_keys($workingIntake->extraction_data) as $key) {
        echo "  - $key\n";
    }
}
