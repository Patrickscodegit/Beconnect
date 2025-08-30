<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🚀 TESTING COMPLETE FIELD MAPPING FIX\n";
echo "=====================================\n\n";

// Test with document 71
$document = App\Models\Document::find(71);
if (!$document) {
    echo "Document not found\n";
    exit;
}

echo "Testing with document: {$document->filename}\n";

try {
    $service = app(App\Services\RobawsIntegrationService::class);
    $result = $service->createOfferFromDocument($document);
    
    if ($result && isset($result['id'])) {
        echo "✅ Offer created with 2-step process!\n";
        echo "Offer ID: {$result['id']}\n";
        echo "Please check this offer in Robaws UI to verify all fields are populated!\n";
    } else {
        echo "❌ Failed to create offer\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
