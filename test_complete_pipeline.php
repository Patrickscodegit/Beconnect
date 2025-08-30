<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\Extraction\HybridExtractionPipeline;
use App\Services\Extraction\Strategies\PatternExtractor;
use App\Services\Extraction\Strategies\DatabaseExtractor;
use App\Services\Extraction\Strategies\AiExtractor;
use App\Services\VehicleDatabase\VehicleDatabaseService;
use Illuminate\Foundation\Application;

// Create Laravel app instance
$app = new Application(
    $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)
);

// Bootstrap the application
$app->singleton(
    Illuminate\Contracts\Http\Kernel::class,
    App\Http\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "🔍 Testing Complete PDF Dimension Extraction Pipeline\n";
echo "=" . str_repeat("=", 70) . "\n";

// Sample PDF text (from the Bentley Continental invoice)
$pdfText = "BENTLEY CONTINENTAL
VIN: SCBFF63W2HC064730
Model: 2017
Color: BLACK";

echo "📄 Testing with PDF text:\n";
echo $pdfText . "\n\n";

// Create pipeline components
$vehicleDb = app(VehicleDatabaseService::class);
$patternExtractor = new PatternExtractor($vehicleDb);
$databaseExtractor = new DatabaseExtractor($vehicleDb);
$aiExtractor = new AiExtractor(); // Mock for testing

// Create pipeline
$pipeline = new HybridExtractionPipeline(
    $patternExtractor,
    $databaseExtractor,
    $aiExtractor
);

// Run extraction
echo "🚀 Running extraction pipeline...\n";
$result = $pipeline->extract($pdfText, 'pdf');

echo "\n📊 Extraction Results:\n";
echo json_encode($result, JSON_PRETTY_PRINT) . "\n";

// Check if AI enhancement would be triggered
echo "\n🤖 AI Enhancement Check:\n";
if (!empty($result['vehicle']['needs_dimension_lookup'])) {
    echo "✅ Vehicle marked for AI dimension lookup\n";
    echo "🎯 AI would be prompted to find dimensions for: " . 
         ($result['vehicle']['brand'] ?? 'Unknown') . " " . 
         ($result['vehicle']['model'] ?? 'Unknown') . " " .
         ($result['vehicle']['year'] ?? 'Unknown') . "\n";
         
    echo "\n📝 Sample AI Prompt that would be sent:\n";
    $prompt = "Based on the vehicle identified as " . 
              ($result['vehicle']['brand'] ?? 'Unknown') . " " . 
              ($result['vehicle']['model'] ?? 'Unknown') . " " .
              ($result['vehicle']['year'] ?? 'Unknown') . 
              ", please provide the standard manufacturer dimensions.\n";
    $prompt .= "Format: Length × Width × Height in meters (e.g., 5.299 × 1.946 × 1.405)\n";
    $prompt .= "Use actual Bentley factory specifications, not estimates.\n";
    
    echo $prompt . "\n";
} else {
    echo "❌ No AI enhancement would be triggered\n";
}

echo "\n✅ Pipeline test completed!\n";
