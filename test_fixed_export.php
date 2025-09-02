<?php

// Run via: php artisan tinker --execute="require 'test_fixed_export.php';"

use App\Models\Intake;
use App\Jobs\ExportIntakeToRobawsJob;

echo "🔄 TESTING FIXED EXPORT JOB\n";
echo "===========================\n\n";

$intake = Intake::find(1);

// Reset the intake for testing
$intake->update([
    'status' => 'processed',
    'robaws_offer_id' => null,
    'robaws_offer_number' => null,
]);

echo "Before export:\n";
echo "  Status: {$intake->status}\n";
echo "  Offer ID: " . ($intake->robaws_offer_id ?: '(null)') . "\n";
echo "  Offer Number: " . ($intake->robaws_offer_number ?: '(null)') . "\n\n";

echo "🚀 Running export job...\n";

try {
    $exportJob = new ExportIntakeToRobawsJob($intake->id);
    $exportJob->handle();
    
    // Refresh to see changes
    $intake->refresh();
    
    echo "✅ Export job completed\n\n";
    echo "After export:\n";
    echo "  Status: {$intake->status}\n";
    echo "  Offer ID: " . ($intake->robaws_offer_id ?: '(null)') . "\n";
    echo "  Offer Number: " . ($intake->robaws_offer_number ?: '(null)') . "\n";
    echo "  Export Error: " . ($intake->last_export_error ?: '(none)') . "\n";
    
    if ($intake->robaws_offer_id) {
        echo "\n🎯 SUCCESS: Offer created in Robaws!\n";
        echo "   Offer ID: {$intake->robaws_offer_id}\n";
        echo "   Offer Number: {$intake->robaws_offer_number}\n";
    } else {
        echo "\n⚠️  Issue: No offer ID saved\n";
    }
    
} catch (\Exception $e) {
    echo "❌ Export error: {$e->getMessage()}\n";
    echo "File: {$e->getFile()}:{$e->getLine()}\n";
}
