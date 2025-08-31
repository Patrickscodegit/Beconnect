<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Http;

// Load Laravel app
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Testing document attachment with name field...\n";

$baseUrl = config('services.robaws.base_url', 'https://app.robaws.com');
$username = config('services.robaws.username');
$password = config('services.robaws.password');

if (!$username || !$password) {
    echo "Error: Robaws credentials not configured\n";
    exit(1);
}

$offerId = '11443';
$documentId = '107102';

try {
    // Get document details first to get the name
    echo "Getting document details...\n";
    $docResponse = Http::timeout(60)
        ->withBasicAuth($username, $password)
        ->acceptJson()
        ->get($baseUrl . "/api/v2/documents/{$documentId}");
        
    if (!$docResponse->successful()) {
        echo "Failed to get document details\n";
        exit(1);
    }
    
    $document = $docResponse->json();
    $documentName = $document['name'] ?? 'document.pdf';
    echo "Document name: {$documentName}\n";
    
    // Method: POST to offers/{id}/documents with name and documentId
    echo "\nTesting POST /offers/{$offerId}/documents with name...\n";
    $response = Http::timeout(60)
        ->withBasicAuth($username, $password)
        ->acceptJson()
        ->post($baseUrl . "/api/v2/offers/{$offerId}/documents", [
            'name' => $documentName,
            'documentId' => $documentId
        ]);
        
    echo "Status: " . $response->status() . "\n";
    echo "Response: " . $response->body() . "\n";
    
    // Check if it worked
    echo "\nChecking documents after attachment...\n";
    $check = Http::timeout(60)
        ->withBasicAuth($username, $password)
        ->acceptJson()
        ->get($baseUrl . "/api/v2/offers/{$offerId}/documents");
    $documents = $check->json();
    echo "Documents: " . json_encode($documents, JSON_PRETTY_PRINT) . "\n";
    
    if (!empty($documents)) {
        echo "SUCCESS: Document attached!\n";
        echo "Number of documents: " . count($documents) . "\n";
    } else {
        echo "Document still not attached.\n";
        
        // Let's also try with just the document file content
        echo "\nTrying alternative approach - attach by uploading file directly...\n";
        
        // Try to get the document content and upload it as a new document to the offer
        $contentResponse = Http::timeout(60)
            ->withBasicAuth($username, $password)
            ->get($baseUrl . "/api/v2/documents/{$documentId}?inline=false");
            
        if ($contentResponse->successful()) {
            echo "Got document content, size: " . strlen($contentResponse->body()) . " bytes\n";
            
            // Try to upload this as a new document to the offer
            $uploadResponse = Http::timeout(60)
                ->withBasicAuth($username, $password)
                ->attach('file', $contentResponse->body(), $documentName)
                ->post($baseUrl . "/api/v2/offers/{$offerId}/documents");
                
            echo "Upload status: " . $uploadResponse->status() . "\n";
            echo "Upload response: " . $uploadResponse->body() . "\n";
            
            // Check again
            $check = Http::timeout(60)
                ->withBasicAuth($username, $password)
                ->acceptJson()
                ->get($baseUrl . "/api/v2/offers/{$offerId}/documents");
            $documents = $check->json();
            echo "Documents after upload: " . json_encode($documents, JSON_PRETTY_PRINT) . "\n";
            
            if (!empty($documents)) {
                echo "SUCCESS: Document uploaded directly!\n";
            }
        } else {
            echo "Failed to get document content\n";
        }
    }
    
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
