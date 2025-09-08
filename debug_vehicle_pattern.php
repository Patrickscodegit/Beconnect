<?php

$content = 'wir haben ein Suzuki Samurai plus Anhänger zu verschiffen';

echo "Testing vehicle pattern:\n";
echo "Content: '$content'\n\n";

$pattern = '/\b([A-Z][a-z]+\s+[A-Z][a-z]+)\s+plus\b/i';
echo "Pattern: $pattern\n";

if (preg_match($pattern, $content, $matches)) {
    echo "✓ MATCHED!\n";
    print_r($matches);
    
    $vehicleName = trim($matches[1]);
    $nameParts = explode(' ', $vehicleName);
    
    echo "Vehicle name: '$vehicleName'\n";
    echo "Name parts:\n";
    print_r($nameParts);
    
    if (count($nameParts) >= 2) {
        $brand = $nameParts[0];
        $model = implode(' ', array_slice($nameParts, 1));
        echo "Brand: '$brand'\n";
        echo "Model: '$model'\n";
    }
} else {
    echo "✗ NO MATCH\n";
}
