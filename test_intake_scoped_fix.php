<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Foundation\Application;

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔧 Testing Intake-Scoped Deduplication Fix\n";
echo str_repeat("=", 50) . "\n";

// Check the current state of intakes 4 and 6
$intake4 = App\Models\Intake::find(4);
$intake6 = App\Models\Intake::find(6);

if (!$intake4 || !$intake6) {
    echo "❌ Could not find intakes 4 and 6\n";
    exit(1);
}

echo "📋 Intake Status:\n";
echo "- Intake #4: {$intake4->documents()->count()} documents\n";
echo "- Intake #6: {$intake6->documents()->count()} documents\n";

// Check document details
$doc4 = $intake4->documents()->first();
$doc6 = $intake6->documents()->first();

if ($doc4 && $doc6) {
    echo "\n🔍 Document Analysis:\n";
    echo "- Doc #4 (Intake 4): Status = {$doc4->processing_status}, SHA = " . substr($doc4->source_content_sha ?? 'null', 0, 8) . "\n";
    echo "- Doc #6 (Intake 6): Status = {$doc6->processing_status}, SHA = " . substr($doc6->source_content_sha ?? 'null', 0, 8) . "\n";
    
    // Test duplicate check with intake scoping
    $service = app(App\Services\EmailDocumentService::class);
    
    // Get the email content for testing
    if ($doc4->storage_path && $doc6->storage_path) {
        $storage4 = Storage::disk($doc4->storage_disk ?? 'documents');
        $storage6 = Storage::disk($doc6->storage_disk ?? 'documents');
        
        if ($storage4->exists($doc4->storage_path) && $storage6->exists($doc6->storage_path)) {
            $content4 = $storage4->get($doc4->storage_path);
            $content6 = $storage6->get($doc6->storage_path);
            
            // Test same content across different intakes
            $dup4in4 = $service->isDuplicate($content4, 4);
            $dup4in6 = $service->isDuplicate($content4, 6);
            
            echo "\n🧪 Deduplication Test Results:\n";
            echo "- Same email in Intake #4: " . ($dup4in4['is_duplicate'] ? '✅ DUPLICATE' : '❌ NOT DUPLICATE') . "\n";
            echo "- Same email in Intake #6: " . ($dup4in6['is_duplicate'] ? '❌ DUPLICATE' : '✅ NOT DUPLICATE') . "\n";
            
            // Check if Extract Data button should be visible
            $shouldShowButton4 = $intake4->documents()->exists() && !$dup4in4['is_duplicate'];
            $shouldShowButton6 = $intake6->documents()->exists() && !$dup4in6['is_duplicate'];
            
            echo "\n🔘 Extract Data Button Visibility:\n";
            echo "- Intake #4: " . ($shouldShowButton4 ? '✅ SHOULD SHOW' : '❌ HIDDEN') . "\n";
            echo "- Intake #6: " . ($shouldShowButton6 ? '✅ SHOULD SHOW' : '❌ HIDDEN') . "\n";
            
            if ($shouldShowButton6) {
                echo "\n🎉 SUCCESS: Intake #6 should now show the Extract Data button!\n";
                echo "👉 The intake-scoped deduplication fix is working correctly.\n";
            } else {
                echo "\n⚠️  Issue: Intake #6 still shows as duplicate. Check the implementation.\n";
            }
        } else {
            echo "❌ Could not read email files from storage\n";
        }
    } else {
        echo "❌ Storage paths not available for documents\n";
    }
} else {
    echo "❌ Could not find documents in both intakes\n";
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "✅ Test completed\n";
