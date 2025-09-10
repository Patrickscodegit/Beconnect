<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Utils\EmailQuotationProcessor;

$emailPath = __DIR__ . '/armos_bv_email.eml';

if (!file_exists($emailPath)) {
    echo "Email file not found: $emailPath\n";
    exit;
}

echo "Testing phone extraction from Armos BV email...\n\n";

try {
    $processor = app(EmailQuotationProcessor::class);
    $emailContent = file_get_contents($emailPath);
    $result = $processor->processEmailForQuotation($emailContent);
    
    if ($result && isset($result['extraction_data']['raw_data'])) {
        $rawData = $result['extraction_data']['raw_data'];
        
        echo "=== RAW DATA PHONE FIELDS ===\n";
        foreach ($rawData as $key => $value) {
            if (is_string($value) && (
                stripos($key, 'phone') !== false || 
                stripos($key, 'tel') !== false || 
                stripos($key, 'mobile') !== false || 
                stripos($key, 'gsm') !== false ||
                preg_match('/\+32|0\d{2,3}[\s\-\.\(\)]*\d/', $value)
            )) {
                echo "$key: $value\n";
            }
        }
        
        echo "\n=== ALL PHONE-LIKE VALUES ===\n";
        foreach ($rawData as $key => $value) {
            if (is_string($value) && preg_match('/(\+32|0\d{1,3})[\s\-\.\(\)]*\d{6,}/', $value)) {
                echo "$key: $value\n";
            }
        }
        
        // Test the enhanced extraction logic
        echo "\n=== ENHANCED EXTRACTION LOGIC TEST ===\n";
        $phone = $rawData['phone'] ?? $rawData['tel'] ?? null;
        $mobile = $rawData['mobile'] ?? $rawData['gsm'] ?? null;
        
        echo "Phone result: " . ($phone ?: 'not found') . "\n";
        echo "Mobile result: " . ($mobile ?: 'not found') . "\n";
        
    } else {
        echo "Failed to process email or no raw_data found\n";
        echo "Result structure:\n";
        print_r($result);
        if (isset($result['error'])) {
            echo "Error: " . $result['error'] . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
