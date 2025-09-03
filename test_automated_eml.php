<?php

require_once 'vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: __DIR__)
    ->withRouting(
        web: __DIR__.'/routes/web.php',
        commands: __DIR__.'/routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

echo "Testing automated .eml file upload to Robaws...\n\n";

// Find a BMW .eml test intake that hasn't been exported yet
$intake = \App\Models\Intake::where('project_reference', 'like', 'BMW%')
    ->whereNull('client')
    ->latest()
    ->first();

if (!$intake) {
    echo "❌ No BMW test intake found. Let's create one...\n";
    
    // Get or create test BMW .eml file
    $emlFile = '/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/bmw_serie7_french.eml';
    if (!file_exists($emlFile)) {
        echo "❌ BMW .eml test file not found at: $emlFile\n";
        exit;
    }
    
    // Create intake from .eml
    $creationService = new \App\Services\IntakeCreationService();
    $intake = $creationService->createFromEmail($emlFile);
    echo "✅ Created new test intake: {$intake->id}\n";
} else {
    echo "✅ Found existing test intake: {$intake->id}\n";
}

echo "Project Reference: {$intake->project_reference}\n";
echo "Status: {$intake->status}\n";

// Check if intake has files
$intakeFiles = $intake->files;
echo "Intake Files: " . $intakeFiles->count() . "\n";
foreach ($intakeFiles as $file) {
    echo "  - {$file->original_filename} ({$file->mime_type})\n";
}

// Check if intake has documents
$documents = $intake->documents;
echo "Documents: " . $documents->count() . "\n";
foreach ($documents as $doc) {
    echo "  - {$doc->original_filename} ({$doc->mime_type})\n";
}

// Now test export
echo "\n🚀 Starting export to Robaws...\n";

$exportService = new \App\Services\Robaws\RobawsExportService();

try {
    $result = $exportService->exportIntake($intake);
    
    if ($result['success']) {
        echo "✅ Export successful!\n";
        echo "Offer ID: {$result['offer_id']}\n";
        
        // Check if files were attached
        if (isset($result['attached_files'])) {
            echo "Attached Files: " . count($result['attached_files']) . "\n";
            foreach ($result['attached_files'] as $file) {
                echo "  - {$file['filename']} ({$file['type']})\n";
            }
        }
        
        // Refresh intake to see if client was set
        $intake->refresh();
        echo "Client ID: " . ($intake->client ?: 'Not set') . "\n";
        
    } else {
        echo "❌ Export failed: " . ($result['message'] ?? 'Unknown error') . "\n";
        if (isset($result['errors'])) {
            foreach ($result['errors'] as $error) {
                echo "  - $error\n";
            }
        }
    }
} catch (\Exception $e) {
    echo "❌ Exception during export: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n✅ Test completed.\n";
