<?php

use App\Models\Extraction;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔄 UPDATING EXISTING EXTRACTIONS WITH QUOTATION IDS\n";
echo "===================================================\n";

// Update existing extractions to have the same quotation ID as their documents
$updated = 0;
$extractions = Extraction::with('document')->get();

foreach ($extractions as $extraction) {
    if ($extraction->document && 
        $extraction->document->robaws_quotation_id && 
        !$extraction->robaws_quotation_id) {
        
        $extraction->update([
            'robaws_quotation_id' => $extraction->document->robaws_quotation_id
        ]);
        $updated++;
        echo "Updated extraction {$extraction->id} with quotation ID {$extraction->document->robaws_quotation_id}\n";
    }
}

echo "Updated {$updated} extractions\n";
echo "\n🚀 Ready to test with new uploads!\n";
