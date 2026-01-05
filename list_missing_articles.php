<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$missingCodes = [
    'GANRDKRBV','GANRDKRCAR','GANRDKRSV','GANRDKRHH',
    'GANRDXBBV','GANRDXBCAR','GANRDXBSV','GANRDXBHH',
    'GANRFLUBV','GANRFLUCAR','GANRFLUSV','GANRFLUHH',
    'GANRJEDBV','GANRJEDCAR','GANRJEDSV','GANRJEDHH',
    'GANRLADBV','GANRLADCAR','GANRLADSV','GANRLADHH',
    'GANRLBVBV','GANRLBVCAR','GANRLBVSV','GANRLBVHH',
    'GANRLIBBV','GANRLIBCAR','GANRLIBSV','GANRLIBHH',
    'GANRMALBV','GANRMALCAR','GANRMALSV','GANRMALHH',
    'GANRNKCBV','GANRNKCCAR','GANRNKCSV','GANRNKCHH',
    'GANRPNRBV','GANRPNRCAR','GANRPNRSV','GANRPNRHH',
    'GANRROBBV','GANRROBCAR','GANRROBSV','GANROBHH',
    'GANRTEMBV','GANRTEMCAR','GANRTEMSV','GANRTEMHH',
    'GANRTFNBV','GANRTFNCAR','GANRTFNSV','GANRTFNHH',
    'GANRTKRBV','GANRTKRCAR','GANRTKRSV','GANRTKRHH',
];

$grouped = [];
foreach ($missingCodes as $code) {
    $port = substr($code, 4, 3); // GANR + 3 char port code
    if (!isset($grouped[$port])) {
        $grouped[$port] = [];
    }
    $grouped[$port][] = $code;
}

echo "=== MISSING ARTICLES IN PRODUCTION ===\n\n";
echo "Total missing: " . count($missingCodes) . " articles\n\n";

foreach ($grouped as $port => $codes) {
    $portModel = \App\Models\Port::where('code', $port)->first();
    $portName = $portModel ? $portModel->name : 'Unknown';
    
    echo "$port ($portName) - " . count($codes) . " articles:\n";
    foreach ($codes as $code) {
        echo "  - $code\n";
    }
    echo "\n";
}

