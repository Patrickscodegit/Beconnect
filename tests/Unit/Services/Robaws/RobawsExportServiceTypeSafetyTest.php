<?php

namespace Tests\Unit\Services\Robaws;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use App\Services\Robaws\RobawsExportService;
use App\Services\Export\Mappers\RobawsMapper;
use App\Services\Export\Clients\RobawsApiClient;
use App\Models\Intake;
use Mockery;
use ReflectionClass;

class RobawsExportServiceTypeSafetyTest extends TestCase
{
    use RefreshDatabase;

    private RobawsExportService $service;
    private RobawsMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock dependencies (avoiding final RobawsApiClient)
        $this->mapper = Mockery::mock(RobawsMapper::class);
        
        // Create a real RobawsApiClient for testing - we'll test the methods directly
        $config = [
            'api_url' => 'https://test.api.com',
            'api_username' => 'test',
            'api_password' => 'test',
        ];
        $apiClient = new RobawsApiClient($config);
        
        $this->service = new RobawsExportService($this->mapper, $apiClient);
    }

    public function test_validates_positive_integer_client_ids()
    {
        // Test valid client IDs
        $this->assertEquals(4046, $this->callValidateClientId(4046, 'test-export-1'));
        $this->assertEquals(123, $this->callValidateClientId('123', 'test-export-2'));
        $this->assertEquals(1, $this->callValidateClientId(1.0, 'test-export-3'));

        // Test invalid client IDs
        $this->assertNull($this->callValidateClientId(0, 'test-export-4'));
        $this->assertNull($this->callValidateClientId(-1, 'test-export-5'));
        $this->assertNull($this->callValidateClientId('invalid', 'test-export-6'));
        $this->assertNull($this->callValidateClientId('', 'test-export-7'));
        $this->assertNull($this->callValidateClientId(null, 'test-export-8'));
    }

    public function test_validates_and_sanitizes_email_addresses()
    {
        // Test valid emails
        $this->assertEquals('test@example.com', $this->callValidateAndSanitizeEmail('test@example.com'));
        $this->assertEquals('user@domain.co.uk', $this->callValidateAndSanitizeEmail('  USER@DOMAIN.CO.UK  '));
        
        // Test invalid emails
        $this->assertEquals('', $this->callValidateAndSanitizeEmail('invalid-email'));
        $this->assertEquals('', $this->callValidateAndSanitizeEmail(''));
        $this->assertEquals('', $this->callValidateAndSanitizeEmail(null));
        $this->assertEquals('', $this->callValidateAndSanitizeEmail('@domain.com'));
    }

    public function test_builds_type_safe_payload_with_valid_client_id()
    {
        Log::spy();

        $intake = Intake::factory()->create([
            'customer_name' => 'John Doe',
            'contact_email' => 'john@example.com',
            'contact_phone' => '+1234567890',
            'robaws_client_id' => 4046,
        ]);

        $extractionData = [
            'customerName' => 'John Doe',
            'contactEmail' => 'john@example.com',
            'customerPhone' => '+1234567890',
        ];

        $mapped = ['some' => 'data'];

        $this->mapper->shouldReceive('toRobawsApiPayload')
            ->once()
            ->with(array_merge($mapped, [
                'client_id' => 4046,
                'contact_email' => 'john@example.com',
            ]))
            ->andReturn([
                'clientId' => 4046,
                'contactEmail' => 'john@example.com',
                'some' => 'data',
            ]);

        $payload = $this->callBuildTypeSeafePayload($intake, $extractionData, $mapped, 'test-export-1');

        $this->assertArrayHasKey('clientId', $payload);
        $this->assertEquals(4046, $payload['clientId']);
        $this->assertEquals('john@example.com', $payload['contactEmail']);

        // Verify comprehensive logging
        Log::shouldHaveReceived('info')
            ->withArgs(function ($message, $context) {
                return $message === 'Type-safe client resolution' && 
                       $context['validated_client_id'] === 4046 &&
                       $context['binding_status'] === 'will_bind_to_client';
            });

        Log::shouldHaveReceived('info')
            ->withArgs(function ($message, $context) {
                return $message === 'Client ID validation successful' && 
                       $context['validated_client_id'] === 4046 &&
                       $context['validated_type'] === 'integer';
            });

        Log::shouldHaveReceived('info')
            ->withArgs(function ($message, $context) {
                return $message === 'Type-safe Robaws payload (api shape)' && 
                       $context['type_safety_applied'] === true;
            });
    }

    public function test_handles_missing_client_id_gracefully()
    {
        Log::spy();

        $intake = Intake::factory()->create([
            'customer_name' => 'Jane Doe',
            'contact_email' => 'jane@example.com',
            'robaws_client_id' => null,
        ]);

        $extractionData = [
            'customerName' => 'Jane Doe',
            'contactEmail' => 'jane@example.com',
        ];

        $mapped = ['some' => 'data'];

        $this->mapper->shouldReceive('toRobawsApiPayload')
            ->once()
            ->with($mapped) // No client_id or contact_email injected
            ->andReturn(['some' => 'data']);

        $payload = $this->callBuildTypeSeafePayload($intake, $extractionData, $mapped, 'test-export-2');

        $this->assertArrayNotHasKey('clientId', $payload);

        // Verify warning logged
        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context) {
                return $message === 'No unique client match found - export will proceed without client binding';
            });
    }

    public function test_handles_invalid_email_format()
    {
        Log::spy();

        $intake = Intake::factory()->create([
            'customer_name' => 'Test Customer',
            'contact_email' => 'invalid-email-format',
            'robaws_client_id' => 123,
        ]);

        $extractionData = [
            'customerName' => 'Test Customer',
            'contactEmail' => 'invalid-email-format',
        ];

        $mapped = ['some' => 'data'];

        $this->mapper->shouldReceive('toRobawsApiPayload')
            ->once()
            ->with(array_merge($mapped, [
                'client_id' => 123,
                'contact_email' => 'sales@truck-time.com', // Fallback email
            ]))
            ->andReturn([
                'clientId' => 123,
                'contactEmail' => 'sales@truck-time.com',
                'some' => 'data',
            ]);

        $payload = $this->callBuildTypeSeafePayload($intake, $extractionData, $mapped, 'test-export-3');

        $this->assertEquals('sales@truck-time.com', $payload['contactEmail']);

        // Verify email validation warning logged
        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context) {
                return $message === 'Invalid email format detected' && 
                       $context['original_email'] === 'invalid-email-format';
            });
    }

    public function test_handles_string_client_id_conversion()
    {
        Log::spy();

        // Test direct validation method instead of full payload build
        $validatedId = $this->callValidateClientId('4046', 'test-export-4');
        
        $this->assertEquals(4046, $validatedId);
        $this->assertIsInt($validatedId);

        // Verify client ID validation logged
        Log::shouldHaveReceived('info')
            ->withArgs(function ($message, $context) {
                return $message === 'Client ID validation successful' && 
                       $context['original_client_id'] === '4046' &&
                       $context['original_type'] === 'string' &&
                       $context['validated_client_id'] === 4046 &&
                       $context['validated_type'] === 'integer';
            });
    }

    public function test_rejects_non_numeric_client_id_strings()
    {
        Log::spy();

        $validatedId = $this->callValidateClientId('not-a-number', 'test-export-5');
        
        $this->assertNull($validatedId);

        // Verify error logged
        Log::shouldHaveReceived('error')
            ->withArgs(function ($message, $context) {
                return $message === 'Non-numeric client ID detected' && 
                       $context['client_id'] === 'not-a-number';
            });
    }

    /**
     * Helper method to call private validateClientId method
     */
    private function callValidateClientId($clientId, string $exportId): ?int
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('validateClientId');
        $method->setAccessible(true);

        return $method->invoke($this->service, $clientId, $exportId);
    }

    /**
     * Helper method to call private validateAndSanitizeEmail method
     */
    private function callValidateAndSanitizeEmail(?string $email): string
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('validateAndSanitizeEmail');
        $method->setAccessible(true);

        return $method->invoke($this->service, $email);
    }

    /**
     * Helper method to call private buildTypeSeafePayload method
     */
    private function callBuildTypeSeafePayload($intake, $extractionData, $mapped, $exportId): array
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('buildTypeSeafePayload');
        $method->setAccessible(true);

        return $method->invoke($this->service, $intake, $extractionData, $mapped, $exportId);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
