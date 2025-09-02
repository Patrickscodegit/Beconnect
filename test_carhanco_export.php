<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\ExportIntakeToRobawsJob;
use App\Models\Intake;

echo "Testing Carhanco export to Robaws...\n";

$intake = Intake::find(3);
if (!$intake) {
    echo "Intake #3 not found\n";
    exit(1);
}

echo "Intake details:\n";
echo "  ID: {$intake->id}\n";
echo "  Status: {$intake->status}\n";
echo "  Customer: {$intake->customer_name}\n";
echo "  Email: " . ($intake->contact_email ?: 'NULL') . "\n";
echo "  Phone: " . ($intake->contact_phone ?: 'NULL') . "\n";

if ($intake->status !== 'processed') {
    echo "\n❌ Intake is not in 'processed' status, cannot export\n";
    exit(1);
}

echo "\n🚀 Starting export to Robaws...\n";

try {
    // Run the export job
    $job = new ExportIntakeToRobawsJob($intake->id);
    $job->handle();
    
    // Check results
    $intake->refresh();
    
    echo "\n📊 Export results:\n";
    echo "  Status: {$intake->status}\n";
    echo "  Export Error: " . ($intake->last_export_error ?: 'None') . "\n";
    
    if ($intake->status === 'exported') {
        echo "  🎉 SUCCESS: Export completed!\n";
        
        // Check if quotation was created
        if ($intake->robaws_quotation_id) {
            echo "  📄 Quotation ID: {$intake->robaws_quotation_id}\n";
        }
    } else {
        echo "  ❌ Export failed\n";
    }
    
} catch (\Exception $e) {
    echo "\n❌ Export job failed with exception:\n";
    echo "   Error: {$e->getMessage()}\n";
    echo "   File: {$e->getFile()}:{$e->getLine()}\n";
}

echo "\n✅ Test completed\n";
