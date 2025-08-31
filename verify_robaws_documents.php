<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Http;

// Load Laravel app
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔍 VERIFYING ROBAWS DOCUMENTS FOR RECENT QUOTATIONS\n";
echo "=================================================\n\n";

$baseUrl = config('services.robaws.base_url', 'https://app.robaws.com');
$username = config('services.robaws.username');
$password = config('services.robaws.password');

if (!$username || !$password) {
    echo "Error: Robaws credentials not configured\n";
    exit(1);
}

// Get the most recent quotations (the ones likely to be viewed)
$quotations = \App\Models\Quotation::latest()->take(10)->get();

$emptyQuotations = [];
$hasDocuments = [];

foreach ($quotations as $quotation) {
    echo "📋 Checking Quotation {$quotation->id} (Robaws ID: {$quotation->robaws_id})\n";
    
    try {
        // Check documents in this offer
        $response = Http::timeout(60)
            ->withBasicAuth($username, $password)
            ->acceptJson()
            ->get($baseUrl . "/api/v2/offers/{$quotation->robaws_id}/documents");
            
        if ($response->successful()) {
            $documents = $response->json();
            
            if (empty($documents)) {
                echo "  ❌ EMPTY - No documents found\n";
                $emptyQuotations[] = $quotation;
            } else {
                echo "  ✅ HAS DOCS - Found " . count($documents) . " document(s)\n";
                $hasDocuments[] = $quotation;
                
                // Show document details
                foreach ($documents as $doc) {
                    echo "    - Document {$doc['id']}: {$doc['name']} ({$doc['size']} bytes)\n";
                }
            }
        } else {
            echo "  ❌ API ERROR - Status: " . $response->status() . "\n";
        }
        
    } catch (Exception $e) {
        echo "  ❌ EXCEPTION - " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}

echo str_repeat("=", 60) . "\n\n";

echo "📊 SUMMARY:\n";
echo "===========\n";
echo "Quotations with documents: " . count($hasDocuments) . "\n";
echo "Empty quotations: " . count($emptyQuotations) . "\n";

if (!empty($emptyQuotations)) {
    echo "\n❌ EMPTY QUOTATIONS (these will show empty DOCUMENTS tab):\n";
    foreach ($emptyQuotations as $q) {
        echo "  - Quotation {$q->id} (Robaws ID: {$q->robaws_id})\n";
    }
    
    echo "\n🔧 These quotations need their documents uploaded or re-linked.\n";
} else {
    echo "\n🎉 All recent quotations have documents!\n";
}

echo "\n💡 TIP: The screenshots you showed are likely from quotations: " . 
    implode(', ', array_map(fn($q) => $q->robaws_id, $emptyQuotations)) . "\n";
