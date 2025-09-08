#!/bin/bash

echo "🔍 Bconnect Services Status Check"
echo "=================================="
echo ""

# Check Laravel Development Server
echo "📱 Laravel Development Server:"
SERVER_PROCESS=$(ps aux | grep "artisan serve" | grep -v grep)
if [ ! -z "$SERVER_PROCESS" ]; then
    echo "  ✅ Running on http://127.0.0.1:8000"
    echo "  🔍 Process: $SERVER_PROCESS"
    
    # Test if server responds
    if curl -s http://127.0.0.1:8000 > /dev/null; then
        echo "  🌐 HTTP Response: OK"
    else
        echo "  ⚠️  HTTP Response: Not responding"
    fi
else
    echo "  ❌ Not running"
fi

echo ""

# Check Horizon Queue Processor
echo "⚡ Horizon Queue Processor:"
HORIZON_PROCESS=$(ps aux | grep "artisan horizon" | grep -v grep)
if [ ! -z "$HORIZON_PROCESS" ]; then
    echo "  ✅ Running"
    echo "  🔍 Process: $HORIZON_PROCESS"
    
    # Check Horizon status via artisan
    HORIZON_STATUS=$(php artisan horizon:status 2>&1)
    if echo "$HORIZON_STATUS" | grep -q "started successfully"; then
        echo "  📊 Status: Active and processing jobs"
    else
        echo "  ⚠️  Status: $HORIZON_STATUS"
    fi
else
    echo "  ❌ Not running"
    echo "  💡 Start with: php artisan horizon"
fi

echo ""

# Check Queue Status
echo "📋 Queue Information:"
QUEUED_JOBS=$(php -r "
require_once 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo \Illuminate\Support\Facades\DB::table('jobs')->count();
")

FAILED_JOBS=$(php -r "
require_once 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo \Illuminate\Support\Facades\DB::table('failed_jobs')->count();
")

echo "  📥 Queued Jobs: $QUEUED_JOBS"
echo "  ❌ Failed Jobs: $FAILED_JOBS"

echo ""

# Check Recent Intakes
echo "📊 Recent Intake Status:"
php -r "
require_once 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

\$intakes = \App\Models\Intake::orderBy('created_at', 'desc')->limit(5)->get();
foreach (\$intakes as \$intake) {
    \$status = \$intake->status;
    \$robaws = \$intake->robaws_offer_id ?: 'none';
    \$icon = match(\$status) {
        'completed' => '✅',
        'pending' => '⏳',
        'export_queued' => '📤',
        'failed' => '❌',
        default => '📋'
    };
    echo \"  {\$icon} ID: {\$intake->id} | {\$status} | Robaws: {\$robaws}\n\";
}
"

echo ""
echo "🎯 Quick Actions:"
echo "  • View admin panel: open http://127.0.0.1:8000/admin"
echo "  • Monitor queues: open http://127.0.0.1:8000/horizon"
echo "  • Restart services: ./dev-start.sh"
