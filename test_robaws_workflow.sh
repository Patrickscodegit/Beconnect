#!/bin/bash

# Quick Test - Create a test intake and process it to see where it breaks

echo "🧪 Quick Robaws Integration Test"
echo "================================"

# Test file upload and processing workflow
php artisan tinker --execute="
use App\Services\IntakeCreationService;
use App\Jobs\ProcessIntake;

echo '1️⃣ Creating test intake with dummy data...' . PHP_EOL;

try {
    // Create test intake 
    \$service = app(IntakeCreationService::class);
    \$intake = \$service->createFromText('Test freight document for BMW vehicle', [
        'source' => 'manual_test',
        'customer_name' => 'Carhanco',
        'contact_email' => 'test@carhanco.com'
    ]);
    
    echo '✅ Test intake created: ' . \$intake->id . PHP_EOL;
    echo '   Status: ' . \$intake->status . PHP_EOL;
    echo '   Customer: ' . \$intake->customer_name . PHP_EOL;
    echo '   Email: ' . \$intake->contact_email . PHP_EOL;
    
    echo PHP_EOL . '2️⃣ Processing intake...' . PHP_EOL;
    
    // Process the intake
    ProcessIntake::dispatchSync(\$intake);
    
    \$intake->refresh();
    echo '✅ Processing complete' . PHP_EOL;
    echo '   Status: ' . \$intake->status . PHP_EOL;
    echo '   Robaws Client ID: ' . (\$intake->robaws_client_id ?: 'NULL') . PHP_EOL;
    
    echo PHP_EOL . '3️⃣ Checking for export job...' . PHP_EOL;
    
    // Check if export job would be dispatched
    if (\$intake->status === 'processed' && \$intake->robaws_client_id) {
        echo '✅ Would dispatch export job - testing manually...' . PHP_EOL;
        
        \$exportService = app(\App\Services\Robaws\RobawsExportService::class);
        \$result = \$exportService->exportIntake(\$intake);
        
        if (\$result['success'] ?? false) {
            echo '✅ Export successful!' . PHP_EOL;
            echo '   Quotation ID: ' . (\$result['quotation_id'] ?? 'NULL') . PHP_EOL;
        } else {
            echo '❌ Export failed: ' . (\$result['error'] ?? 'Unknown error') . PHP_EOL;
        }
    } else {
        echo '❌ Not ready for export' . PHP_EOL;
        echo '   Status: ' . \$intake->status . PHP_EOL;
        echo '   Client ID: ' . (\$intake->robaws_client_id ?: 'NULL') . PHP_EOL;
    }
    
    echo PHP_EOL . '🧹 Cleaning up...' . PHP_EOL;
    \$intake->files()->delete();
    \$intake->delete();
    echo '✅ Test data cleaned up' . PHP_EOL;
    
} catch (Exception \$e) {
    echo '❌ Test failed: ' . \$e->getMessage() . PHP_EOL;
    echo '   File: ' . \$e->getFile() . ':' . \$e->getLine() . PHP_EOL;
}
"

echo ""
echo "🎯 Test Complete!"
echo ""
echo "💡 If this test passes but your live app doesn't work:"
echo "   • Check if files are being uploaded correctly"
echo "   • Check if ProcessIntake jobs are being dispatched"
echo "   • Check if queue workers are running"
echo "   • Check the specific file type/content you're uploading"
