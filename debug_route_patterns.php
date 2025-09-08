<?php

$content = 'ab Deutschland nach Mombasa oder Dar es Salaam';

echo "CONTENT: '$content'\n\n";

// Current route pattern
$routePattern = '/ab\s+([A-Za-zÄÖÜäöüß\s,\-\.]+?)\s+nach\s+([A-Za-zÄÖÜäöüß\s,\-\.]+?)(?:[.,;]|\s|$)/i';
echo "Route pattern: $routePattern\n";
if (preg_match($routePattern, $content, $matches)) {
    echo "✓ MATCHED!\n";
    print_r($matches);
    echo "Origin: '{$matches[1]}'\n";
    echo "Destination: '{$matches[2]}'\n";
} else {
    echo "✗ NO MATCH\n";
}

echo "\n" . str_repeat("-", 50) . "\n";

// Destination options pattern
$destOptionsPattern = '/nach\s+([A-Za-zÄÖÜäöüß\s,\-\.]+?)\s+oder\s+([A-Za-zÄÖÜäöüß\s,\-\.]+?)(?:[.,;]|$)/i';
echo "Destination options pattern: $destOptionsPattern\n";
if (preg_match($destOptionsPattern, $content, $matches)) {
    echo "✓ MATCHED!\n";
    print_r($matches);
} else {
    echo "✗ NO MATCH\n";
}
