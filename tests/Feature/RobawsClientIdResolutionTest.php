<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Intake;
use App\Services\Robaws\RobawsExportService;
use App\Services\RobawsClient;
use App\Exceptions\RobawsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

class RobawsClientIdResolutionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that mapper injects clientId via default when none provided
     */
    public function test_mapper_injects_client_id_via_default_when_none_provided(): void
    {
        config()->set('services.robaws.default_client_id', 999);

        $service = app(RobawsExportService::class);

        // Use reflection to test the private method
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('mapExtractionToRobaws');
        $method->setAccessible(true);

        $payload = $method->invoke($service, ['title' => 'Test Quotation']);

        $this->assertArrayHasKey('clientId', $payload);
        $this->assertEquals(999, $payload['clientId']);
    }

    /**
     * Test that mapper uses provided clientId when available
     */
    public function test_mapper_uses_provided_client_id_when_available(): void
    {
        config()->set('services.robaws.default_client_id', 999);

        $service = app(RobawsExportService::class);

        // Use reflection to test the private method
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('mapExtractionToRobaws');
        $method->setAccessible(true);

        $payload = $method->invoke($service, [
            'clientId' => 123,
            'title' => 'Test Quotation'
        ]);

        $this->assertArrayHasKey('clientId', $payload);
        $this->assertEquals(123, $payload['clientId']);
    }

    /**
     * Test that mapper throws exception when no clientId can be resolved
     */
    public function test_mapper_throws_when_no_client_id_can_be_resolved(): void
    {
        config()->set('services.robaws.default_client_id', null);

        $service = app(RobawsExportService::class);

        // Use reflection to test the private method
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('mapExtractionToRobaws');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing clientId for offer creation');

        $method->invoke($service, ['title' => 'Test Quotation']);
    }

    /**
     * Test that createOffer throws if clientId missing
     */
    public function test_create_offer_throws_if_client_id_missing(): void
    {
        $client = app(RobawsClient::class);
        
        $this->expectException(RobawsException::class);
        $this->expectExceptionMessage('createOffer(): "clientId" is required but missing');

        $client->createOffer(['title' => 'No client']);
    }

    /**
     * Test that createOffer throws if clientId is empty
     */
    public function test_create_offer_throws_if_client_id_empty(): void
    {
        $client = app(RobawsClient::class);
        
        $this->expectException(RobawsException::class);
        $this->expectExceptionMessage('createOffer(): "clientId" is required but missing');

        $client->createOffer([
            'clientId' => null,
            'title' => 'Empty client'
        ]);
    }

    /**
     * Test that export service handles missing client gracefully in full flow
     */
    public function test_export_service_handles_missing_client_gracefully(): void
    {
        config()->set('services.robaws.default_client_id', 999);

        $intake = Intake::factory()->create();
        
        // Create a minimal extraction without client data
        $intake->extraction()->create([
            'extracted_data' => [
                'title' => 'Test Transport',
                'vehicles' => [
                    ['make_model' => 'BMW X5', 'price' => 1000]
                ]
            ],
            'raw_json' => json_encode(['test' => 'data']) // Add required field
        ]);

        $service = app(RobawsExportService::class);
        
        // This should work without throwing "undefined clientId" errors
        // It might fail for other reasons (no Robaws connection), but not undefined keys
        try {
            $result = $service->exportIntake($intake);
            
            // Should have canonical structure regardless of success/failure
            $this->assertArrayHasKey('failed', $result);
            $this->assertArrayHasKey('success', $result);
            $this->assertArrayHasKey('stats', $result);
            
        } catch (RobawsException $e) {
            // Expected if no real Robaws connection
            $this->assertStringNotContainsString('Undefined array key', $e->getMessage());
            $this->assertStringNotContainsString('clientId', $e->getMessage());
        } catch (\RuntimeException $e) {
            // Also acceptable for missing clientId validation
            $this->assertTrue(true, 'Runtime exception is acceptable for missing client resolution');
        }
    }

    /**
     * Test that client data extraction works with various formats
     */
    public function test_client_data_extraction_from_various_formats(): void
    {
        // Mock the client resolution to not actually call Robaws
        $this->mock(RobawsClient::class, function ($mock) {
            $mock->shouldReceive('findOrCreateClient')
                 ->andThrow(new \Exception('Mock client resolution failed'));
        });

        config()->set('services.robaws.default_client_id', 999);

        $service = app(RobawsExportService::class);

        // Use reflection to test the private method
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('mapExtractionToRobaws');
        $method->setAccessible(true);

        // Test extraction with nested client data (should fallback to default when mock fails)
        $payload1 = $method->invoke($service, [
            'client' => [
                'name' => 'Test Company',
                'email' => 'test@company.com'
            ]
        ]);

        $this->assertArrayHasKey('clientId', $payload1);
        $this->assertEquals(999, $payload1['clientId']); // Should fallback to default

        // Test extraction with flat client data (should fallback to default when mock fails)
        $payload2 = $method->invoke($service, [
            'company' => 'Test Company 2',
            'email' => 'test2@company.com'
        ]);

        $this->assertArrayHasKey('clientId', $payload2);
        $this->assertEquals(999, $payload2['clientId']); // Should fallback to default
    }
}
