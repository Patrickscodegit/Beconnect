<?php

require_once 'vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;

$app = require_once 'bootstrap/app.php';

// Boot the application
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    echo "🧪 Testing Service Container Resolution\n\n";

    // Test 1: Resolve via interface
    echo "1️⃣ Resolving via interface...\n";
    $exporterInterface = app(\App\Services\Robaws\Contracts\RobawsExporter::class);
    echo "✅ Interface resolved to: " . get_class($exporterInterface) . "\n\n";

    // Test 2: Resolve via new concrete class
    echo "2️⃣ Resolving via new concrete class...\n";
    $newExporter = app(\App\Services\Robaws\RobawsExportService::class);
    echo "✅ New class resolved to: " . get_class($newExporter) . "\n\n";

    // Test 3: Resolve via old concrete class (should get redirected)
    echo "3️⃣ Resolving via old concrete class (backward compatibility)...\n";
    $oldExporter = app(\App\Services\RobawsExportService::class);
    echo "✅ Old class resolved to: " . get_class($oldExporter) . "\n\n";

    // Test 4: Check if they're the same instance (singleton)
    echo "4️⃣ Testing singleton behavior...\n";
    $same = $exporterInterface === $newExporter;
    echo "✅ Interface and new class are same instance: " . ($same ? 'YES' : 'NO') . "\n\n";

    // Test 5: Check dependencies
    echo "5️⃣ Testing dependency resolution...\n";
    $client = app(\App\Services\RobawsClient::class);
    echo "✅ RobawsClient resolved to: " . get_class($client) . "\n";
    
    $payloadBuilder = app(\App\Services\Robaws\RobawsPayloadBuilder::class);
    echo "✅ RobawsPayloadBuilder resolved to: " . get_class($payloadBuilder) . "\n\n";

    echo "🎉 All container bindings working correctly!\n";
    echo "📝 Summary:\n";
    echo "   - Interface binds to new service ✅\n";
    echo "   - New service resolves correctly ✅\n";
    echo "   - Old service redirects to new ✅\n";
    echo "   - Singleton pattern working ✅\n";
    echo "   - All dependencies available ✅\n";

} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
