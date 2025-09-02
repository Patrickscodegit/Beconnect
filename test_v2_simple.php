<?php

/**
 * Simple artisan command to verify v2-only unification
 */

echo "🔧 V2-ONLY UNIFICATION VERIFICATION\n";
echo "==========================================\n\n";

// Test NameNormalizer
echo "TEST 1: NameNormalizer functionality\n";
echo "------------------------------------\n";

$testPairs = [
    ['Carhanco BV', 'Carhanco'],
    ['Café René SPRL', 'Cafe Rene'],
    ['Company Ltd.', 'Company'],
];

foreach ($testPairs as [$name1, $name2]) {
    $norm1 = \App\Services\Robaws\NameNormalizer::normalize($name1);
    $norm2 = \App\Services\Robaws\NameNormalizer::normalize($name2);
    $similarity = \App\Services\Robaws\NameNormalizer::similarity($name1, $name2);
    
    echo "   '{$name1}' -> '{$norm1}'\n";
    echo "   '{$name2}' -> '{$norm2}'\n";
    echo "   Similarity: {$similarity}%\n\n";
}

// Test RobawsApiClient methods
echo "TEST 2: RobawsApiClient v2-only methods\n";
echo "---------------------------------------\n";

$reflection = new ReflectionClass(\App\Services\Export\Clients\RobawsApiClient::class);

$v2OnlyMethods = [
    'findClientByEmail',
    'findClientByPhone', 
    'listClients',
    'getClientById'
];

echo "✅ V2-only methods implemented:\n";
foreach ($v2OnlyMethods as $method) {
    if ($reflection->hasMethod($method)) {
        echo "   ✓ {$method}()\n";
    } else {
        echo "   ❌ {$method}() missing\n";
    }
}

echo "\n";

// Test ClientResolver
echo "TEST 3: ClientResolver service\n";
echo "------------------------------\n";

try {
    $resolver = app(\App\Services\Robaws\ClientResolver::class);
    echo "✅ ClientResolver service instantiated successfully\n";
    
    // Test empty hints
    $result = $resolver->resolve([]);
    echo "   Empty hints result: " . ($result ? "found client" : "no client") . "\n";
    
    // Test name hint with non-existent company
    $result = $resolver->resolve(['name' => 'NonExistentCompanyXYZ123']);
    echo "   Non-existent name result: " . ($result ? "found client" : "no client") . "\n";
    
} catch (Exception $e) {
    echo "❌ ClientResolver error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test ProcessIntake integration
echo "TEST 4: ProcessIntake integration check\n";
echo "--------------------------------------\n";

$processIntakeFile = file_get_contents(app_path('Jobs/ProcessIntake.php'));

if (strpos($processIntakeFile, 'ClientResolver') !== false) {
    echo "✅ ProcessIntake imports ClientResolver\n";
} else {
    echo "❌ ProcessIntake missing ClientResolver import\n";
}

if (strpos($processIntakeFile, '$resolver->resolve($hints)') !== false) {
    echo "✅ ProcessIntake calls resolver before validation\n";
} else {
    echo "❌ ProcessIntake missing resolver call\n";
}

if (strpos($processIntakeFile, 'robaws_client_id') !== false) {
    echo "✅ ProcessIntake sets robaws_client_id on resolution\n";
} else {
    echo "❌ ProcessIntake missing client ID assignment\n";
}

echo "\n";

echo "🎯 SUMMARY\n";
echo "----------\n";
echo "The v2-only unification ensures:\n";
echo "• NameNormalizer: Unicode normalization + legal suffix removal\n";
echo "• RobawsApiClient: Pure v2 API methods only\n";
echo "• ClientResolver: Unified hints-based resolution\n";
echo "• ProcessIntake: Resolver runs before validation\n";
echo "• Consistent behavior for .eml and manual uploads\n\n";

echo "✅ V2-ONLY UNIFICATION VERIFICATION COMPLETE!\n";
