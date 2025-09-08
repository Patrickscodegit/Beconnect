<?php

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel for logging
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$content = '800cm lang, 204cm breit, 232cm hoch';

echo "Testing dimensions extraction with: '$content'\n\n";

// Test the German dimensions pattern
$pattern = '/(\d+)\s*cm\s+lang[,]?\s*(\d+)\s*cm\s+breit[,]?\s*(\d+)\s*cm\s+hoch/i';
echo "Pattern: $pattern\n";

if (preg_match($pattern, $content, $matches)) {
    echo "✓ MATCHED!\n";
    print_r($matches);
    
    $length = (float) str_replace(',', '.', $matches[1]);
    $width = (float) str_replace(',', '.', $matches[2]);
    $height = (float) str_replace(',', '.', $matches[3]);
    $unit = 'cm';
    
    echo "Parsed:\n";
    echo "  Length: $length $unit\n";
    echo "  Width: $width $unit\n";
    echo "  Height: $height $unit\n";
    
    // Convert if necessary
    if ($unit === 'cm') {
        $length_cm = $length;
        $width_cm = $width;
        $height_cm = $height;
    }
    
    echo "Final dimensions:\n";
    echo "  length_cm: $length_cm\n";
    echo "  width_cm: $width_cm\n";
    echo "  height_cm: $height_cm\n";
    
} else {
    echo "✗ NO MATCH\n";
}
