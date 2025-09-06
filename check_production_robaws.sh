#!/bin/bash

# Production Status Check - Run this on your server to see current state

echo "🔍 Production Robaws Status Check"
echo "=================================="

# Check recent intakes
echo "1️⃣ Recent Intakes (last 10):"
php artisan tinker --execute="
\$intakes = App\Models\Intake::orderBy('created_at', 'desc')->take(10)->get();
if (\$intakes->count() > 0) {
    foreach (\$intakes as \$intake) {
        echo '  ID: ' . \$intake->id . ' | Status: ' . \$intake->status . ' | Customer: ' . (\$intake->customer_name ?: 'NULL') . ' | Robaws: ' . (\$intake->robaws_offer_id ?: 'NULL') . ' | Created: ' . \$intake->created_at . PHP_EOL;
    }
} else {
    echo '  ❌ No intakes found' . PHP_EOL;
}
"

echo ""

# Check queue status
echo "2️⃣ Queue Status:"
php artisan queue:work --once --timeout=5 --quiet > /dev/null 2>&1 &
QUEUE_PID=$!
sleep 3
kill $QUEUE_PID 2>/dev/null
echo "✅ Queue worker test completed"

# Check failed jobs
echo ""
echo "3️⃣ Failed Jobs:"
php artisan tinker --execute="
\$failed = DB::table('failed_jobs')->count();
if (\$failed > 0) {
    echo '❌ Failed jobs: ' . \$failed . PHP_EOL;
    \$recent = DB::table('failed_jobs')->orderBy('failed_at', 'desc')->take(3)->get(['payload', 'exception']);
    foreach (\$recent as \$job) {
        \$payload = json_decode(\$job->payload, true);
        echo '   • ' . (\$payload['displayName'] ?? 'Unknown Job') . PHP_EOL;
    }
} else {
    echo '✅ No failed jobs' . PHP_EOL;
}
"

echo ""

# Check recent file uploads  
echo "4️⃣ Recent IntakeFiles (last 5):"
php artisan tinker --execute="
\$files = App\Models\IntakeFile::orderBy('created_at', 'desc')->take(5)->get();
if (\$files->count() > 0) {
    foreach (\$files as \$file) {
        echo '  File: ' . \$file->filename . ' | Intake: ' . \$file->intake_id . ' | Size: ' . number_format(\$file->file_size) . ' bytes | Created: ' . \$file->created_at . PHP_EOL;
    }
} else {
    echo '  ❌ No files uploaded recently' . PHP_EOL;
}
"

echo ""

# Test the upload workflow
echo "5️⃣ Testing Complete Workflow:"
php artisan tinker --execute="
echo '   Creating test intake...' . PHP_EOL;
\$service = app(\App\Services\IntakeCreationService::class);
\$intake = \$service->createFromText('Production test', [
    'customer_name' => 'Test Customer',
    'contact_email' => 'test@example.com'
]);

echo '   Processing...' . PHP_EOL;
\App\Jobs\ProcessIntake::dispatchSync(\$intake);

\$intake->refresh();
if (\$intake->robaws_offer_id) {
    echo '   ✅ SUCCESS: Offer created - ' . \$intake->robaws_offer_id . PHP_EOL;
} else {
    echo '   ❌ FAILED: No offer created' . PHP_EOL;
    echo '   Status: ' . \$intake->status . PHP_EOL;
    echo '   Error: ' . (\$intake->last_export_error ?: 'None') . PHP_EOL;
}

\$intake->files()->delete();
\$intake->delete();
echo '   Test cleaned up' . PHP_EOL;
"

echo ""
echo "🎯 Diagnosis Complete!"
echo ""
echo "💡 If workflow test passes but you're not seeing offers:"
echo "   • Files may not be converting to intakes"  
echo "   • Check your upload interface"
echo "   • Verify queue workers are running"
echo "   • Check logs for errors during real uploads"
