<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Robaws Configuration Check ===\n\n";

echo "Robaws Configuration:\n";
echo "  Base URL: " . (config('services.robaws.base_url') ?: 'NOT SET') . "\n";
echo "  API Key: " . (config('services.robaws.api_key') ? 'SET' : 'NOT SET') . "\n";
echo "  Company ID: " . (config('services.robaws.company_id') ?: 'NOT SET') . "\n";
echo "  Default Company ID: " . (config('services.robaws.default_company_id') ?: 'NOT SET') . "\n";

// Check environment file
if (file_exists('.env')) {
    echo "\nEnvironment variables:\n";
    $envContent = file_get_contents('.env');
    $robawsLines = array_filter(explode("\n", $envContent), function($line) {
        return strpos($line, 'ROBAWS_') === 0;
    });
    
    if (empty($robawsLines)) {
        echo "  ❌ No ROBAWS_ environment variables found\n";
    } else {
        foreach ($robawsLines as $line) {
            if (strpos($line, 'API_KEY') !== false || strpos($line, 'TOKEN') !== false) {
                echo "  " . substr($line, 0, strpos($line, '=') + 1) . "***\n";
            } else {
                echo "  $line\n";
            }
        }
    }
} else {
    echo "\n❌ .env file not found\n";
}

echo "\n=== Configuration Check Complete ===\n";
