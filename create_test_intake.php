<?php

use App\Models\Intake;

// Create test intake with Armos BV data
$intake = Intake::create([
    'customer_name' => 'Armos BV',
    'customer_email' => 'info@armos.be',
    'contact_phone' => '+32 (0)3 435 86 57',
    'status' => 'pending',
    'extraction_data' => [
        'customer_name' => 'Armos BV',
        'email' => 'info@armos.be',
        'phone' => '+32 (0)3 435 86 57',
        'mobile' => '+32 (0)476 72 02 16',
        'vat_number' => '0437 311 533',
        'website' => 'www.armos.be',
        'address' => [
            'street' => 'Kapelsesteenweg 611',
            'city' => 'Antwerp (Ekeren)',
            'zip' => 'B-2180',
            'country' => 'Belgium'
        ],
        'contact' => [
            'name' => 'Armos BV Team',
            'email' => 'info@armos.be',
            'phone' => '+32 (0)3 435 86 57',
            'mobile' => '+32 (0)476 72 02 16',
            'company' => 'Armos BV'
        ],
        'raw_text' => '
From: info@armos.be

Dear colleagues,

We are Armos BV, a Belgian company located at:
Kapelsesteenweg 611
B-2180 Antwerp (Ekeren)
Belgium

Contact details:
Mobile: +32 (0)476 72 02 16
Tel: +32 (0)3 435 86 57
VAT number: 0437 311 533
Website: www.armos.be

We need transport for our RoRo shipment:

1 x used BMW X5
Dimensions: L390 cm, B230 cm, H310cm

Best regards,
Armos BV Team
'
    ]
]);

echo "Created intake ID: {$intake->id}\n";
echo "Customer: {$intake->customer_name}\n";
echo "Email: {$intake->customer_email}\n";
