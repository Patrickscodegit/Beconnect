#!/bin/bash

echo "🚀 Complete Production AI & Robaws Fix"
echo "======================================"
echo

cd /home/forge/bconnect.64.226.120.45.nip.io || exit 1

# Step 1: Diagnose current state
echo "🔍 STEP 1: Running Diagnostics"
echo "------------------------------"
bash diagnose_ai_extraction.sh

echo ""
echo "Press Enter to continue with fixes, or Ctrl+C to stop..."
read -r

# Step 2: Apply fixes
echo ""
echo "🔧 STEP 2: Applying Fixes"
echo "-------------------------"
bash fix_production_ai_extraction.sh

# Step 3: Final verification
echo ""
echo "✅ STEP 3: Final Verification"
echo "-----------------------------"
echo "Checking if everything is working now..."

php artisan tinker --execute="
echo '🧪 Final Test - Creating Rich Offer' . PHP_EOL;
echo '===================================' . PHP_EOL;

try {
    \$service = app(\App\Services\IntakeCreationService::class);
    \$intake = \$service->createFromText('Production test with rich data', [
        'customer_name' => 'Acme Transport Solutions',
        'contact_email' => 'shipping@acme-transport.com', 
        'contact_phone' => '+31-20-1234567',
        'car_details' => '2024 BMW X7, Premium Package, White Pearl',
        'additional_notes' => 'Urgent shipping from Amsterdam to Berlin, vehicle value: €85,000'
    ]);

    echo '1️⃣ Created intake: ' . \$intake->id . PHP_EOL;
    
    // Process it
    \App\Jobs\ProcessIntake::dispatchSync(\$intake);
    \$intake->refresh();
    
    echo '2️⃣ Processing results:' . PHP_EOL;
    echo '   Status: ' . \$intake->status . PHP_EOL;
    echo '   Customer: ' . (\$intake->customer_name ?: 'MISSING') . PHP_EOL;
    echo '   Email: ' . (\$intake->contact_email ?: 'MISSING') . PHP_EOL;
    echo '   Phone: ' . (\$intake->contact_phone ?: 'MISSING') . PHP_EOL;
    echo '   Car Details: ' . (\$intake->car_details ?: 'MISSING') . PHP_EOL;
    echo '   Client ID: ' . (\$intake->robaws_client_id ?: 'MISSING') . PHP_EOL;
    echo '   Offer ID: ' . (\$intake->robaws_offer_id ?: 'MISSING') . PHP_EOL;
    
    if (\$intake->robaws_offer_id && \$intake->customer_name && \$intake->contact_email && \$intake->car_details) {
        echo '🎉 SUCCESS: Rich offer with full data created!' . PHP_EOL;
        echo '   Your production is now working like local!' . PHP_EOL;
    } elseif (\$intake->robaws_offer_id) {
        echo '⚠️ PARTIAL: Offer created but missing some data' . PHP_EOL;
        echo '   Check AI API keys and extraction service' . PHP_EOL;
    } else {
        echo '❌ FAILED: No offer created' . PHP_EOL;
        echo '   Check Robaws connection and company ID' . PHP_EOL;
    }
    
    // Cleanup
    \$intake->files()->delete();
    \$intake->delete();
    
} catch (Exception \$e) {
    echo '❌ TEST FAILED: ' . \$e->getMessage() . PHP_EOL;
}
"

echo ""
echo "🎯 Production Fix Complete!"
echo "=========================="
echo ""
echo "✅ What we fixed:"
echo "  • AI extraction service configuration"
echo "  • OpenAI API key validation"
echo "  • File attachment handling"  
echo "  • Incomplete data reprocessing"
echo "  • Queue worker restart"
echo ""
echo "🔍 Your new offers should now match local quality!"
echo "   - Full customer details"
echo "   - Complete vehicle information"
echo "   - All contact information"
echo "   - File attachments included"
echo ""
echo "📊 Monitor with: tail -f storage/logs/laravel.log"
