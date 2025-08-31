<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Models\Intake;
use App\Services\Robaws\RobawsExportService;
use Illuminate\Foundation\Application;

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🧪 Testing Filament Export Fix\n";
echo "================================\n\n";

try {
    // Get the export service
    $exportService = app(RobawsExportService::class);
    
    // Find an intake to test with
    $intake = Intake::with('documents')->first();
    
    if (!$intake) {
        echo "❌ No intakes found in database\n";
        exit(1);
    }
    
    echo "📋 Testing with intake ID: {$intake->id}\n";
    echo "📄 Documents count: " . $intake->documents->count() . "\n\n";
    
    // Test the export method
    echo "🚀 Calling exportIntake method...\n";
    $results = $exportService->exportIntake($intake);
    
    echo "📊 Export Results:\n";
    echo "  - Structure: " . json_encode(array_keys($results), JSON_PRETTY_PRINT) . "\n";
    echo "  - Success: " . ($results['success'] ?? 'MISSING') . "\n";
    echo "  - Failed: " . ($results['failed'] ?? 'MISSING') . "\n";
    echo "  - Exists: " . ($results['exists'] ?? 'MISSING') . "\n";
    echo "  - Errors: " . json_encode($results['errors'] ?? [], JSON_PRETTY_PRINT) . "\n";
    
    // Test the specific keys that Filament expects
    $requiredKeys = ['success', 'failed', 'exists', 'errors'];
    $missingKeys = [];
    
    foreach ($requiredKeys as $key) {
        if (!array_key_exists($key, $results)) {
            $missingKeys[] = $key;
        }
    }
    
    if (empty($missingKeys)) {
        echo "\n✅ SUCCESS: All required keys present for Filament compatibility!\n";
        echo "🎉 The 'Undefined array key \"failed\"' error should be fixed.\n";
    } else {
        echo "\n❌ ERROR: Missing required keys: " . implode(', ', $missingKeys) . "\n";
    }
    
} catch (\Exception $e) {
    echo "❌ Exception occurred: " . $e->getMessage() . "\n";
    echo "📍 File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n🏁 Test completed.\n";
