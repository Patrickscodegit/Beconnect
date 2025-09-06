#!/bin/bash

echo "🚀 Enabling Auto-Export to Robaws"
echo "=================================="
echo

cd /home/forge/bconnect.64.226.120.45.nip.io || exit 1

# 1. Pull latest code
echo "1️⃣ Pulling latest code..."
git pull origin main

# 2. Run migrations
echo "2️⃣ Running migrations..."
php artisan migrate --force

# 3. Clear caches
echo "3️⃣ Clearing caches..."
php artisan config:clear
php artisan cache:clear
php artisan queue:restart

# 4. Set ROBAWS_COMPANY_ID if not set
echo "4️⃣ Checking ROBAWS_COMPANY_ID..."
if ! grep -q "^ROBAWS_COMPANY_ID=" .env; then
    echo "ROBAWS_COMPANY_ID=1" >> .env
    echo "✅ Added ROBAWS_COMPANY_ID=1"
else
    echo "✅ ROBAWS_COMPANY_ID already set"
fi

# 5. Cache config
echo "5️⃣ Caching configuration..."
php artisan config:cache

# 6. Reprocess all pending/needs_contact intakes
echo "6️⃣ Reprocessing pending intakes..."
php artisan tinker --execute="
\$intakes = App\Models\Intake::whereIn('status', ['pending', 'needs_contact', 'failed'])
    ->whereNull('robaws_offer_id')
    ->get();
    
echo 'Found ' . \$intakes->count() . ' intakes to reprocess' . PHP_EOL;

foreach (\$intakes as \$intake) {
    echo 'Processing intake #' . \$intake->id . '...' . PHP_EOL;
    App\Jobs\ProcessIntake::dispatch(\$intake);
}

echo '✅ All intakes queued for processing' . PHP_EOL;
"

# 7. Start queue worker for immediate processing
echo "7️⃣ Processing queue..."
timeout 30 php artisan queue:work --stop-when-empty --timeout=60

echo
echo "✅ Auto-Export Enabled!"
echo "=================================="
echo
echo "Changes applied:"
echo "• Contact info is no longer mandatory"
echo "• System will auto-create clients if not found"
echo "• All pending intakes have been requeued"
echo
echo "Your intakes should now export automatically!"
