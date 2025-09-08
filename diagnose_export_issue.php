<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Intake;
use App\Services\Robaws\RobawsExportService;

echo "=== Robaws Export Diagnostic ===\n\n";

// 1. Check intakes
echo "1. Checking intakes...\n";
$intakes = Intake::take(5)->get();
echo "Total intakes: " . Intake::count() . "\n";

if ($intakes->isEmpty()) {
    echo "❌ No intakes found\n";
    exit(1);
}

foreach ($intakes as $intake) {
    echo "  Intake {$intake->id}: {$intake->customer_name}\n";
    echo "    Status: {$intake->status}\n";
    echo "    Robaws offer ID: " . ($intake->robaws_offer_id ?: 'None') . "\n";
    echo "    Export attempts: " . ($intake->export_attempt_count ?: '0') . "\n";
    echo "    Last error: " . ($intake->last_export_error ?: 'None') . "\n";
    echo "    Has extraction data: " . (!empty($intake->extraction_data) ? 'Yes' : 'No') . "\n\n";
}

// 2. Test Robaws connection
echo "2. Testing Robaws connection...\n";
try {
    $exportService = app(RobawsExportService::class);
    $connectionTest = $exportService->testConnection();
    
    if ($connectionTest['success']) {
        echo "✓ Robaws connection successful\n";
    } else {
        echo "❌ Robaws connection failed: " . ($connectionTest['error'] ?? 'Unknown error') . "\n";
    }
} catch (Exception $e) {
    echo "❌ Exception testing connection: " . $e->getMessage() . "\n";
}

echo "\n3. Testing manual export...\n";
$testIntake = $intakes->first();

try {
    echo "Attempting to export intake {$testIntake->id}...\n";
    $result = $exportService->exportIntake($testIntake, ['force' => true]);
    
    if ($result['success']) {
        echo "✓ Export successful!\n";
        echo "  Action: {$result['action']}\n";
        echo "  Quotation ID: {$result['quotation_id']}\n";
        echo "  Duration: {$result['duration_ms']}ms\n";
    } else {
        echo "❌ Export failed: " . ($result['error'] ?? 'Unknown error') . "\n";
        if (isset($result['details'])) {
            echo "  Details: " . json_encode($result['details'], JSON_PRETTY_PRINT) . "\n";
        }
    }
} catch (Exception $e) {
    echo "❌ Exception during export: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== Diagnostic Complete ===\n";
