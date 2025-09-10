<?php

// Quick test of the extraction pipeline
$data = [
    'raw_data' => [
        'company' => 'Armos BV',
        'vat' => '0437 311 533',
        'website' => 'www.armos.be',
        'address' => 'Kapelsesteenweg 611, B-2180 Antwerp (Ekeren), Belgium',
        'street' => 'Kapelsesteenweg 611',
        'city' => 'Antwerp (Ekeren)',
        'zip' => 'B-2180',
        'country' => 'Belgium',
        'phone' => '+32 (0)3 435 86 57',
        'mobile' => '+32 (0)476 72 02 16',
        'email' => 'nancy@armos.be'
    ],
    'contact' => [
        'name' => 'Nancy Deckers',
        'email' => 'nancy@armos.be',
        'company' => 'Armos BV'
    ]
];

// Extract values with our new logic
$rawData = $data['raw_data'] ?? [];
$contact = $data['contact'] ?? [];

$customerName = $rawData['company'] ?? $contact['company'] ?? $contact['name'] ?? 'Unknown';
$vatNumber = $rawData['vat'] ?? $rawData['vat_number'] ?? '';
if ($vatNumber && !str_starts_with($vatNumber, 'BE')) {
    $vatNumber = 'BE' . preg_replace('/[^0-9]/', '', $vatNumber);
}

$website = $rawData['website'] ?? '';
if ($website && !preg_match('/^https?:\/\//', $website)) {
    $website = 'www.' . ltrim($website, 'www.');
}

echo "Customer: $customerName\n";
echo "VAT: $vatNumber\n";
echo "Website: $website\n";
echo "Address: " . $rawData['address'] . "\n";

// Show what the placeholders would be
$placeholders = [
    'CLIENT' => $customerName,
    'CLIENT_VAT' => $vatNumber,
    'CLIENT_WEBSITE' => $website,
    'CLIENT_ADDRESS' => $rawData['address'],
    'CLIENT_TEL' => $rawData['phone'],
    'CONTACT_NAME' => $contact['name']
];

echo "\nPlaceholders:\n";
foreach ($placeholders as $key => $value) {
    echo "\${$key}: $value\n";
}
