<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Http;

// Load Laravel app
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔍 CHECKING ALL RECENT QUOTATIONS FOR DOCUMENTS\n";
echo "===============================================\n\n";

$baseUrl = config('services.robaws.base_url', 'https://app.robaws.com');
$username = config('services.robaws.username');
$password = config('services.robaws.password');

if (!$username || !$password) {
    echo "Error: Robaws credentials not configured\n";
    exit(1);
}

// Get recent quotations from our database
$quotations = \App\Models\Quotation::latest()->take(5)->get();

foreach ($quotations as $quotation) {
    echo "📋 Quotation {$quotation->id} (Robaws ID: {$quotation->robaws_id})\n";
    echo "---------------------------------------------------\n";
    
    try {
        // Check documents in this offer
        $response = Http::timeout(60)
            ->withBasicAuth($username, $password)
            ->acceptJson()
            ->get($baseUrl . "/api/v2/offers/{$quotation->robaws_id}/documents");
            
        if ($response->successful()) {
            $documents = $response->json();
            
            if (empty($documents)) {
                echo "❌ NO DOCUMENTS FOUND\n";
            } else {
                echo "✅ Found " . count($documents) . " document(s):\n";
                foreach ($documents as $doc) {
                    echo "  - Document {$doc['id']}: {$doc['name']}\n";
                    echo "    Size: {$doc['size']} bytes\n";
                    echo "    Created: {$doc['createdAt']}\n";
                }
            }
        } else {
            echo "❌ API Error: " . $response->status() . " - " . $response->body() . "\n";
        }
        
        // Also check our local documents for this quotation
        $localDocs = \App\Models\Document::where('robaws_quotation_id', $quotation->robaws_id)->get();
        echo "\n📁 Local documents for this quotation:\n";
        if ($localDocs->isEmpty()) {
            echo "  None found\n";
        } else {
            foreach ($localDocs as $doc) {
                echo "  - Document {$doc->id}: {$doc->filename}\n";
                echo "    Upload status: " . ($doc->upload_status ?: 'None') . "\n";
                echo "    Robaws doc ID: " . ($doc->robaws_document_id ?: 'None') . "\n";
            }
        }
        
    } catch (Exception $e) {
        echo "❌ Exception: " . $e->getMessage() . "\n";
    }
    
    echo "\n" . str_repeat("=", 60) . "\n\n";
}

echo "🔍 SUMMARY: Check which quotations have documents in Robaws vs our system\n";
