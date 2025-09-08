<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Intake;
use App\Services\Robaws\RobawsExportService;

echo "=== Manual Export Test ===\n";

// Try to export intake 7
$intake = Intake::find(7);
if (!$intake) {
    echo "Intake 7 not found!\n";
    exit(1);
}

echo "Testing export for Intake 7:\n";
echo "Current extraction data: " . json_encode($intake->extraction_data, JSON_PRETTY_PRINT) . "\n";

$exportService = app(RobawsExportService::class);

try {
    $result = $exportService->exportIntake($intake, ['force' => true]);
    echo "\nExport result:\n";
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
} catch (\Throwable $e) {
    echo "Export failed with error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
