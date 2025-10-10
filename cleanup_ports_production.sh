#!/bin/bash

# Cleanup Production Ports - Keep Only Relevant Ports
# This script removes unnecessary ports and keeps only the ones actually used

echo "🚢 Cleaning up production ports database..."

# Navigate to the application directory
cd /home/forge/bconnect.64.226.120.45.nip.io || {
    echo "❌ Failed to navigate to application directory"
    exit 1
}

echo "📊 Current port count:"
php artisan tinker --execute="echo 'Total ports: ' . App\Models\Port::count();"

echo ""
echo "🗑️  Removing unnecessary ports..."

# Keep only the 3 POL ports you want: Antwerp, Zeebrugge, Flushing
# And the POD ports that are actually used by your carriers

php artisan tinker --execute="
// Define the ports we want to keep
\$keepPorts = [
    // POLs (Ports of Loading)
    'ANR', // Antwerp, Belgium
    'ZEE', // Zeebrugge, Belgium  
    'FLU', // Flushing, Netherlands
    
    // PODs (Ports of Discharge) - Based on your carriers' actual routes
    'LOS', // Lagos, Nigeria
    'DKR', // Dakar, Senegal
    'ABJ', // Abidjan, Ivory Coast
    'CKY', // Conakry, Guinea
    'LFW', // Lomé, Togo
    'COO', // Cotonou, Benin
    'DLA', // Douala, Cameroon
    'PNR', // Pointe Noire, Congo
    'DAR', // Dar es Salaam, Tanzania
    'MBA', // Mombasa, Kenya
    'DUR', // Durban, South Africa
    'ELS', // East London, South Africa
    'PLZ', // Port Elizabeth, South Africa
    'WVB', // Walvis Bay, Namibia
];

// Count ports before deletion
\$beforeCount = App\Models\Port::count();
echo 'Ports before cleanup: ' . \$beforeCount . PHP_EOL;

// Delete ports not in the keep list
\$deletedCount = App\Models\Port::whereNotIn('code', \$keepPorts)->delete();
echo 'Deleted ports: ' . \$deletedCount . PHP_EOL;

// Count ports after deletion
\$afterCount = App\Models\Port::count();
echo 'Ports after cleanup: ' . \$afterCount . PHP_EOL;

// Show remaining ports
echo PHP_EOL . 'Remaining ports:' . PHP_EOL;
App\Models\Port::orderBy('name')->get(['name', 'country', 'code'])->each(function(\$port) {
    echo \$port->name . ', ' . \$port->country . ' (' . \$port->code . ')' . PHP_EOL;
});
"

echo ""
echo "🧹 Clearing caches..."
php artisan cache:clear
php artisan view:clear
php artisan config:clear

echo ""
echo "✅ Port cleanup completed!"
echo "   POL dropdown will now show only: Antwerp, Zeebrugge, Flushing"
echo "   POD dropdown will show only the relevant destination ports"
echo ""
echo "🔍 Test at: http://bconnect.64.226.120.45.nip.io/schedules"
