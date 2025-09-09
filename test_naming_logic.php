<?php

require_once __DIR__ . '/vendor/autoload.php';

// Mock the necessary classes for testing
class MockIntake {
    public $id = 999;
    public $customer_name = null;
    public $created_at;
    public $files;
    
    public function __construct() {
        $this->created_at = new DateTime();
        $this->files = collect([]);
    }
}

class MockFile {
    public $original_filename;
    
    public function __construct($filename) {
        $this->original_filename = $filename;
    }
}

echo "🧪 Testing Improved Client Naming Logic\n";
echo "======================================\n\n";

// Test scenarios
$scenarios = [
    [
        'name' => 'VIN Detection',
        'extraction_data' => [
            'vin_candidates' => [
                ['vin' => 'WBABC1234567890123', 'confidence' => 0.95]
            ]
        ]
    ],
    [
        'name' => 'Vehicle Information',
        'extraction_data' => [
            'vehicle' => [
                'make' => 'Toyota',
                'model' => 'Prius'
            ]
        ]
    ],
    [
        'name' => 'Email Pattern in OCR',
        'extraction_data' => [
            'ocr_text' => 'Contact John Doe at john.doe@example.com for more info'
        ]
    ],
    [
        'name' => 'Phone Pattern in OCR',
        'extraction_data' => [
            'ocr_text' => 'Call us at +32 123 456 789 for appointment'
        ]
    ],
    [
        'name' => 'Meaningful Filename',
        'extraction_data' => [],
        'filename' => 'toyota-service-estimate.jpg'
    ],
    [
        'name' => 'Screenshot Filename (should be ignored)',
        'extraction_data' => [],
        'filename' => 'Screenshot 2025-09-09.png'
    ],
    [
        'name' => 'No useful data (timestamp fallback)',
        'extraction_data' => [],
        'filename' => 'IMG_001.jpg'
    ]
];

// Mock the method (simplified version)
function generateFallbackClientName($intake, $extractionData) {
    // Try VIN-based name with better formatting
    if (!empty($extractionData['vin_candidates'])) {
        $vin = $extractionData['vin_candidates'][0]['vin'] ?? $extractionData['vin_candidates'][0];
        if (is_string($vin) && strlen($vin) >= 8) {
            return "Client - VIN: " . substr($vin, -8);
        }
    }

    // Try to extract vehicle information for a meaningful name
    if (!empty($extractionData['vehicle']['make']) || !empty($extractionData['vehicle']['model'])) {
        $make = $extractionData['vehicle']['make'] ?? 'Vehicle';
        $model = $extractionData['vehicle']['model'] ?? '';
        return trim("Client - {$make} {$model} Owner");
    }

    // Check for any identifiable information in OCR text
    if (!empty($extractionData['ocr_text'])) {
        $text = $extractionData['ocr_text'];
        
        // Look for email patterns to derive name
        if (preg_match('/([a-zA-Z]+\.[a-zA-Z]+)@/', $text, $matches)) {
            $nameParts = explode('.', $matches[1]);
            if (count($nameParts) >= 2) {
                return ucfirst($nameParts[0]) . ' ' . ucfirst($nameParts[1]);
            }
        }
        
        // Look for phone number patterns to create identifier
        if (preg_match('/(\+?\d{2,3}[\s-]?\d{3}[\s-]?\d{2,3}[\s-]?\d{2,3})/', $text, $matches)) {
            $phone = preg_replace('/[^\d]/', '', $matches[1]);
            if (strlen($phone) >= 8) {
                return "Client - Phone: " . substr($phone, -4);
            }
        }
    }

    // Use file name if meaningful
    $firstFile = $intake->files->first();
    if ($firstFile && $firstFile->original_filename) {
        $filename = pathinfo($firstFile->original_filename, PATHINFO_FILENAME);
        if (strlen($filename) > 5 && !preg_match('/^(IMG|DSC|Photo|Screenshot)/', $filename)) {
            return "Client - " . ucwords(str_replace(['-', '_'], ' ', $filename));
        }
    }

    // Create a time-based identifier for uniqueness
    $timestamp = $intake->created_at->format('Ymd-Hi');
    return "Image Client - {$timestamp}";
}

foreach ($scenarios as $scenario) {
    $intake = new MockIntake();
    
    if (isset($scenario['filename'])) {
        $intake->files = collect([new MockFile($scenario['filename'])]);
    }
    
    $clientName = generateFallbackClientName($intake, $scenario['extraction_data']);
    
    echo "📋 {$scenario['name']}:\n";
    echo "   → Client Name: \"{$clientName}\"\n\n";
}

echo "Testing completed! 🎉\n";
