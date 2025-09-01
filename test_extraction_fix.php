<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Document;
use App\Services\Extraction\Strategies\EmailExtractionStrategy;
use App\Services\AiRouter;
use App\Services\Extraction\HybridExtractionPipeline;

echo "Testing EmailExtractionStrategy with fixed document...\n";

$doc = Document::find(3);
echo "Document ID: {$doc->id}\n";
echo "Filename: {$doc->filename}\n";
echo "Storage disk: {$doc->storage_disk}\n";
echo "File path: {$doc->file_path}\n\n";

try {
    $aiRouter = app(AiRouter::class);
    $hybridPipeline = app(HybridExtractionPipeline::class);
    $strategy = new EmailExtractionStrategy($aiRouter, $hybridPipeline);

    echo "Document supports email extraction: " . ($strategy->supports($doc) ? 'YES' : 'NO') . "\n";

    if ($strategy->supports($doc)) {
        echo "Running extraction...\n";
        $result = $strategy->extract($doc);
        echo "✅ Extraction completed successfully!\n";
        echo "Result success: " . ($result->isSuccessful() ? 'YES' : 'NO') . "\n";
        echo "Confidence: {$result->getConfidence()}%\n";
        if (!$result->isSuccessful()) {
            echo "Error: {$result->getError()}\n";
        } else {
            echo "Extracted data preview:\n";
            print_r(array_slice($result->getExtractedData(), 0, 5));
        }
    }
} catch (Exception $e) {
    echo "❌ Extraction failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
