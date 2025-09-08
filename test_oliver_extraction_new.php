<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

use App\Services\EmailParserService;
use App\Services\Extraction\Strategies\PatternExtractor;
use App\Services\Export\Mappers\RobawsMapper;

echo "Testing Oliver Sielemann email extraction...\n\n";

// Read the email file
$emailContent = file_get_contents('car_shipment_oliver.eml');

// Initialize services
$emailParser = app(EmailParserService::class);
$patternExtractor = app(PatternExtractor::class);
$robawsMapper = app(RobawsMapper::class);

// Process the email
$parsed = $emailParser->parse($emailContent);

echo "=== EMAIL PARSING RESULTS ===\n";
echo "From: " . $parsed['from'] . "\n";
echo "Subject: " . $parsed['subject'] . "\n";
echo "Body Preview: " . substr($parsed['body'], 0, 200) . "...\n\n";

// Extract data using pattern extractor
echo "=== PATTERN EXTRACTION ===\n";
$extractionData = $patternExtractor->extract($parsed['body']);
echo json_encode($extractionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// Create a mock intake for mapping
$mockIntake = new \App\Models\Intake();
$mockIntake->customer_name = $parsed['from_name'] ?? 'Oliver Sielemann';
$mockIntake->customer_email = $parsed['from'];

// Map to Robaws format
echo "=== ROBAWS MAPPING ===\n";
$mapped = $robawsMapper->mapIntakeToRobaws($mockIntake, $extractionData);

// Show key fields
echo "Customer Reference: " . ($mapped['quotation_info']['customer_reference'] ?? 'N/A') . "\n";
echo "Concerning: " . ($mapped['quotation_info']['concerning'] ?? 'N/A') . "\n";
echo "Cargo: " . ($mapped['cargo_details']['cargo'] ?? 'N/A') . "\n";
echo "Dimensions: " . ($mapped['cargo_details']['dimensions_text'] ?? 'N/A') . "\n";
echo "POL: " . ($mapped['routing']['pol'] ?? 'N/A') . "\n";
echo "POD: " . ($mapped['routing']['pod'] ?? 'N/A') . "\n";
echo "POR: " . ($mapped['routing']['por'] ?? 'N/A') . "\n";
echo "FDEST: " . ($mapped['routing']['fdest'] ?? 'N/A') . "\n\n";

echo "=== ISSUES TO FIX ===\n";
echo "Expected:\n";
echo "- Customer Reference: EXP RORO - GERMANY - MOMBASA - 1 x used Suzuki Samurai\n";
echo "- Cargo: 1 x used Suzuki Samurai\n";
echo "- Dimensions: 800cm x 204cm x 232cm, 2000kg\n";
echo "- POL: Germany\n";
echo "- POD: Mombasa (or Dar es Salaam)\n\n";

echo "=== ROBAWS API PAYLOAD ===\n";
$payload = $robawsMapper->toRobawsApiPayload($mapped);
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
