<?php

/**
 * Test Name-Only Client Resolution
 * 
 * Verifies that an intake with only a customer name (like "Carhanco") 
 * can be successfully processed and marked as ready for export.
 */

require_once __DIR__ . '/vendor/autoload.php';

// Test via Laravel Tinker since we need the full framework
$testScript = '
echo "🔧 TESTING NAME-ONLY CLIENT RESOLUTION\n";
echo "=====================================\n\n";

use App\Services\Robaws\ClientResolver;

// Test 1: Direct resolver test
echo "TEST 1: ClientResolver with name-only hints\n";
echo "-------------------------------------------\n";

$resolver = app(ClientResolver::class);
$result = $resolver->resolve([\'name\' => \'Carhanco\']);

if ($result) {
    echo "✅ Name-only resolution works!\n";
    echo "   Client ID: {$result[\'id\']}\n";
    echo "   Confidence: {$result[\'confidence\']}\n";
} else {
    echo "❌ Name-only resolution failed\n";
}

echo "\n";

// Test 2: Simulate intake processing
echo "TEST 2: Simulated intake processing\n";
echo "-----------------------------------\n";

// Simulate what ProcessIntake does
$hints = [
    "id" => null,
    "email" => null, 
    "phone" => null,
    "name" => "Carhanco"
];

$hit = $resolver->resolve($hints);

if ($hit) {
    echo "✅ ProcessIntake simulation successful!\n";
    echo "   Would set robaws_client_id = {$hit[\'id\']}\n";
    echo "   Would set status = processed\n";
    echo "   Ready for export without email/phone!\n";
} else {
    echo "❌ ProcessIntake simulation failed\n";
}

echo "\n🎯 RESULT: Name-only client resolution " . ($hit ? "WORKS" : "NEEDS FIXING") . "!\n";

exit();
';

echo "Running name-only resolution test...\n";
echo "=====================================\n";

// Save test script and run it
file_put_contents('name_only_test.php', "<?php\n" . $testScript);

system('cd /Users/patrickhome/Documents/Robaws2025_AI/Bconnect && php artisan tinker --execute="require \'name_only_test.php\';"');

unlink('name_only_test.php');

echo "\n✅ Test complete! If successful, Carhanco alone should be sufficient.\n";
