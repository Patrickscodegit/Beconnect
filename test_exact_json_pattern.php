<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== TESTING WITH EXACT JSON FIELD PATTERN ===\n\n";

// Get document with extraction data
$document = App\Models\Document::whereNotNull('extraction_data')
    ->where('id', 71)
    ->first();

if (!$document) {
    echo "Document not found.\n";
    exit;
}

echo "Using Document ID: {$document->id}\n";
echo "Filename: {$document->filename}\n";

// Test the complete export process
try {
    $service = app(App\Services\RobawsIntegrationService::class);
    
    echo "\n=== EXPORTING TO ROBAWS ===\n";
    echo "All fields are configured the same way as JSON in Robaws\n";
    echo "Using EXACT same pattern for all fields...\n\n";
    
    $result = $service->createOfferFromDocument($document);
    
    if ($result && isset($result['id'])) {
        echo "✅ Export successful!\n";
        echo "Robaws Offer ID: {$result['id']}\n\n";
        
        echo "=== EXPECTED RESULTS ===\n";
        echo "Since all fields are configured the same way as JSON in Robaws:\n";
        echo "✅ JSON field should be populated (was working before)\n";
        echo "✅ CARGO field should be populated\n";
        echo "✅ Customer field should be populated\n";
        echo "✅ Customer reference field should be populated\n";
        echo "✅ Contact field should be populated\n";
        echo "✅ POR field should be populated\n";
        echo "✅ POL field should be populated\n";
        echo "✅ POD field should be populated\n";
        echo "✅ DIM_BEF_DELIVERY field should be populated\n";
        
        echo "\nPlease check Robaws offer ID: {$result['id']}\n";
        
        // Check if we have extraFields in the response
        if (isset($result['extraFields'])) {
            echo "\n=== CHECKING FIELD POPULATION ===\n";
            $targetFields = ['JSON', 'CARGO', 'Customer', 'Customer reference', 'Contact', 'POR', 'POL', 'POD', 'DIM_BEF_DELIVERY'];
            
            foreach ($targetFields as $fieldName) {
                $found = false;
                foreach ($result['extraFields'] as $field) {
                    if (is_array($field) && isset($field['key']) && $field['key'] === $fieldName) {
                        $value = $field['value'] ?? '';
                        if (!empty($value)) {
                            echo "✅ {$fieldName}: POPULATED\n";
                        } else {
                            echo "❌ {$fieldName}: EMPTY\n";
                        }
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    echo "❓ {$fieldName}: NOT FOUND\n";
                }
            }
        }
        
    } else {
        echo "❌ Export failed\n";
        if ($result) {
            echo "Result: " . print_r($result, true) . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "\n=== CHECK LOGS ===\n";
echo "Run: tail -50 storage/logs/laravel.log | grep 'Robaws payload FINAL'\n";
echo "This will show exactly what fields and values were sent.\n";
