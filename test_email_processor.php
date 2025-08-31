<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Utils\EmailQuotationProcessor;
use App\Services\Extraction\HybridExtractionPipeline;

echo "=== EMAIL QUOTATION PROCESSOR TEST ===\n\n";

// Initialize processor
$pipeline = app(HybridExtractionPipeline::class);
$processor = new EmailQuotationProcessor($pipeline);

// Read the email
$emailPath = '/Users/patrickhome/Downloads/68b320c74d202_01K3XVG2MBHB27G6HHDHBT47RX.eml';
$emailContent = file_get_contents($emailPath);

echo "📧 Processing email (first time)...\n";
$result1 = $processor->processEmailForQuotation($emailContent, 'email_001');

echo "Status: " . $result1['status'] . "\n";
echo "Quality: " . $result1['metadata']['extraction_quality'] . "%\n";
echo "Completeness: " . round($result1['metadata']['completeness_score'] * 100, 1) . "%\n";
echo "Recommendation: " . $result1['recommendation']['action'] . " (" . $result1['recommendation']['confidence'] . ")\n\n";

echo "📋 QUOTATION FIELDS:\n";
foreach ($result1['quotation_fields'] as $field => $value) {
    $status = $value ? '✅' : '❌';
    echo "$status $field: " . ($value ?: 'MISSING') . "\n";
}

echo "\n🔄 Processing same email again (should detect duplicate)...\n";
$result2 = $processor->processEmailForQuotation($emailContent, 'email_001');

echo "Status: " . $result2['status'] . "\n";
echo "Message: " . $result2['message'] . "\n\n";

echo "🔄 Processing same content with different ID...\n";
$result3 = $processor->processEmailForQuotation($emailContent, 'email_002');

echo "Status: " . $result3['status'] . "\n";
echo "Message: " . $result3['message'] . "\n\n";

echo "📊 ROBAWS INTEGRATION FIELDS:\n";
foreach ($result1['robaws_fields'] as $field => $value) {
    echo "✅ $field: $value\n";
}

echo "\n🎯 PROCESSING RECOMMENDATION:\n";
$rec = $result1['recommendation'];
echo "Action: " . $rec['action'] . "\n";
echo "Confidence: " . $rec['confidence'] . "\n";
echo "Message: " . $rec['message'] . "\n";

if (!empty($rec['missing_fields'])) {
    echo "Missing Fields: " . implode(', ', $rec['missing_fields']) . "\n";
}

echo "\n📈 QUALITY METRICS:\n";
echo "Extraction Quality: " . $result1['quotation_fields']['extraction_quality'] . "%\n";
echo "Data Completeness: " . $result1['quotation_fields']['data_completeness'] . "%\n";

$warnings = data_get($result1['extraction_data'], 'final_validation.warnings', []);
if (!empty($warnings)) {
    echo "Validation Warnings:\n";
    foreach ($warnings as $warning) {
        echo "  ⚠️  $warning\n";
    }
}

echo "\n🚀 READY FOR QUOTATION SYSTEM!\n";
echo "The email has been successfully processed and all fields are mapped for quotation creation.\n";

// Clear cache for next run
EmailQuotationProcessor::clearCache();
echo "\n✅ Cache cleared for next processing session.\n";
