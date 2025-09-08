<?php

$content = 'ab Deutschland nach Mombasa oder Dar es Salaam';

echo "Testing specific German destination options pattern matching:\n\n";

// Test the exact pattern that should match
$pattern = '/nach\s+([A-Za-zÄÖÜäöüß\s,\-\.]+?)\s+oder\s+([A-Za-zÄÖÜäöüß\s,\-\.]+?)(?:[.,;\n]|$)/i';
echo "Pattern: $pattern\n";
echo "Content: '$content'\n\n";

if (preg_match($pattern, $content, $matches)) {
    echo "✓ MATCHED!\n";
    print_r($matches);
    echo "Option 1: '{$matches[1]}'\n";
    echo "Option 2: '{$matches[2]}'\n";
} else {
    echo "✗ NO MATCH\n";
    echo "This should not happen!\n";
}

echo "\n" . str_repeat("-", 50) . "\n";

// Test with exact email content
$emailContent = 'Guten Tag,

wir haben ein Suzuki Samurai plus Anhänger zu verschiffen. Vor ca. 1,5 Jahren habe ich bereits eine Anfrage gestellt wegen diesem Fahrzeug, leider ist der Verkauf damals nicht zustande gekommen. Jetzt ist es soweit.

Die Daten:
Suzuki Samurai plus RS-Camp-Wohnwagenhänger, 1 Achse
800cm lang, 204cm breit, 232cm hoch
ca. 1,8t
ab Deutschland nach Mombasa oder Dar es Salaam

Können Sie mir ein Angebot machen?

Vielen Dank vorab
Oliver Sielemann';

echo "\nTesting with full email content:\n";

if (preg_match($pattern, $emailContent, $matches)) {
    echo "✓ MATCHED in full email!\n";
    print_r($matches);
} else {
    echo "✗ NO MATCH in full email\n";
    echo "This is the problem!\n";
}
