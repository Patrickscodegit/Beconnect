# Robaws Integration Diagnostic Recipe
# Run this in Laravel Forge to check why offers aren't being created

echo "🔍 Robaws Integration Diagnostics - Production"
echo "=============================================="
echo "Server: $(hostname)"
echo "Date: $(date)"
echo ""

# Change to application directory
cd /home/forge/{{ site }}

# Check if Laravel app exists
if [ ! -f "artisan" ]; then
    echo "❌ Laravel application not found in /home/forge/{{ site }}"
    exit 1
fi

echo "✅ Laravel application found"
echo ""

# 1. Check Robaws Configuration
echo "1️⃣ Checking Robaws Configuration..."
php artisan tinker --execute="
echo 'Base URL: ' . config('services.robaws.base_url') . PHP_EOL;
echo 'Auth Type: ' . config('services.robaws.auth') . PHP_EOL;
echo 'Username: ' . config('services.robaws.username') . PHP_EOL;
echo 'Password: ' . (config('services.robaws.password') ? '[SET]' : '[NOT SET]') . PHP_EOL;
echo 'Company ID: ' . config('services.robaws.company_id') . PHP_EOL;
"
echo ""

# 2. Test Robaws Connection
echo "2️⃣ Testing Robaws API Connection..."
php artisan tinker --execute="
try {
    \$client = app(App\Services\RobawsClient::class);
    \$result = \$client->testConnection();
    echo 'Connection: ' . (\$result ? '✅ SUCCESS' : '❌ FAILED') . PHP_EOL;
} catch (Exception \$e) {
    echo '❌ Connection failed: ' . \$e->getMessage() . PHP_EOL;
}
"
echo ""

# 3. Check Recent Intakes
echo "3️⃣ Checking Recent Intakes (last 10)..."
php artisan tinker --execute="
\$intakes = App\Models\Intake::orderBy('created_at', 'desc')->take(10)->get();
if (\$intakes->count() > 0) {
    echo 'Found ' . \$intakes->count() . ' recent intakes:' . PHP_EOL;
    foreach (\$intakes as \$intake) {
        echo '  ID: ' . \$intake->id . ' | Status: ' . \$intake->status . ' | Customer: ' . (\$intake->customer_name ?: 'NULL') . ' | Robaws ID: ' . (\$intake->robaws_offer_id ?: 'NULL') . ' | Created: ' . \$intake->created_at->format('M d H:i') . PHP_EOL;
    }
} else {
    echo '❌ No intakes found - this is likely the issue!' . PHP_EOL;
    echo '💡 File uploads may not be creating intake records' . PHP_EOL;
}
"
echo ""

# 4. Check Recent File Uploads
echo "4️⃣ Checking Recent File Uploads..."
php artisan tinker --execute="
\$files = App\Models\IntakeFile::orderBy('created_at', 'desc')->take(5)->get();
if (\$files->count() > 0) {
    echo 'Found ' . \$files->count() . ' recent files:' . PHP_EOL;
    foreach (\$files as \$file) {
        echo '  File: ' . \$file->filename . ' | Intake: ' . \$file->intake_id . ' | Size: ' . number_format(\$file->file_size) . ' bytes | Created: ' . \$file->created_at->format('M d H:i') . PHP_EOL;
    }
} else {
    echo '❌ No files uploaded recently' . PHP_EOL;
}
"
echo ""

# 5. Check Queue Status
echo "5️⃣ Checking Queue and Jobs..."
php artisan tinker --execute="
\$failed = DB::table('failed_jobs')->count();
if (\$failed > 0) {
    echo '❌ Failed jobs: ' . \$failed . PHP_EOL;
    \$recent = DB::table('failed_jobs')->orderBy('failed_at', 'desc')->take(3)->get(['payload', 'exception']);
    foreach (\$recent as \$job) {
        \$payload = json_decode(\$job->payload, true);
        \$jobName = \$payload['displayName'] ?? 'Unknown Job';
        echo '   • ' . \$jobName . PHP_EOL;
    }
} else {
    echo '✅ No failed jobs' . PHP_EOL;
}
"
echo ""

# 6. Test Complete Workflow
echo "6️⃣ Testing Complete Robaws Workflow..."
php artisan tinker --execute="
echo 'Creating test intake...' . PHP_EOL;
try {
    \$service = app(\App\Services\IntakeCreationService::class);
    \$intake = \$service->createFromText('Production workflow test', [
        'customer_name' => 'Carhanco',
        'contact_email' => 'test@carhanco.com',
        'source' => 'forge_diagnostic'
    ]);
    
    echo 'Test intake created: ' . \$intake->id . ' (Status: ' . \$intake->status . ')' . PHP_EOL;
    
    echo 'Processing with ProcessIntake job...' . PHP_EOL;
    \App\Jobs\ProcessIntake::dispatchSync(\$intake);
    
    \$intake->refresh();
    echo 'After processing:' . PHP_EOL;
    echo '  Status: ' . \$intake->status . PHP_EOL;
    echo '  Client ID: ' . (\$intake->robaws_client_id ?: 'NULL') . PHP_EOL;
    echo '  Robaws Offer ID: ' . (\$intake->robaws_offer_id ?: 'NULL') . PHP_EOL;
    
    if (\$intake->robaws_offer_id) {
        echo '🎉 SUCCESS: Robaws integration is working!' . PHP_EOL;
        echo '   Created offer: ' . \$intake->robaws_offer_id . PHP_EOL;
    } else {
        echo '❌ FAILED: No offer created' . PHP_EOL;
        if (\$intake->last_export_error) {
            echo '   Error: ' . \$intake->last_export_error . PHP_EOL;
        }
    }
    
    echo 'Cleaning up test data...' . PHP_EOL;
    \$intake->files()->delete();
    \$intake->delete();
    echo 'Test cleaned up' . PHP_EOL;
    
} catch (Exception \$e) {
    echo '❌ Workflow test failed: ' . \$e->getMessage() . PHP_EOL;
}
"
echo ""

# 7. Check Storage Configuration  
echo "7️⃣ Checking Storage Configuration..."
php artisan tinker --execute="
echo 'Default disk: ' . config('filesystems.default') . PHP_EOL;
echo 'Documents driver: ' . config('filesystems.disks.documents.driver') . PHP_EOL;
echo 'Documents disk working: ';
try {
    \Storage::disk('documents')->put('test.txt', 'test');
    \Storage::disk('documents')->delete('test.txt');
    echo '✅ YES' . PHP_EOL;
} catch (Exception \$e) {
    echo '❌ NO - ' . \$e->getMessage() . PHP_EOL;
}
"
echo ""

# 8. Summary
echo "🎯 DIAGNOSTIC SUMMARY"
echo "===================="
echo ""
echo "💡 If you see:"
echo "   • 'No intakes found' → File uploads aren't creating intakes"
echo "   • 'Connection failed' → Check Robaws credentials"
echo "   • 'Workflow test SUCCESS' → Integration works, check your upload interface"
echo "   • 'Workflow test FAILED' → Check the error message"
echo ""
echo "📋 Next steps:"
echo "   1. If no intakes: Check how you're uploading files"
echo "   2. If connection fails: Verify Robaws credentials in .env"
echo "   3. If workflow works: Your upload interface might not trigger intake creation"
echo ""
echo "🏁 Diagnostic complete!"
