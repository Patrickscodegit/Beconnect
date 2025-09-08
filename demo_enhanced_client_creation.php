<?php

/**
 * Demonstration script for enhanced Robaws client creation
 * 
 * This script shows how the enhanced client creation/retrieval process works
 * when exporting to Robaws with comprehensive customer data and contact persons.
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Models\Intake;
use App\Services\Export\Mappers\RobawsMapper;
use App\Services\Export\Clients\RobawsApiClient;
use App\Services\Robaws\RobawsExportService;

echo "=== Enhanced Robaws Client Creation Demo ===\n\n";

// 1. Create a sample intake with comprehensive extraction data
$sampleExtractionData = [
    'customer_name' => 'Ebele Elobi Trading Company Ltd.',
    'email' => 'info@ebele-trading.ng',
    'phone' => '+234 1 234 5678',
    'mobile' => '+234 803 123 4567',
    'vat_number' => 'NG123456789',
    'company_number' => 'RC-987654321',
    'website' => 'https://ebele-trading.com',
    'contact' => [
        'name' => 'Ebele Elobi',
        'first_name' => 'Ebele',
        'last_name' => 'Elobi',
        'email' => 'ebele@ebele-trading.ng',
        'phone' => '+234 1 234 5678',
        'mobile' => '+234 803 123 4567',
        'function' => 'Managing Director',
        'department' => 'Operations',
    ],
    'sender' => 'John Smith', // Additional contact person
    'address' => [
        'street' => 'Victoria Island Business District',
        'street_number' => '45A',
        'city' => 'Lagos',
        'postal_code' => '101241',
        'country' => 'Nigeria',
    ],
    'shipping' => [
        'origin' => 'Antwerp, Belgium',
        'destination' => 'Lagos, Nigeria',
        'method' => 'RORO',
    ],
    'vehicle' => [
        'brand' => 'MAN',
        'model' => 'TGX 26.440',
        'year' => '2020',
        'condition' => 'used',
        'fuel_type' => 'diesel',
    ],
    'raw_text' => 'Good day, we need a quote for shipping our MAN truck from Antwerp to Lagos port. Please provide competitive rates for RORO shipping. Thank you. Best regards, Ebele Elobi',
];

echo "1. Sample Extraction Data:\n";
echo json_encode($sampleExtractionData, JSON_PRETTY_PRINT) . "\n\n";

// 2. Create mapper and extract enhanced customer data
$mapper = new RobawsMapper();

// Mock intake for demonstration  
$intake = new class extends App\Models\Intake {
    public $id = 12345;
    public $customer_name = 'Ebele Elobi Trading Company Ltd.';
    public $contact_email = 'info@ebele-trading.ng';
    public $contact_phone = '+234 1 234 5678';
    public $robaws_client_id = null;
    
    public function loadMissing($relations) {
        return $this;
    }
    
    public function getExtractionDataAttribute() {
        return null;
    }
};

// Extract enhanced customer data
$mappedData = $mapper->mapIntakeToRobaws($intake, $sampleExtractionData);
$customerData = $mappedData['customer_data'];

echo "2. Enhanced Customer Data Extracted:\n";
echo json_encode($customerData, JSON_PRETTY_PRINT) . "\n\n";

// 3. Show what would be sent to create/update a client
echo "3. Client Creation/Update Process:\n\n";

echo "Step 1: Search for existing client\n";
echo "- Search by email: {$customerData['email']}\n";
echo "- Search by name: {$customerData['name']}\n\n";

echo "Step 2: If client not found, create new client with enhanced data:\n";
$clientPayload = [
    'name' => $customerData['name'],
    'email' => $customerData['email'],
    'phone' => $customerData['phone'],
    'mobile' => $customerData['mobile'],
    'vat_number' => $customerData['vat_number'],
    'company_number' => $customerData['company_number'],
    'website' => $customerData['website'],
    'language' => $customerData['language'],
    'currency' => $customerData['currency'],
    'client_type' => $customerData['client_type'],
    'status' => 'active',
    'address' => [
        'street' => $customerData['street'],
        'street_number' => $customerData['street_number'],
        'city' => $customerData['city'],
        'postal_code' => $customerData['postal_code'],
        'country' => $customerData['country'],
    ],
    'contact_persons' => [
        [
            'name' => $customerData['contact_person']['name'],
            'first_name' => $customerData['contact_person']['first_name'],
            'last_name' => $customerData['contact_person']['last_name'],
            'email' => $customerData['contact_person']['email'],
            'phone' => $customerData['contact_person']['phone'],
            'mobile' => $customerData['contact_person']['mobile'],
            'function' => $customerData['contact_person']['function'],
            'is_primary' => true,
            'receives_quotes' => true,
        ]
    ]
];

echo json_encode($clientPayload, JSON_PRETTY_PRINT) . "\n\n";

// 4. Show the benefits of enhanced client creation
echo "4. Benefits of Enhanced Client Creation:\n\n";

echo "✅ Comprehensive Customer Profiles:\n";
echo "   - Full company information (VAT, company number, website)\n";
echo "   - Complete address details\n";
echo "   - Multiple phone numbers (office and mobile)\n";
echo "   - Automatic client type detection (company vs individual)\n\n";

echo "✅ Contact Person Management:\n";
echo "   - Primary contact person with full details\n";
echo "   - Job title/function information\n";
echo "   - Separate contact details for the person\n";
echo "   - Support for multiple contact persons\n\n";

echo "✅ Smart Data Extraction:\n";
echo "   - Language detection from email content\n";
echo "   - Country normalization (Deutschland → Germany)\n";
echo "   - Port to country mapping (Antwerp → Belgium)\n";
echo "   - Fallback mechanisms for missing data\n\n";

echo "✅ Improved Client Matching:\n";
echo "   - Search by email first (most unique)\n";
echo "   - Fallback to name-based search\n";
echo "   - Update existing clients with new information\n";
echo "   - Avoid duplicate client creation\n\n";

// 5. Show the integration with Robaws export
echo "5. Integration with Robaws Export:\n\n";

echo "The enhanced client data flows through the export process:\n";
echo "1. Extract enhanced customer data during mapping\n";
echo "2. Find or create client in Robaws with full details\n";
echo "3. Store client ID for future exports\n";
echo "4. Link quotation/offer to the client\n";
echo "5. Include contact person in quotation details\n\n";

// 6. Example API calls that would be made
echo "6. Example API Calls:\n\n";

echo "GET /api/v2/clients?email=info@ebele-trading.ng\n";
echo "→ Search for existing client by email\n\n";

echo "POST /api/v2/clients\n";
echo "→ Create new client with enhanced data if not found\n\n";

echo "POST /api/v2/offers\n";
echo "→ Create quotation linked to client ID\n\n";

// 7. Show the resulting client profile in Robaws
echo "7. Resulting Client Profile in Robaws:\n\n";

$resultingProfile = [
    'id' => 'CLI-2025-001',
    'name' => 'Ebele Elobi Trading Company Ltd.',
    'type' => 'company',
    'email' => 'info@ebele-trading.ng',
    'phone' => '+234 1 234 5678',
    'mobile' => '+234 803 123 4567',
    'vat_number' => 'NG123456789',
    'company_number' => 'RC-987654321',
    'website' => 'https://ebele-trading.com',
    'language' => 'en',
    'currency' => 'EUR',
    'address' => [
        'street' => 'Victoria Island Business District 45A',
        'city' => 'Lagos',
        'postal_code' => '101241',
        'country' => 'Nigeria',
    ],
    'primary_contact' => [
        'name' => 'Ebele Elobi',
        'title' => 'Managing Director',
        'email' => 'ebele@ebele-trading.ng',
        'phone' => '+234 1 234 5678',
        'receives_quotes' => true,
    ],
    'status' => 'active',
    'created_at' => date('Y-m-d H:i:s'),
];

echo json_encode($resultingProfile, JSON_PRETTY_PRINT) . "\n\n";

echo "=== Demo Complete ===\n\n";

echo "This enhanced system creates rich client profiles that enable:\n";
echo "• Better customer relationship management\n";
echo "• More accurate quote routing to the right contact person\n";
echo "• Improved duplicate prevention\n";
echo "• Enhanced reporting and analytics\n";
echo "• Better integration with CRM workflows\n";
