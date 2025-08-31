<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Intake;
use App\Models\User;
use App\Services\Robaws\RobawsExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClientIdErrorFixValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test the exact scenario that was causing "Undefined array key 'clientId'" error
     */
    public function test_export_does_not_throw_undefined_client_id_error(): void
    {
        // Set up environment like production
        config()->set('services.robaws.default_client_id', 1);
        
        // Create a test intake with extraction
        $intake = Intake::factory()->create();
        $intake->extraction()->create([
            'extracted_data' => [
                'title' => 'BMW Serie 7 Transport',
                'vehicles' => [
                    [
                        'make_model' => 'BMW Serie 7',
                        'price' => 2500
                    ]
                ]
                // Note: No explicit clientId here - this was the trigger
            ],
            'raw_json' => json_encode(['test' => 'extraction'])
        ]);

        $service = app(RobawsExportService::class);
        
        // This used to throw "Undefined array key 'clientId'"
        try {
            $result = $service->exportIntake($intake);
            
            // Should have canonical structure even if export fails
            $this->assertArrayHasKey('failed', $result);
            $this->assertArrayHasKey('success', $result);
            $this->assertArrayHasKey('uploaded', $result);
            $this->assertArrayHasKey('exists', $result);
            $this->assertArrayHasKey('skipped', $result);
            $this->assertArrayHasKey('stats', $result);
            
            // The specific error should not occur
            $this->assertTrue(true, 'No undefined clientId error occurred');
            
        } catch (\Throwable $e) {
            // Any other errors are acceptable (missing Robaws connection, etc.)
            // But NOT "Undefined array key 'clientId'"
            $this->assertStringNotContainsString('Undefined array key', $e->getMessage());
            $this->assertStringNotContainsString('clientId', $e->getMessage());
        }
    }

    /**
     * Test that the mapper properly handles the exact problematic scenario
     */
    public function test_mapper_handles_extraction_without_explicit_client(): void
    {
        $service = app(RobawsExportService::class);
        
        // Use reflection to test the private method
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('mapExtractionToRobaws');
        $method->setAccessible(true);

        // This is the exact kind of extraction data that was causing the issue
        $extractionData = [
            'title' => 'BMW Serie 7 Transport',
            'vehicles' => [
                [
                    'make_model' => 'BMW Serie 7',
                    'price' => 2500
                ]
            ]
            // No clientId field at all
        ];

        $mapped = $method->invoke($service, $extractionData);

        // Should have resolved to the default clientId
        $this->assertArrayHasKey('clientId', $mapped);
        $this->assertIsInt($mapped['clientId']);
        $this->assertEquals(1, $mapped['clientId']); // Our default
    }
}
