<?php

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "📞 Contact Person Phone Update Test\n";
echo "===================================\n\n";

use App\Services\Export\Mappers\RobawsMapper;
use App\Services\Export\Clients\RobawsApiClient;

try {
    // Create RobawsApiClient instance
    $apiClient = app(RobawsApiClient::class);
    
    // Create test data simulating Armos BV extraction
    $testData = [
        'client_id' => null, // Will be resolved via API
        'extraction_data' => [
            'raw_data' => [
                'company' => 'Armos BV',
                'phone' => '+32 9 264 90 10',
                'mobile' => '+32 476 123 456'
            ],
            'contact' => [
                'name' => 'Nancy Deckers',
                'email' => 'nancy.deckers@armos.be',
                'company' => 'Armos BV',
                'phone' => '+32 9 264 90 10',
                'mobile' => '+32 476 123 456'
            ]
        ]
    ];
    
    // Simulate the quotation data structure
    $quotationData = [
        'customer' => 'Armos BV',
        'contact_email' => 'nancy.deckers@armos.be',
        'contact_name' => 'Nancy Deckers'
    ];
    
    echo "🔍 Finding client ID for Armos BV...\n";
    $clientId = $apiClient->findClientId('Armos BV', 'nancy.deckers@armos.be');
    
    if (!$clientId) {
        echo "❌ No client found for Armos BV\n";
        exit(1);
    }
    
    echo "✅ Found client ID: $clientId\n\n";
    
    // Test the contact phone update logic
    echo "📋 Getting current client data...\n";
    $clientData = $apiClient->getClientById($clientId);
    
    if (empty($clientData['contacts'])) {
        echo "❌ No contacts found for client\n";
        exit(1);
    }
    
    echo "👥 Found " . count($clientData['contacts']) . " contact(s)\n";
    
    foreach ($clientData['contacts'] as $contact) {
        echo "  - Contact ID {$contact['id']}: {$contact['name']} ({$contact['email']})\n";
        echo "    Current phone: " . ($contact['tel'] ?? 'empty') . "\n";
        echo "    Current mobile: " . ($contact['gsm'] ?? 'empty') . "\n";
        
        // Update this contact if email matches
        if (strcasecmp($contact['email'], 'nancy.deckers@armos.be') === 0) {
            echo "\n🔄 Updating contact person with phone numbers...\n";
            
            // Prepare the update data
            $contactUpdate = [
                'phone' => '+32 9 264 90 10',
                'mobile' => '+32 476 123 456'
            ];
            
            // Update the contact
            $result = $apiClient->updateClientContact($clientId, $contact['id'], $contactUpdate);
            
            if ($result) {
                echo "✅ Contact person updated successfully\n";
                
                // Verify the update
                echo "\n🔍 Verifying update...\n";
                $updatedData = $apiClient->getClientById($clientId);
                foreach ($updatedData['contacts'] as $updatedContact) {
                    if ($updatedContact['id'] == $contact['id']) {
                        echo "  - Updated phone: " . ($updatedContact['tel'] ?? 'empty') . "\n";
                        echo "  - Updated mobile: " . ($updatedContact['gsm'] ?? 'empty') . "\n";
                        break;
                    }
                }
            } else {
                echo "❌ Failed to update contact person\n";
            }
            break;
        }
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
