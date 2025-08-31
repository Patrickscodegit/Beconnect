<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Intake;
use App\Services\RobawsExportService;

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🧪 Testing Offer Reuse Logic\n";
echo str_repeat("=", 60) . "\n";

try {
    // Find an intake with processed documents
    $intake = Intake::whereHas('documents.extractions', function ($query) {
        $query->where('status', 'completed')
              ->whereNotNull('extracted_data');
    })->first();

    if (!$intake) {
        echo "❌ No processed intakes found\n";
        exit(1);
    }

    echo "📄 Testing intake: {$intake->id}\n";
    echo "🏠 Customer: {$intake->customer_name} <{$intake->customer_email}>\n";
    echo "📁 Documents: " . $intake->documents()->count() . "\n";
    echo "✅ Processed docs: " . $intake->documents()
        ->whereHas('extractions', function ($query) {
            $query->where('status', 'completed')
                  ->whereNotNull('extracted_data');
        })->count() . "\n";
    
    // Check current Robaws state
    echo "\n🎯 Current Robaws State:\n";
    echo "   - Offer ID: " . ($intake->robaws_offer_id ?: 'NONE') . "\n";
    echo "   - Offer Number: " . ($intake->robaws_offer_number ?: 'NONE') . "\n";

    $service = app(RobawsExportService::class);

    echo "\n🚀 FIRST EXPORT ATTEMPT:\n";
    echo "   (Should create new offer if none exists, or reuse existing)\n";
    
    $result1 = $service->exportIntake($intake);
    
    echo "   Results:\n";
    echo "   - Offer ID: " . ($result1['offer_id'] ?? 'MISSING') . "\n";
    echo "   - Uploaded: " . ($result1['uploaded'] ?? 0) . "\n";
    echo "   - Already Exists: " . ($result1['exists'] ?? 0) . "\n";
    echo "   - Failed: " . ($result1['failed'] ?? 0) . "\n";
    
    if (!empty($result1['errors'])) {
        echo "   - Errors: " . implode(', ', $result1['errors']) . "\n";
    }

    // Reload intake to see updated fields
    $intake->refresh();
    echo "   - Intake Offer ID after: " . ($intake->robaws_offer_id ?: 'NONE') . "\n";

    if ($intake->robaws_offer_id) {
        echo "\n🔄 SECOND EXPORT ATTEMPT:\n";
        echo "   (Should reuse existing offer: {$intake->robaws_offer_id})\n";
        
        $result2 = $service->exportIntake($intake);
        
        echo "   Results:\n";
        echo "   - Offer ID: " . ($result2['offer_id'] ?? 'MISSING') . "\n";
        echo "   - Uploaded: " . ($result2['uploaded'] ?? 0) . "\n";
        echo "   - Already Exists: " . ($result2['exists'] ?? 0) . "\n";
        echo "   - Failed: " . ($result2['failed'] ?? 0) . "\n";
        
        if ($result1['offer_id'] === $result2['offer_id']) {
            echo "   ✅ OFFER REUSE SUCCESS: Same offer used both times!\n";
        } else {
            echo "   ❌ OFFER REUSE FAILED: Different offers created!\n";
            echo "      First: " . ($result1['offer_id'] ?? 'none') . "\n";
            echo "      Second: " . ($result2['offer_id'] ?? 'none') . "\n";
        }
    }

    echo "\n📊 Robaws Documents Ledger:\n";
    $robawsDocs = DB::table('robaws_documents')
        ->whereIn('document_id', $intake->documents()->pluck('id'))
        ->get();
        
    foreach ($robawsDocs as $doc) {
        echo "   - Doc {$doc->document_id}: {$doc->filename} -> Offer {$doc->robaws_offer_id}\n";
        echo "     Hash: " . substr($doc->content_hash, 0, 16) . "...\n";
        echo "     Status: {$doc->upload_status}\n";
    }

    echo "\n🎉 Test completed successfully!\n";

} catch (\Exception $e) {
    echo "❌ Test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
