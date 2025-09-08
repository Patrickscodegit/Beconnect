<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Models\Intake;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "🔄 Testing Auto-Refresh Functionality\n";
echo "=====================================\n\n";

// Find a test intake to modify
$intake = Intake::latest()->first();

if (!$intake) {
    echo "❌ No intakes found to test with\n";
    exit(1);
}

echo "📋 Found intake ID: {$intake->id}\n";
echo "📊 Current status: {$intake->status}\n";
echo "👤 Customer: {$intake->customer_name}\n\n";

// Simulate status changes every 3 seconds
$statuses = ['processing', 'processed', 'completed'];
$originalStatus = $intake->status;

echo "🚀 Starting status simulation (Ctrl+C to stop)...\n";
echo "📱 Open the admin panel to see real-time updates: http://127.0.0.1:8000/admin/intakes\n\n";

$iteration = 0;
while (true) {
    $iteration++;
    $newStatus = $statuses[($iteration - 1) % count($statuses)];
    
    // Update the status
    $intake->update([
        'status' => $newStatus,
        'updated_at' => now()
    ]);
    
    echo "[" . now()->format('H:i:s') . "] 🔄 Changed intake {$intake->id} status to: {$newStatus}\n";
    
    // Wait 3 seconds
    sleep(3);
    
    // After 5 iterations, restore original status and exit
    if ($iteration >= 5) {
        $intake->update(['status' => $originalStatus]);
        echo "\n✅ Test completed! Restored original status: {$originalStatus}\n";
        echo "🎯 You should have seen the status changes automatically refresh in the admin panel\n";
        break;
    }
}
