<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

use App\Services\Extraction\Strategies\PatternExtractor;
use App\Services\Export\Mappers\RobawsMapper;

echo "Testing Oliver Sielemann email extraction (Pattern Extractor Only)...\n\n";

// Extract email body manually from the email content
$emailContent = file_get_contents('car_shipment_oliver.eml');

// Extract plain text body from email
preg_match('/Content-Type: text\/plain.*?\n\n(.*?)(?=------=_NextPart_|$)/s', $emailContent, $matches);
$plainBody = isset($matches[1]) ? trim($matches[1]) : '';

// Clean up quoted-printable encoding
$plainBody = quoted_printable_decode($plainBody);

echo "=== EMAIL BODY ===\n";
echo $plainBody . "\n\n";

// Initialize services
$patternExtractor = app(PatternExtractor::class);
$robawsMapper = app(RobawsMapper::class);

// Extract data using pattern extractor
echo "=== PATTERN EXTRACTION ===\n";
$extractionData = $patternExtractor->extract($plainBody);
echo json_encode($extractionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// Create a mock intake for mapping
$mockIntake = new \App\Models\Intake();
$mockIntake->customer_name = 'Oliver Sielemann';
$mockIntake->customer_email = 'info@oliversielemann.de';

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

echo "=== EXPECTED VS ACTUAL ===\n";
echo "Expected Customer Reference: EXP RORO - GERMANY - MOMBASA - 1 x used Suzuki Samurai\n";
echo "Actual Customer Reference:   " . ($mapped['quotation_info']['customer_reference'] ?? 'N/A') . "\n\n";

echo "Expected Cargo: 1 x used Suzuki Samurai\n";
echo "Actual Cargo:   " . ($mapped['cargo_details']['cargo'] ?? 'N/A') . "\n\n";

echo "Expected Dimensions: Should include 800cm x 204cm x 232cm, 2000kg\n";
echo "Actual Dimensions:   " . ($mapped['cargo_details']['dimensions_text'] ?? 'N/A') . "\n\n";

echo "Expected POL: Should be Germany port\n";
echo "Actual POL:   " . ($mapped['routing']['pol'] ?? 'N/A') . "\n\n";

echo "Expected POD: Should be Mombasa\n";
echo "Actual POD:   " . ($mapped['routing']['pod'] ?? 'N/A') . "\n\n";
