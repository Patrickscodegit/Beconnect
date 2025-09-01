<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Intake;
use App\Services\Export\Mappers\RobawsMapper;
use PHPUnit\Framework\Attributes\Test;

class RobawsMapperComprehensiveTest extends TestCase
{
    #[Test]
    public function it_builds_api_payload_with_customer_and_extra_fields()
    {
        // Arrange
        $intake = Intake::factory()->make([
            'customer_name' => 'Badr Algothami',
            'customer_email'=> 'badr@example.com',
        ]);

        $extraction = [
            'document_data' => [
                'contact' => ['name' => 'Badr Algothami', 'email' => 'badr@example.com'],
                'vehicle' => ['brand' => 'BMW', 'model' => 'Série 7', 'vin' => 'WBA123456789'],
                'shipping'=> [
                    'method' => 'RoRo',
                    'route'  => [
                        'origin'      => ['city' => 'Bruxelles', 'country' => 'Belgium'],
                        'destination' => ['city' => 'Djeddah',   'country' => 'Saudi Arabia'],
                    ],
                    'timeline' => [['vessel' => 'MSC Foo', 'voyage' => '123A']],
                ],
            ],
            'dates' => [
                ['type' => 'eta', 'date' => '2025-09-15'],
            ],
            'pricing' => [],
        ];

        $mapper = app(RobawsMapper::class);

        // Act: full map, then set customer_id, then API payload
        $mapped = $mapper->mapIntakeToRobaws($intake, $extraction);
        $mapped['customer_id'] = 4321; // <- inject from apiClient->findCustomerId() in service
        $payload = $mapper->toRobawsApiPayload($mapped);

        // Assert: top-levels (no date field in API payload)
        $this->assertSame(4321, $payload['customerId']);
        $this->assertSame('badr@example.com', $payload['contactEmail']);
        $this->assertArrayHasKey('extraFields', $payload);

        // Assert: typed extraFields
        $xf = $payload['extraFields'];
        $this->assertSame('TEXT', $xf['POR']['type']);
        $this->assertSame('Bruxelles, Belgium', $xf['POR']['stringValue']);
        // FDEST should be empty for port destinations (Djeddah is a port)
        $this->assertArrayNotHasKey('FDEST', $xf);
        $this->assertSame('TEXT', $xf['CARGO']['type']);
        $this->assertStringContainsString('BMW', $xf['CARGO']['stringValue']);
        $this->assertStringContainsString('Série 7', $xf['CARGO']['stringValue']);
        $this->assertStringContainsString('WBA123456789', $xf['CARGO']['stringValue']);
        $this->assertSame('TEXT', $xf['JSON']['type']); // JSON is TEXT not TEXTAREA in current mapper
        $this->assertNotEmpty($xf['JSON']['stringValue']); // pretty-printed extraction
    }

    #[Test]
    public function it_types_dates_and_booleans_correctly()
    {
        $mapper = app(RobawsMapper::class);
        $intake = Intake::factory()->make();

        $extraction = [
            'document_data' => ['contact' => ['email' => 'x@y.z']],
            'dates' => [
                ['type' => 'eta', 'date' => '2025-12-01'],
                ['type' => 'ets', 'date' => '2025-11-20'],
                ['type' => 'etc', 'date' => '2025-11-15'],
            ],
        ];

        $mapped = $mapper->mapIntakeToRobaws($intake, $extraction);
        $mapped['customer_id'] = 1;
        $payload = $mapper->toRobawsApiPayload($mapped);

        $xf = $payload['extraFields'];

        // Depending on your tenant, DATE might be TEXT. If Robaws rejected DATE, keep them TEXT.
        $this->assertArrayHasKey('ETA', $xf);
        $this->assertContains($xf['ETA']['type'], ['DATE','TEXT']);
        $this->assertNotEmpty($xf['ETA']['stringValue']);

        $this->assertArrayHasKey('URGENT', $xf);
        $this->assertSame('CHECKBOX', $xf['URGENT']['type']);
        $this->assertIsBool($xf['URGENT']['booleanValue']);
    }

    #[Test]
    public function it_handles_missing_customer_id_gracefully()
    {
        $mapper = app(RobawsMapper::class);
        $intake = Intake::factory()->make();

        $extraction = [
            'document_data' => ['contact' => ['email' => 'test@example.com']],
        ];

        $mapped = $mapper->mapIntakeToRobaws($intake, $extraction);
        // Don't set customer_id to test graceful handling
        $payload = $mapper->toRobawsApiPayload($mapped);

        // array_filter removes null values from payload - this is correct behavior
        $this->assertArrayNotHasKey('customerId', $payload);
        $this->assertArrayHasKey('extraFields', $payload);
        $this->assertIsArray($payload);
    }

    #[Test]
    public function it_preserves_top_level_over_nested_data()
    {
        $mapper = app(RobawsMapper::class);
        $intake = Intake::factory()->make();

        $extraction = [
            'origin' => 'TOP_LEVEL_ORIGIN', // This should win
            'document_data' => [
                'shipping' => [
                    'route' => [
                        'origin' => ['city' => 'NESTED_ORIGIN'], // This should be ignored
                    ]
                ]
            ]
        ];

        $mapped = $mapper->mapIntakeToRobaws($intake, $extraction);
        $mapped['customer_id'] = 999;
        $payload = $mapper->toRobawsApiPayload($mapped);

        $xf = $payload['extraFields'];
        $this->assertSame('TOP_LEVEL_ORIGIN', $xf['POR']['stringValue']);
    }
}
