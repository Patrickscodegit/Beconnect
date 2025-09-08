<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Intake;

echo "=== Analyzing Extraction Data Patterns ===\n";

$intakes = Intake::orderBy('id')->get(['id', 'customer_name', 'contact_email', 'contact_phone', 'extraction_data']);

$emails = [];
$contactData = [];

foreach ($intakes as $intake) {
    echo "\nIntake {$intake->id}:\n";
    echo "  Customer: " . ($intake->customer_name ?: 'None') . "\n";
    echo "  Email: " . ($intake->contact_email ?: 'None') . "\n";
    echo "  Phone: " . ($intake->contact_phone ?: 'None') . "\n";
    
    if ($intake->extraction_data) {
        $contact = $intake->extraction_data['contact'] ?? null;
        if (!empty($contact)) {
            echo "  Extracted Contact: " . json_encode($contact) . "\n";
            if (isset($contact['email'])) {
                $emails[] = $contact['email'];
            }
            $contactData[] = $contact;
        } else {
            echo "  Extracted Contact: Empty or none\n";
        }
    } else {
        echo "  Extraction Data: None\n";
    }
}

echo "\n=== Email Pattern Analysis ===\n";
$emailCounts = array_count_values($emails);
foreach ($emailCounts as $email => $count) {
    echo "Email '$email' appears $count times\n";
}

echo "\n=== Unique Contact Patterns ===\n";
$uniqueContacts = array_unique(array_map('json_encode', $contactData));
foreach ($uniqueContacts as $contact) {
    echo "Pattern: $contact\n";
}
