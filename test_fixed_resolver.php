<?php
require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Testing fixed ClientResolver...\n";

$resolver = app(\App\Services\Robaws\ClientResolver::class);
$result = $resolver->resolve(['name' => '2 Connect Logistics BV']);

if ($result) {
    echo "✅ SUCCESS: Found client ID {$result['id']} with confidence {$result['confidence']}\n";
} else {
    echo "❌ FAILED: No client found\n";
}

// Test with partial name
$result2 = $resolver->resolve(['name' => 'Connect Logistics']);
if ($result2) {
    echo "✅ PARTIAL SUCCESS: Found client ID {$result2['id']} with confidence {$result2['confidence']}\n";
} else {
    echo "❌ PARTIAL FAILED: No client found\n";
}

echo "Test complete.\n";
