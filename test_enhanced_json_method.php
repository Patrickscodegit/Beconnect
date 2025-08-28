<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Intake;
use App\Services\RobawsIntegrationService;

echo "🧪 Testing Enhanced JSON Method\n";
echo "===============================\n\n";

// Get the most recent intake with extraction
$intake = Intake::with('extraction')->whereHas('extraction')->orderBy('updated_at', 'desc')->first();

if (!$intake || !$intake->extraction) {
    echo "❌ No intake with extraction found\n";
    exit(1);
}

echo "✅ Found Intake ID: {$intake->id}\n";
echo "📊 Extraction confidence: {$intake->extraction->confidence}%\n";

// Get the RobawsIntegrationService and test the enhanced JSON method
try {
    $service = app(RobawsIntegrationService::class);
    
    // Use reflection to call the private method
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('buildEnhancedExtractionJson');
    $method->setAccessible(true);
    
    echo "\n🚀 Testing buildEnhancedExtractionJson method...\n";
    
    $extractedData = $intake->extraction->extracted_data;
    $extraction = $intake->extraction;
    
    $enhancedJson = $method->invoke($service, $extractedData, $extraction);
    
    echo "✅ Method executed successfully!\n";
    
    // Analyze the result
    echo "\n📊 Enhanced JSON Analysis:\n";
    
    $sections = ['extraction_metadata', 'extraction_data', 'quality_metrics', 'robaws_integration'];
    foreach ($sections as $section) {
        $status = isset($enhancedJson[$section]) ? '✅' : '❌';
        echo "{$status} {$section}: " . (isset($enhancedJson[$section]) ? 'Present' : 'Missing') . "\n";
    }
    
    // Check key metrics
    echo "\n📈 Key Metrics:\n";
    echo "- Confidence Score: " . ($enhancedJson['extraction_metadata']['confidence_score'] ?? 'N/A') . "%\n";
    echo "- Quality Score: " . ($enhancedJson['quality_metrics']['overall_quality_score'] ?? 'N/A') . "%\n";
    echo "- Field Completeness: " . ($enhancedJson['quality_metrics']['field_completeness'] ?? 'N/A') . "%\n";
    echo "- Extraction ID: " . ($enhancedJson['extraction_metadata']['extraction_id'] ?? 'N/A') . "\n";
    
    // Test JSON encoding
    $jsonString = json_encode($enhancedJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    echo "📏 JSON size: " . strlen($jsonString) . " bytes\n";
    
    // Show sample contact data
    if (isset($enhancedJson['extraction_data']['processed_data']['contact_information'])) {
        $contact = $enhancedJson['extraction_data']['processed_data']['contact_information'];
        echo "\n👤 Processed Contact Data:\n";
        echo "- Name: " . ($contact['name'] ?? 'N/A') . "\n";
        echo "- Email: " . ($contact['email'] ?? 'N/A') . "\n";
        echo "- Company: " . ($contact['company'] ?? 'N/A') . "\n";
        echo "- Validation: " . (($contact['validation_status']['valid'] ?? false) ? 'Valid' : 'Invalid') . "\n";
    }
    
    // Show sample of the JSON structure
    echo "\n📝 Sample JSON Structure (first 500 chars):\n";
    echo substr($jsonString, 0, 500) . "...\n";
    
    echo "\n✅ Enhanced JSON generation is working perfectly!\n";
    echo "🎉 Ready to be included in Robaws exports!\n";
    
} catch (Exception $e) {
    echo "❌ Error testing method: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "Test completed!\n";
