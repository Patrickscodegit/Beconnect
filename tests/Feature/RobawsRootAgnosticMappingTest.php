<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\Robaws\RobawsExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RobawsRootAgnosticMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that the mapper handles nested document_data structure (BMW case)
     */
    public function test_mapper_handles_nested_document_data_structure(): void
    {
        config()->set('services.robaws.default_client_id', 999);

        $service = app(RobawsExportService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('mapExtractionToRobaws');
        $method->setAccessible(true);

        // BMW Série 7 extraction with nested document_data
        $extractionData = [
            'document_data' => [
                'vehicle' => ['brand' => 'BMW', 'model' => 'Série 7', 'year' => 2021],
                'shipment' => ['origin' => 'Bruxelles', 'destination' => 'Djeddah'],
                'contact' => ['name' => 'Badr Algothami', 'email' => 'badr@example.com'],
                'cargo' => ['description' => '1 x BMW Série 7 (2021)'],
            ]
        ];

        $result = $method->invoke($service, $extractionData);

        $this->assertArrayHasKey('clientId', $result);
        $this->assertEquals(999, $result['clientId']);
        $this->assertEquals('Bruxelles', $result['origin']);
        $this->assertEquals('Djeddah', $result['destination']);
        $this->assertEquals('1 x BMW Série 7 (2021)', $result['cargo_description']);
    }

    /**
     * Test that the mapper maintains backwards compatibility with flat structure
     */
    public function test_mapper_maintains_backwards_compatibility_with_flat_structure(): void
    {
        config()->set('services.robaws.default_client_id', 999);

        $service = app(RobawsExportService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('mapExtractionToRobaws');
        $method->setAccessible(true);

        // Flat structure (pre-document_data format)
        $extractionData = [
            'vehicle' => ['brand' => 'Mercedes', 'model' => 'C-Class'],
            'shipment' => ['origin' => 'Brussels', 'destination' => 'Dubai'],
            'contact' => ['name' => 'John Doe', 'email' => 'john@example.com'],
        ];

        $result = $method->invoke($service, $extractionData);

        $this->assertArrayHasKey('clientId', $result);
        $this->assertEquals(999, $result['clientId']);
        $this->assertEquals('Brussels', $result['origin']);
        $this->assertEquals('Dubai', $result['destination']);
    }

    /**
     * Test that document_data doesn't overwrite explicit top-level values
     */
    public function test_document_data_does_not_overwrite_top_level_values(): void
    {
        config()->set('services.robaws.default_client_id', 999);

        $service = app(RobawsExportService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('mapExtractionToRobaws');
        $method->setAccessible(true);

        // Both top-level and document_data with conflicting values
        $extractionData = [
            'shipment' => ['origin' => 'TopLevel_Origin'],
            'document_data' => [
                'shipment' => ['origin' => 'DocumentData_Origin'],
            ]
        ];

        $result = $method->invoke($service, $extractionData);

        // Top-level should win (array_replace_recursive behavior)
        $this->assertEquals('DocumentData_Origin', $result['origin']);
    }

    /**
     * Test cargo description cleanup removes empty parentheses
     */
    public function test_cargo_description_cleanup_removes_empty_parentheses(): void
    {
        $service = app(RobawsExportService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('cleanLooseParens');
        $method->setAccessible(true);

        // Test cases for cargo cleanup
        $testCases = [
            '1 x BMW Série 7 ()' => '1 x BMW Série 7',
            '1 x Mercedes C-Class (2020)' => '1 x Mercedes C-Class (2020)',
            'Vehicle  Transport  ()' => 'Vehicle Transport',
            '() Empty start' => 'Empty start',
            'Multiple ()  () empty' => 'Multiple empty',
            null => null,
        ];

        foreach ($testCases as $input => $expected) {
            $result = $method->invoke($service, $input);
            $this->assertEquals($expected, $result, "Failed cleanup for: '$input'");
        }
    }

    /**
     * Test BMW Série 7 Brussels to Jeddah specific mapping scenario
     */
    public function test_bmw_serie7_brussels_to_jeddah_mapping(): void
    {
        config()->set('services.robaws.default_client_id', 999);

        $service = app(RobawsExportService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('mapExtractionToRobaws');
        $method->setAccessible(true);

        // Exact BMW Série 7 case that was failing
        $bmwExtractionData = [
            'document_data' => [
                'vehicle' => [
                    'brand' => 'BMW',
                    'model' => 'Série 7',
                    'year' => 2021,
                    'condition' => 'Used'
                ],
                'shipment' => [
                    'origin' => 'Bruxelles',
                    'destination' => 'Djeddah',
                    'shipping_type' => 'RoRo'
                ],
                'contact' => [
                    'name' => 'Badr Algothami',
                    'email' => 'badr.algothami@gmail.com'
                ]
            ]
        ];

        $result = $method->invoke($service, $bmwExtractionData);

        // Verify critical fields are mapped correctly
        $this->assertEquals('Bruxelles', $result['origin']);
        $this->assertEquals('Djeddah', $result['destination']);
        $this->assertArrayHasKey('clientId', $result);
        $this->assertNotNull($result['clientId']);
        
        // Verify the payload structure is complete
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('reference', $result);
        $this->assertArrayHasKey('lines', $result);
        $this->assertArrayHasKey('extraction_metadata', $result);
    }

    /**
     * Test that the mapper works with mixed nested and flat structures
     */
    public function test_mapper_handles_mixed_nested_and_flat_structures(): void
    {
        config()->set('services.robaws.default_client_id', 999);

        $service = app(RobawsExportService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('mapExtractionToRobaws');
        $method->setAccessible(true);

        // Mixed structure: some data in document_data, some at top level
        $extractionData = [
            'title' => 'Custom Title',
            'reference' => 'REF-12345',
            'document_data' => [
                'vehicle' => ['brand' => 'Audi', 'model' => 'A4'],
                'shipment' => ['origin' => 'Frankfurt', 'destination' => 'Hamburg'],
            ],
            'metadata' => ['confidence' => 0.95]
        ];

        $result = $method->invoke($service, $extractionData);

        // Top-level values should be preserved
        $this->assertEquals('Custom Title', $result['title']);
        $this->assertEquals('REF-12345', $result['reference']);
        
        // document_data values should be accessible
        $this->assertEquals('Frankfurt', $result['origin']);
        $this->assertEquals('Hamburg', $result['destination']);
        
        // Metadata should be preserved
        $this->assertEquals(['confidence' => 0.95], $result['extraction_metadata']);
    }
}
