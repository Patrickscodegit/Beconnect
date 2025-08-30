<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Document;

echo "🔍 Finding documents with extraction data:\n";
echo "==========================================\n";

$documents = Document::with('extractions')->get();

foreach ($documents as $doc) {
    $extraction = $doc->extractions()->latest()->first();
    if ($extraction && $extraction->extracted_data) {
        $data = is_string($extraction->extracted_data) 
            ? json_decode($extraction->extracted_data, true) 
            : $extraction->extracted_data;
        
        if (is_array($data) && !empty($data)) {
            echo "📄 Document ID: {$doc->id}\n";
            echo "   File: {$doc->filename}\n";
            echo "   Has data: " . (count($data) > 0 ? 'YES' : 'NO') . "\n";
            
            // Show structure
            if (isset($data['quotation_info'])) {
                echo "   ✓ quotation_info\n";
            }
            if (isset($data['routing'])) {
                echo "   ✓ routing\n";
            }
            if (isset($data['cargo_details'])) {
                echo "   ✓ cargo_details\n";
            }
            
            echo "   ---\n";
        }
    }
}
