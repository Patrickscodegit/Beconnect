<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\RobawsIntegrationService;
use App\Services\RobawsClient;
use App\Models\Document;
use Illuminate\Support\Facades\Log;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "🔍 DEBUGGING DOCUMENT 33 FAILURE\n";
echo "=================================\n\n";

try {
    // Get document 33
    $document = Document::find(33);
    
    if (!$document) {
        echo "❌ Document 33 not found\n";
        exit;
    }
    
    echo "📄 Document 33 Details:\n";
    echo "  Filename: {$document->filename}\n";
    echo "  File Path: {$document->file_path}\n";
    echo "  Storage Disk: {$document->storage_disk}\n";
    echo "  Processing Status: {$document->processing_status}\n";
    echo "  Processing Error: {$document->processing_error}\n";
    echo "  Robaws Quotation ID: {$document->robaws_quotation_id}\n\n";
    
    // Check if file exists
    $fileExists = \Storage::disk($document->storage_disk)->exists($document->file_path);
    echo "📁 File exists: " . ($fileExists ? 'YES' : 'NO') . "\n\n";
    
    // Check Robaws configuration
    echo "🔧 Robaws Configuration:\n";
    echo "  Base URL: " . config('services.robaws.base_url') . "\n";
    echo "  Username: " . (config('services.robaws.username') ? 'SET' : 'NOT SET') . "\n";
    echo "  Password: " . (config('services.robaws.password') ? 'SET' : 'NOT SET') . "\n\n";
    
    // Test Robaws connection
    echo "🌐 Testing Robaws Connection...\n";
    $robawsClient = new RobawsClient();
    $connectionTest = $robawsClient->testConnection();
    
    if ($connectionTest['success']) {
        echo "✅ Robaws connection successful\n";
    } else {
        echo "❌ Robaws connection failed: " . ($connectionTest['message'] ?? 'Unknown error') . "\n";
    }
    
    echo "\n🧪 Testing Document 33 Integration...\n";
    
    // Test the integration service
    $robawsService = new RobawsIntegrationService($robawsClient);
    
    try {
        $result = $robawsService->createOfferFromDocument($document);
        
        if ($result && isset($result['id'])) {
            echo "✅ Successfully created offer: {$result['id']}\n";
        } else {
            echo "❌ Failed to create offer\n";
            if (is_array($result)) {
                echo "Response: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
            }
        }
    } catch (\Exception $e) {
        echo "❌ Exception during offer creation:\n";
        echo "  Error: " . $e->getMessage() . "\n";
        echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        echo "  Trace: " . $e->getTraceAsString() . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "📍 File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n🏁 Debug complete!\n";
