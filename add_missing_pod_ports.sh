#!/bin/bash

# Add Missing POD Ports to Production
# This script adds the 10 missing POD ports to match your local environment

echo "🚢 Adding missing POD ports to production..."

# Navigate to the application directory
cd /home/forge/bconnect.64.226.120.45.nip.io || {
    echo "❌ Failed to navigate to application directory"
    exit 1
}

echo "📊 Current port count:"
php artisan tinker --execute="echo 'Total ports: ' . App\Models\Port::count();"

echo ""
echo "🌱 Adding missing POD ports..."

php artisan tinker --execute="
// Add the missing POD ports to match your local environment
\$missingPorts = [
    ['code' => 'ABJ', 'name' => 'Abidjan', 'country' => 'Côte d\'Ivoire', 'region' => 'Africa', 'type' => 'pod'],
    ['code' => 'CKY', 'name' => 'Conakry', 'country' => 'Guinea', 'region' => 'Africa', 'type' => 'pod'],
    ['code' => 'COO', 'name' => 'Cotonou', 'country' => 'Benin', 'region' => 'Africa', 'type' => 'pod'],
    ['code' => 'DKR', 'name' => 'Dakar', 'country' => 'Senegal', 'region' => 'Africa', 'type' => 'pod'],
    ['code' => 'DAR', 'name' => 'Dar es Salaam', 'country' => 'Tanzania', 'region' => 'Africa', 'type' => 'pod'],
    ['code' => 'DLA', 'name' => 'Douala', 'country' => 'Cameroon', 'region' => 'Africa', 'type' => 'pod'],
    ['code' => 'ELS', 'name' => 'East London', 'country' => 'South Africa', 'region' => 'Africa', 'type' => 'pod'],
    ['code' => 'LFW', 'name' => 'Lomé', 'country' => 'Togo', 'region' => 'Africa', 'type' => 'pod'],
    ['code' => 'PLZ', 'name' => 'Port Elizabeth', 'country' => 'South Africa', 'region' => 'Africa', 'type' => 'pod'],
    ['code' => 'WVB', 'name' => 'Walvis Bay', 'country' => 'Namibia', 'region' => 'Africa', 'type' => 'pod'],
];

\$addedCount = 0;
foreach (\$missingPorts as \$portData) {
    \$port = App\Models\Port::updateOrCreate(
        ['code' => \$portData['code']],
        \$portData
    );
    if (\$port->wasRecentlyCreated) {
        \$addedCount++;
        echo 'Added: ' . \$portData['name'] . ', ' . \$portData['country'] . ' (' . \$portData['code'] . ')' . PHP_EOL;
    } else {
        echo 'Updated: ' . \$portData['name'] . ', ' . \$portData['country'] . ' (' . \$portData['code'] . ')' . PHP_EOL;
    }
}

echo PHP_EOL . 'Added ' . \$addedCount . ' new ports' . PHP_EOL;
echo 'Total ports now: ' . App\Models\Port::count() . PHP_EOL;
"

echo ""
echo "🧹 Clearing caches..."
php artisan cache:clear
php artisan view:clear
php artisan config:clear

echo ""
echo "✅ Missing POD ports added!"
echo "   Production POD dropdown should now match your local (14 ports)"
echo ""
echo "🔍 Test at: http://bconnect.64.226.120.45.nip.io/schedules"
