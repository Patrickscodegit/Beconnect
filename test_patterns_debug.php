<?php

// Test our patterns directly
$content = 'Die Daten:
Suzuki Samurai plus RS-Camp-Wohnwagenhänger, 1 Achse
800cm lang, 204cm breit, 232cm hoch
ca. 1,8t
ab Deutschland nach Mombasa oder Dar es Salaam';

echo "CONTENT TO TEST:\n";
echo $content . "\n\n";

// Test German dimensions pattern
$dimensionsPattern = '/(\d+)\s*cm\s+lang[,]?\s*(\d+)\s*cm\s+breit[,]?\s*(\d+)\s*cm\s+hoch/i';
echo "Testing German dimensions pattern: $dimensionsPattern\n";
if (preg_match($dimensionsPattern, $content, $matches)) {
    echo "✓ MATCHED!\n";
    print_r($matches);
} else {
    echo "✗ NO MATCH\n";
}

echo "\n" . str_repeat("-", 50) . "\n";

// Test German route pattern  
$routePattern = '/ab\s+([A-Za-zÄÖÜäöüß\s,\-\.]+?)\s+nach\s+([A-Za-zÄÖÜäöüß\s,\-\.]+?)(?:[.,;]|\s|$)/i';
echo "Testing German route pattern: $routePattern\n";
if (preg_match($routePattern, $content, $matches)) {
    echo "✓ MATCHED!\n";
    print_r($matches);
} else {
    echo "✗ NO MATCH\n";
}

echo "\n" . str_repeat("-", 50) . "\n";

// Test German destination options pattern
$destOptionsPattern = '/nach\s+([A-Za-zÄÖÜäöüß\s,\-\.]+?)\s+oder\s+([A-Za-zÄÖÜäöüß\s,\-\.]+?)(?:[.,;]|$)/i';
echo "Testing German destination options pattern: $destOptionsPattern\n";
if (preg_match($destOptionsPattern, $content, $matches)) {
    echo "✓ MATCHED!\n";
    print_r($matches);
} else {
    echo "✗ NO MATCH\n";
}

echo "\n" . str_repeat("-", 50) . "\n";

// Test vehicle in parentheses
$vehiclePattern = '/\(([^)]+)\)/i';
echo "Testing vehicle parentheses pattern: $vehiclePattern\n";
if (preg_match($vehiclePattern, $content, $matches)) {
    echo "✓ MATCHED!\n";
    print_r($matches);
} else {
    echo "✗ NO MATCH\n";
}
