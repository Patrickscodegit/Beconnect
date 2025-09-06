#!/bin/bash

# Debug Export Job Issue - Test the complete flow step by step

echo "🔍 Debug Export Job Flow"
echo "========================"

php artisan tinker --execute="
use App\Services\IntakeCreationService;
use App\Jobs\ProcessIntake;
use App\Jobs\ExportIntakeToRobawsJob;

echo '1️⃣ Creating test intake...' . PHP_EOL;
\$service = app(IntakeCreationService::class);
\$intake = \$service->createFromText('BMW freight shipment', [
    'source' => 'debug_test',
    'customer_name' => 'Carhanco', 
    'contact_email' => 'test@carhanco.com'
]);

echo '✅ Created intake: ' . \$intake->id . PHP_EOL;
echo '   Initial Status: ' . \$intake->status . PHP_EOL;

echo PHP_EOL . '2️⃣ Processing with ProcessIntake job...' . PHP_EOL;
ProcessIntake::dispatchSync(\$intake);

\$intake->refresh();
echo '✅ After ProcessIntake:' . PHP_EOL;
echo '   Status: ' . \$intake->status . PHP_EOL;
echo '   Client ID: ' . (\$intake->robaws_client_id ?: 'NULL') . PHP_EOL;

if (\$intake->status === 'processed') {
    echo PHP_EOL . '3️⃣ Status is processed - would dispatch export job' . PHP_EOL;
    echo '   Testing ExportIntakeToRobawsJob manually...' . PHP_EOL;
    
    try {
        ExportIntakeToRobawsJob::dispatchSync(\$intake->id);
        
        \$intake->refresh();
        echo '✅ After ExportIntakeToRobawsJob:' . PHP_EOL;
        echo '   Status: ' . \$intake->status . PHP_EOL;
        echo '   Robaws Offer ID: ' . (\$intake->robaws_offer_id ?: 'NULL') . PHP_EOL;
        echo '   Last Error: ' . (\$intake->last_export_error ?: 'None') . PHP_EOL;
        
    } catch (Exception \$e) {
        echo '❌ ExportIntakeToRobawsJob failed: ' . \$e->getMessage() . PHP_EOL;
    }
} else {
    echo PHP_EOL . '❌ Status is not processed, export would not be dispatched' . PHP_EOL;
    
    if (\$intake->status === 'completed') {
        echo '💡 Status is completed - this suggests export already happened' . PHP_EOL;
        echo '   Robaws Offer ID: ' . (\$intake->robaws_offer_id ?: 'NULL') . PHP_EOL;
        
        // Check if there's a quotation created
        if (\$intake->robaws_offer_id) {
            echo '✅ Offer was created: ' . \$intake->robaws_offer_id . PHP_EOL;
            echo '🎉 SUCCESS: Robaws integration is working!' . PHP_EOL;
        } else {
            echo '❌ No offer ID - something went wrong' . PHP_EOL;
        }
    }
}

echo PHP_EOL . '🧹 Cleaning up...' . PHP_EOL;
\$intake->files()->delete();
\$intake->delete();
echo '✅ Test cleaned up' . PHP_EOL;
"
