<?php

echo "=== Enhanced Robaws Client Creation Demo ===\n\n";

// 1. Sample extraction data from a real email
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

// 2. Enhanced customer data that would be created
$enhancedCustomerData = [
    'name' => 'Ebele Elobi Trading Company Ltd.',
    'email' => 'info@ebele-trading.ng',
    'phone' => '+234 1 234 5678',
    'mobile' => '+234 803 123 4567',
    'vat_number' => 'NG123456789',
    'company_number' => 'RC-987654321',
    'website' => 'https://ebele-trading.com',
    'language' => 'en',
    'currency' => 'EUR',
    'client_type' => 'company',
    'street' => 'Victoria Island Business District',
    'street_number' => '45A',
    'city' => 'Lagos',
    'postal_code' => '101241',
    'country' => 'Nigeria',
    'contact_person' => [
        'name' => 'Ebele Elobi',
        'first_name' => 'Ebele',
        'last_name' => 'Elobi',
        'email' => 'ebele@ebele-trading.ng',
        'phone' => '+234 1 234 5678',
        'mobile' => '+234 803 123 4567',
        'function' => 'Managing Director',
        'is_primary' => true,
    ]
];

echo "2. Enhanced Customer Data for Client Creation:\n";
echo json_encode($enhancedCustomerData, JSON_PRETTY_PRINT) . "\n\n";

// 3. Client creation process
echo "3. Enhanced Client Creation Process:\n\n";

echo "STEP 1: Search for Existing Client\n";
echo "   GET /api/v2/clients (search by email: info@ebele-trading.ng)\n";
echo "   → If found: Update with new information\n";
echo "   → If not found: Create new client\n\n";

echo "STEP 2: Create New Client (if not found)\n";
echo "   POST /api/v2/clients\n";

$clientPayload = [
    'name' => 'Ebele Elobi Trading Company Ltd.',
    'email' => 'info@ebele-trading.ng',
    'phone' => '+234 1 234 5678',
    'mobile' => '+234 803 123 4567',
    'vat_number' => 'NG123456789',
    'company_number' => 'RC-987654321',
    'website' => 'https://ebele-trading.com',
    'language' => 'en',
    'currency' => 'EUR',
    'client_type' => 'company',
    'status' => 'active',
    'address' => [
        'street' => 'Victoria Island Business District',
        'street_number' => '45A',
        'city' => 'Lagos',
        'postal_code' => '101241',
        'country' => 'Nigeria',
    ],
    'contact_persons' => [
        [
            'name' => 'Ebele Elobi',
            'first_name' => 'Ebele',
            'last_name' => 'Elobi',
            'email' => 'ebele@ebele-trading.ng',
            'phone' => '+234 1 234 5678',
            'mobile' => '+234 803 123 4567',
            'function' => 'Managing Director',
            'is_primary' => true,
            'receives_quotes' => true,
        ]
    ]
];

echo "   Request Body:\n";
echo json_encode($clientPayload, JSON_PRETTY_PRINT) . "\n\n";

echo "STEP 3: Create Quotation Linked to Client\n";
echo "   POST /api/v2/offers\n";

$quotationPayload = [
    'title' => 'EXP RORO - ANR - LAGOS - 1 x used MAN Truck',
    'clientId' => 12345, // ID returned from client creation
    'contactEmail' => 'info@ebele-trading.ng',
    'extraFields' => [
        'CUSTOMER' => ['stringValue' => 'Ebele Elobi Trading Company Ltd.'],
        'CONTACT' => ['stringValue' => 'Ebele Elobi'],
        'CONTACT_EMAIL' => ['stringValue' => 'info@ebele-trading.ng'],
        'POL' => ['stringValue' => 'Antwerp, Belgium'],
        'POD' => ['stringValue' => 'Lagos, Nigeria'],
        'CARGO' => ['stringValue' => '1 x used MAN TGX 26.440 (2020)'],
        'METHOD' => ['stringValue' => 'RORO'],
    ]
];

echo "   Request Body (simplified):\n";
echo json_encode($quotationPayload, JSON_PRETTY_PRINT) . "\n\n";

// 4. Benefits
echo "4. Key Benefits of Enhanced Client Creation:\n\n";

echo "✅ COMPREHENSIVE CUSTOMER PROFILES:\n";
echo "   • Full company information (VAT, registration numbers)\n";
echo "   • Complete address with postal code\n";
echo "   • Multiple contact methods (office, mobile)\n";
echo "   • Website and business details\n";
echo "   • Automatic company vs individual detection\n\n";

echo "✅ CONTACT PERSON MANAGEMENT:\n";
echo "   • Primary contact with job title/function\n";
echo "   • Separate contact details for the person\n";
echo "   • Email routing preferences (quotes, invoices)\n";
echo "   • Support for multiple contact persons\n\n";

echo "✅ SMART DATA EXTRACTION:\n";
echo "   • Language detection from email content\n";
echo "   • Country normalization (Deutschland → Germany)\n";
echo "   • Port mapping (Antwerp → Belgium)\n";
echo "   • Phone number formatting\n\n";

echo "✅ IMPROVED DUPLICATE PREVENTION:\n";
echo "   • Search by email first (most reliable)\n";
echo "   • Fallback to name-based matching\n";
echo "   • Update existing clients with new info\n";
echo "   • Avoid creating duplicate records\n\n";

// 5. Comparison with old system
echo "5. Comparison: Before vs After\n\n";

echo "BEFORE (Basic Client Creation):\n";
echo "   {\n";
echo '     "name": "Unknown Customer",'."\n";
echo '     "email": "noreply@bconnect.com"'."\n";
echo "   }\n\n";

echo "AFTER (Enhanced Client Creation):\n";
echo "   {\n";
echo '     "name": "Ebele Elobi Trading Company Ltd.",'."\n";
echo '     "email": "info@ebele-trading.ng",'."\n";
echo '     "phone": "+234 1 234 5678",'."\n";
echo '     "vat_number": "NG123456789",'."\n";
echo '     "address": { "city": "Lagos", "country": "Nigeria" },'."\n";
echo '     "contact_person": { "name": "Ebele Elobi", "function": "Managing Director" }'."\n";
echo "   }\n\n";

// 6. Real-world impact
echo "6. Real-World Impact:\n\n";

echo "🎯 BETTER CUSTOMER RELATIONSHIPS:\n";
echo "   • Personal contact with decision makers\n";
echo "   • Professional communication addressing correct person\n";
echo "   • Complete customer context for sales team\n\n";

echo "📈 IMPROVED BUSINESS EFFICIENCY:\n";
echo "   • Faster quote routing to right contact\n";
echo "   • Reduced manual data entry\n";
echo "   • Better follow-up and customer service\n\n";

echo "🔍 ENHANCED REPORTING:\n";
echo "   • Customer segmentation by country/type\n";
echo "   • Revenue analysis by customer profile\n";
echo "   • Contact person effectiveness tracking\n\n";

echo "=== Demo Complete ===\n\n";

echo "The enhanced client creation system transforms basic email data into\n";
echo "rich customer profiles that improve every aspect of the business process.\n";
