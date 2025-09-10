use App\Models\Intake;
use App\Services\Export\Mappers\RobawsMapper;

// Create test intake with real Armos BV data
$intake = new Intake();
$intake->id = 999999;
$intake->raw_data = [
    'subject' => 'Ro-Ro transport van Antwerpen naar MOMBASA',
    'content' => 'Goede middag... Details heftruck: Jungheftruck TFG435s L390 cm B230 cm H310cm 3500KG ... Nancy',
    'contact' => [
        'name' => 'Nancy Deckers',
        'email' => 'nancy@armos.be',
        'phone' => '+32 (0)3 435 86 57',
        'mobile' => '+32 (0)476 72 02 16'
    ],
    'company' => 'Armos BV',
    'vat_number' => '0437 311 533',
    'website' => 'www.armos.be',
    'address' => 'Kapelsesteenweg 611, B-2180 Antwerp (Ekeren), Belgium'
];

$extractionData = [
    'from' => 'Antwerpen',
    'to' => 'MOMBASA',
    'cargo_type' => 'Heftruck',
    'cargo_details' => 'Jungheftruck TFG435s - L390 cm, B230 cm, H310cm, 3500KG'
];

echo "=== ARMOS BV CONTACT PHONE TEST ===\n\n";

$mapper = new RobawsMapper();

// Test the full flow
echo "1. Testing mapIntakeToRobaws():\n";
$mappedData = $mapper->mapIntakeToRobaws($intake, $extractionData);

echo "   Customer: " . ($mappedData['customer']['name'] ?? 'NOT SET') . "\n";
echo "   Contact: " . ($mappedData['contact']['name'] ?? 'NOT SET') . "\n";

echo "\n2. Testing toRobawsApiPayload():\n";
$payload = $mapper->toRobawsApiPayload($mappedData);

echo "   Template client: " . ($payload['extraFields']['client'] ?? 'NOT SET') . "\n";
echo "   Template clientVat: " . ($payload['extraFields']['clientVat'] ?? 'NOT SET') . "\n";
echo "   Contact phone: " . ($payload['contact']['phone'] ?? 'NOT SET') . "\n";
echo "   Contact mobile: " . ($payload['contact']['mobile'] ?? 'NOT SET') . "\n";

echo "\n=== TEST COMPLETE ===\n";
