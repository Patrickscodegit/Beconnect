<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

use App\Services\Email\EmailProcessor;
use App\Services\Extraction\Strategies\PatternExtractor;
use App\Services\Export\Mappers\RobawsMapper;
use Illuminate\Support\Facades\Log;

echo "Testing Oliver Sielemann email extraction...\n\n";

// Read the email file
$emailContent = file_get_contents('car_shipment_oliver.eml');

// Initialize services
$emailProcessor = app(EmailProcessor::class);
$patternExtractor = app(PatternExtractor::class);
$robawsMapper = app(RobawsMapper::class);

// Process the email
$processed = $emailProcessor->processEmailContent($emailContent);

echo "=== EMAIL PROCESSING RESULTS ===\n";
echo "From: " . $processed['from'] . "\n";
echo "Subject: " . $processed['subject'] . "\n";
echo "Body Preview: " . substr($processed['body'], 0, 200) . "...\n\n";

// Extract data using pattern extractor
echo "=== PATTERN EXTRACTION ===\n";
$extractionData = $patternExtractor->extract($processed['body']);
echo json_encode($extractionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// Create a mock intake for mapping
$mockIntake = new \App\Models\Intake();
$mockIntake->customer_name = $processed['from_name'] ?? 'Oliver Sielemann';
$mockIntake->customer_email = $processed['from'];

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

echo "=== FULL ROBAWS PAYLOAD ===\n";
$payload = $robawsMapper->toRobawsApiPayload($mapped);
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
