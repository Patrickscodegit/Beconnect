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

echo "=== ARMOS BV DATA DEBUG ===\n";

$mapper = new RobawsMapper();

// Test mapping
echo "1. Raw intake data:\n";
print_r($intake->raw_data);

echo "\n2. Extraction data:\n";
print_r($extractionData);

echo "\n3. Mapped data:\n";
$mappedData = $mapper->mapIntakeToRobaws($intake, $extractionData);
print_r($mappedData);

echo "\n4. API Payload:\n";
$payload = $mapper->toRobawsApiPayload($mappedData);
print_r($payload);

echo "\n=== DEBUG COMPLETE ===\n";
