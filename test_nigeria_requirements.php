<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Models\Intake\Intake;
use App\Models\Intake\Document;
use App\Services\Extraction\Strategies\PatternExtractor;
use App\Services\Export\Mappers\RobawsMapper;
use App\Services\VehicleDatabase\VehicleDatabaseService;

echo "Nigeria Requirements Validation Test\n";
echo "===================================\n\n";

// Lagos Nigeria email content
$lagosBmwContent = 'Date: Sun, 8 Sep 2024 10:30:00 +0200
From: info@africa-shipping.com
To: robaws@example.com
Subject: Re: BMW 7 Series Export - Lagos Nigeria

Dear Team,

We are proceeding with the export of the BMW 7 series to Lagos, Nigeria.

Destination: TIN-CAN PORT, Lagos Nigeria
Vehicle Details: Used MAN Truck
Reference: EXP RORO - ANR - LAGOS - 1 x used MAN Truck

Please confirm the documentation.

Best regards,
African Shipping Services';

// Create intake and document
$intake = new Intake();
$intake->id = 99999;
$intake->status = 'pending';

$document = new Document();
$document->id = 99999;
$document->intake_id = $intake->id;
$document->mime_type = 'message/rfc822';
$document->content = $lagosBmwContent;

// Create instances
$vehicleService = new VehicleDatabaseService();
$patternExtractor = new PatternExtractor($vehicleService);
$mapper = new RobawsMapper();

try {
    // Extract data
    echo "Extracting data from Lagos email...\n";
    $extractedData = $patternExtractor->extract($document);

    echo "Extracted data:\n";
    print_r($extractedData);

    // Map to Robaws format - pass the extracted data as third parameter
    echo "\nMapping to Robaws format...\n";
    $robawsData = $mapper->mapIntakeToRobaws($intake, [$document], $extractedData);

    echo "Robaws mapped data:\n";
    print_r($robawsData);

    // Specific tests
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "VALIDATION TESTS\n";
    echo str_repeat("=", 50) . "\n";

    // Test POD (Port of Destination)
    $pod = $robawsData['pod'] ?? 'NOT FOUND';
    echo "POD (Port of Destination): " . $pod . "\n";

    if (strpos(strtolower($pod), 'lagos') !== false && strpos(strtolower($pod), 'nigeria') !== false) {
        echo "✅ POD correctly shows 'Lagos, Nigeria'\n";
    } else {
        echo "❌ POD should show 'Lagos, Nigeria', currently shows: " . $pod . "\n";
    }

    // Test Customer Reference 
    $customerRef = $robawsData['customer_reference'] ?? 'NOT FOUND';
    echo "\nCustomer Reference: " . $customerRef . "\n";

    $expectedElements = ['EXP', 'RORO', 'ANR', 'LAGOS', 'MAN', 'Truck'];
    $foundElements = [];

    foreach ($expectedElements as $element) {
        if (stripos($customerRef, $element) !== false) {
            $foundElements[] = $element;
        }
    }

    echo "Found elements in customer reference: " . implode(', ', $foundElements) . "\n";

    if (count($foundElements) >= 4) { // At least 4 out of 6 elements should be present
        echo "✅ Customer reference contains expected Nigeria/Lagos elements\n";
    } else {
        echo "❌ Customer reference should contain EXP RORO - ANR - LAGOS - MAN Truck elements\n";
    }

    echo "\nTest completed successfully.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
