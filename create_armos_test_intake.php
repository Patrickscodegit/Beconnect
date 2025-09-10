<?php

// Create test intake with real Armos BV email data for debugging customer mapping

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Foundation\Application;
use App\Models\Intake;

// Bootstrap Laravel
$app = new Application(realpath(__DIR__));
require __DIR__ . '/bootstrap/app.php';

// Get the real email content
$emailContent = file_get_contents(__DIR__ . '/armos_bv_email.eml');

// Create fake extraction data that simulates what would be extracted from the Armos BV email
$extractionData = [
    'contact' => [
        'name' => 'Nancy Deckers',
        'email' => 'nancy@armos.be',
        'phone' => '+32 (0)3 435 86 57',
        'mobile' => '+32 (0)476 72 02 16',
        'company' => 'Armos BV',
    ],
    'file_id' => null,
    'filename' => 'armos_bv_email.eml',
    'mime_type' => 'message/rfc822',
    'contact_email' => 'nancy@armos.be',
    'contact_phone' => '+32 (0)3 435 86 57',
    'customer_name' => 'Nancy Deckers',
    'raw_data' => [
        'company' => 'Armos BV',
        'contact_name' => 'Nancy Deckers',
        'email' => 'nancy@armos.be',
        'phone' => '+32 (0)3 435 86 57',
        'mobile' => '+32 (0)476 72 02 16',
        'vat' => '0437 311 533',
        'website' => 'www.armos.be',
        'address' => 'Kapelsesteenweg 611, B-2180 Antwerp (Ekeren), Belgium',
        'street' => 'Kapelsesteenweg 611',
        'city' => 'Antwerp (Ekeren)',
        'zip' => 'B-2180',
        'country' => 'Belgium',
        'subject' => 'RO-RO verscheping ANTWERPEN - MOMBASA, KENIA',
        'message_content' => $emailContent,
        'cargo_type' => 'heftruck',
        'cargo_description' => 'Jungheftruck TFG435s L390 cm B230 cm H310cm 3500KG',
        'origin' => 'Antwerpen',
        'destination' => 'MOMBASA, KENIA',
        'service_type' => 'RO-RO transport',
        'extraction_method' => 'email_parsing',
        'extraction_timestamp' => now()->toISOString(),
    ],
    'extracted_at' => now()->toISOString()
];

try {
    // Create the intake record
    $intake = Intake::create([
        'status' => 'extracted',
        'extracted_data' => $extractionData,
        'extracted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    
    echo "✅ Created test intake with ID: {$intake->id}\n";
    echo "📧 Email: nancy@armos.be\n"; 
    echo "🏢 Company: Armos BV\n";
    echo "📱 Phone: +32 (0)3 435 86 57\n";
    echo "📱 Mobile: +32 (0)476 72 02 16\n";
    echo "🆔 VAT: 0437 311 533\n";
    echo "🌐 Website: www.armos.be\n";
    echo "📍 Address: Kapelsesteenweg 611, B-2180 Antwerp (Ekeren), Belgium\n";
    echo "\n";
    echo "🧪 Test command: php artisan debug:client-mapping --intake={$intake->id} --company=\"Armos BV\"\n";
    
} catch (Exception $e) {
    echo "❌ Error creating intake: {$e->getMessage()}\n";
}
