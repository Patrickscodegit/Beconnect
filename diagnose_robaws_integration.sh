#!/bin/bash

# Robaws Integration Diagnostic Script
# Check why offers aren't being created

echo "🔍 Robaws Integration Diagnostics"
echo "================================="

# Check if we're in Laravel app
if [ ! -f "artisan" ]; then
    echo "❌ Not in Laravel directory"
    exit 1
fi

echo "✅ Laravel app detected"
echo ""

# 1. Check Robaws Configuration
echo "1️⃣ Checking Robaws Configuration..."
php artisan tinker --execute="
echo 'Base URL: '.config('services.robaws.base_url').PHP_EOL;
echo 'Auth Type: '.config('services.robaws.auth').PHP_EOL;
echo 'Username: '.config('services.robaws.username').PHP_EOL;
echo 'Password: '.(config('services.robaws.password') ? '[SET]' : '[NOT SET]').PHP_EOL;
echo 'Company ID: '.config('services.robaws.company_id').PHP_EOL;
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
echo "3️⃣ Checking Recent Intakes..."
php artisan tinker --execute="
\$recent = App\Models\Intake::orderBy('created_at', 'desc')->take(3)->get(['id', 'status', 'robaws_quotation_id', 'created_at']);
echo 'Recent Intakes:' . PHP_EOL;
foreach (\$recent as \$intake) {
    echo '  ID: ' . \$intake->id . ' | Status: ' . \$intake->status . ' | Robaws ID: ' . (\$intake->robaws_quotation_id ?: 'NULL') . ' | Created: ' . \$intake->created_at . PHP_EOL;
}
"

echo ""

# 4. Check Queue Status
echo "4️⃣ Checking Queue Status..."
php artisan queue:work --once --quiet 2>/dev/null &
QUEUE_PID=$!
sleep 2
kill $QUEUE_PID 2>/dev/null

php artisan tinker --execute="
\$failed = DB::table('failed_jobs')->count();
echo 'Failed Jobs: ' . \$failed . PHP_EOL;
if (\$failed > 0) {
    \$recent = DB::table('failed_jobs')->orderBy('failed_at', 'desc')->take(3)->get(['payload', 'exception']);
    foreach (\$recent as \$job) {
        \$payload = json_decode(\$job->payload, true);
        echo '  Failed: ' . (\$payload['displayName'] ?? 'Unknown Job') . PHP_EOL;
    }
}
"

echo ""

# 5. Check ProcessIntake Job
echo "5️⃣ Testing ProcessIntake Job Manually..."
php artisan tinker --execute="
\$lastIntake = App\Models\Intake::orderBy('created_at', 'desc')->first();
if (\$lastIntake) {
    echo 'Testing ProcessIntake for ID: ' . \$lastIntake->id . PHP_EOL;
    try {
        \$job = new App\Jobs\ProcessIntake(\$lastIntake);
        \$job->handle();
        echo '✅ ProcessIntake job executed successfully' . PHP_EOL;
    } catch (Exception \$e) {
        echo '❌ ProcessIntake failed: ' . \$e->getMessage() . PHP_EOL;
    }
} else {
    echo '❌ No intakes found to test' . PHP_EOL;
}
"

echo ""

# 6. Check Observers
echo "6️⃣ Checking Model Observers..."
php artisan tinker --execute="
echo 'Intake Observers: ';
\$observers = [];
foreach (App\Models\Intake::getObservableEvents() as \$event) {
    if (App\Models\Intake::hasObserver(\$event)) {
        \$observers[] = \$event;
    }
}
echo implode(', ', \$observers) . PHP_EOL;
"

echo ""

# 7. Check Last Robaws Activity
echo "7️⃣ Checking Last Robaws Activity in Logs..."
if [ -f "storage/logs/laravel.log" ]; then
    echo "Recent Robaws-related log entries:"
    grep -i "robaws\|quotation\|offer" storage/logs/laravel.log | tail -5 || echo "No Robaws activity found in logs"
else
    echo "No log file found"
fi

echo ""
echo "🎯 Diagnostic Complete!"
echo ""
echo "💡 Common Issues:"
echo "• Check if ProcessIntake job is dispatched after file upload"
echo "• Verify Robaws credentials are correct"
echo "• Ensure queue worker is running (php artisan queue:work)"
echo "• Check if extraction data is being created"
echo "• Verify document processing pipeline is complete"
