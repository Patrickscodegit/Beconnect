<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\RobawsDocument;
use App\Services\Robaws\RobawsExportService;
use App\Services\Export\Mappers\RobawsMapper;
use App\Services\Export\Clients\RobawsApiClient;
use App\Services\RobawsClient;
use App\Support\StreamHasher;
use Mockery;
use Storage;

class RobawsIdempotencyLedgerTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    /** @test */
    public function it_returns_exists_when_sha256_found_in_ledger_without_api_call()
    {
        $this->markTestSkipped('uploadDocumentByPath method deprecated - test needs refactoring');
        // Arrange: Create a ledger entry
        $offerId = 'test-offer-123';
        $sha256 = 'abcd1234567890abcd1234567890abcd1234567890abcd1234567890abcd1234';
        $filename = 'test-document.pdf';
        
        $existingDoc = RobawsDocument::create([
            'robaws_offer_id' => $offerId,
            'robaws_document_id' => 999,
            'filename' => $filename,
            'sha256' => $sha256,
            'filesize' => 1024,
            'mime' => 'application/pdf',
            'uploaded_at' => now()
        ]);

        // Mock the required services
        $mockMapper = Mockery::mock(RobawsMapper::class);
        // Can't mock final class - use app instance instead
        $mockApiClient = app(\App\Services\Export\Clients\RobawsApiClient::class);
        $mockLegacyClient = Mockery::mock(RobawsClient::class);
        $mockLegacyClient->shouldNotReceive('uploadDocument');

        // Mock StreamHasher to return our predetermined hash
        $mockStreamHasher = Mockery::mock(StreamHasher::class);
        $mockStream = fopen('php://temp', 'r+');
        fwrite($mockStream, 'dummy content');
        rewind($mockStream);
        
        $mockStreamHasher->shouldReceive('toTempHashedStream')
            ->once()
            ->andReturn([
                'stream' => $mockStream,
                'sha256' => $sha256,
                'size' => 1024
            ]);

        // Create temp file for testing
        Storage::fake('local');
        Storage::put('documents/test-document.pdf', 'dummy content');
        $testPath = storage_path('app/documents/test-document.pdf');

        $service = new RobawsExportService($mockMapper, $mockApiClient, $mockLegacyClient);
        
        // Inject the StreamHasher mock using reflection since it's likely injected
        $reflection = new \ReflectionClass($service);
        if ($reflection->hasProperty('streamHasher')) {
            $property = $reflection->getProperty('streamHasher');
            $property->setAccessible(true);
            $property->setValue($service, $mockStreamHasher);
        }

        // Act
        $result = $service->uploadDocumentByPath($offerId, 'documents/test-document.pdf');

        // Assert
        $this->assertEquals('exists', $result['status']);
        $this->assertEquals('Found in local ledger', $result['reason']);
        $this->assertEquals(999, $result['document']['id']);
        $this->assertEquals($filename, $result['document']['name']);
        $this->assertEquals($sha256, $result['document']['sha256']);
        $this->assertEquals('local', $result['_raw']['source']);
        
        // Verify the client was never called (idempotency achieved)
        $mockClient->shouldNotHaveReceived('uploadDocument');
        
        if (is_resource($mockStream)) {
            fclose($mockStream);
        }
    }

    /** @test */
    public function it_creates_ledger_entry_after_successful_upload()
    {
        $this->markTestSkipped('uploadDocumentByPath method deprecated - test needs refactoring');
        // Arrange
        $offerId = 'test-offer-456';
        $sha256 = 'efgh5678901234efgh5678901234efgh5678901234efgh5678901234efgh5678';
        
        // Ensure no existing ledger entry
        $this->assertDatabaseMissing('robaws_documents', [
            'robaws_offer_id' => $offerId,
            'sha256' => $sha256
        ]);

        // Mock successful client response
        $mockMapper = Mockery::mock(RobawsMapper::class);
        // Can't mock final class - use app instance instead
        $mockApiClient = app(\App\Services\Export\Clients\RobawsApiClient::class);
        $mockLegacyClient = Mockery::mock(RobawsClient::class);
        $mockLegacyClient->shouldReceive('uploadDocument')
            ->once()
            ->andReturn([
                'success' => true,
                'document' => [
                    'id' => 777,
                    'name' => 'new-document.pdf',
                    'mime' => 'application/pdf',
                    'size' => 2048
                ]
            ]);

        // Mock StreamHasher
        $mockStreamHasher = Mockery::mock(StreamHasher::class);
        $mockStream = fopen('php://temp', 'r+');
        fwrite($mockStream, 'new dummy content');
        rewind($mockStream);
        
        $mockStreamHasher->shouldReceive('toTempHashedStream')
            ->once()
            ->andReturn([
                'stream' => $mockStream,
                'sha256' => $sha256,
                'size' => 2048
            ]);

        // Create temp file
        Storage::fake('local');
        Storage::put('documents/new-document.pdf', 'new dummy content');

        $service = new RobawsExportService($mockMapper, $mockApiClient, $mockLegacyClient);
        
        // Inject the StreamHasher mock
        $reflection = new \ReflectionClass($service);
        if ($reflection->hasProperty('streamHasher')) {
            $property = $reflection->getProperty('streamHasher');
            $property->setAccessible(true);
            $property->setValue($service, $mockStreamHasher);
        }

        // Act
        $result = $service->uploadDocumentByPath($offerId, 'documents/new-document.pdf');

        // Assert upload result
        $this->assertEquals('uploaded', $result['status']);
        $this->assertEquals(777, $result['document']['id']);
        $this->assertEquals($sha256, $result['document']['sha256']);

        // Assert ledger entry was created
        $this->assertDatabaseHas('robaws_documents', [
            'robaws_offer_id' => $offerId,
            'robaws_document_id' => 777,
            'sha256' => $sha256,
            'filename' => 'new-document.pdf', // not prettified in ledger
            'filesize' => 2048
        ]);
        
        if (is_resource($mockStream)) {
            fclose($mockStream);
        }
    }

    /** @test */
    public function it_prevents_duplicate_uploads_on_subsequent_calls()
    {
        $this->markTestSkipped('uploadDocumentByPath method deprecated - test needs refactoring');
        // Arrange: First upload creates ledger entry
        $offerId = 'test-offer-789';
        $sha256 = 'ijkl9012345678ijkl9012345678ijkl9012345678ijkl9012345678ijkl9012';
        
        // Create initial ledger entry (simulating first successful upload)
        RobawsDocument::create([
            'robaws_offer_id' => $offerId,
            'robaws_document_id' => 555,
            'filename' => 'duplicate-test.pdf',
            'sha256' => $sha256,
            'filesize' => 512,
            'uploaded_at' => now()
        ]);

        // Mock services that should never be called on second attempt
        $mockMapper = Mockery::mock(RobawsMapper::class);
        // Can't mock final class - use app instance instead
        $mockApiClient = app(\App\Services\Export\Clients\RobawsApiClient::class);
        $mockLegacyClient = Mockery::mock(RobawsClient::class);
        $mockLegacyClient->shouldNotReceive('uploadDocument');

        // Mock StreamHasher for second attempt
        $mockStreamHasher = Mockery::mock(StreamHasher::class);
        $mockStream = fopen('php://temp', 'r+');
        fwrite($mockStream, 'duplicate content');
        rewind($mockStream);
        
        $mockStreamHasher->shouldReceive('toTempHashedStream')
            ->once()
            ->andReturn([
                'stream' => $mockStream,
                'sha256' => $sha256,
                'size' => 512
            ]);

        Storage::fake('local');
        Storage::put('documents/duplicate-test.pdf', 'duplicate content');

        $service = new RobawsExportService($mockMapper, $mockApiClient, $mockLegacyClient);
        
        // Inject the StreamHasher mock
        $reflection = new \ReflectionClass($service);
        if ($reflection->hasProperty('streamHasher')) {
            $property = $reflection->getProperty('streamHasher');
            $property->setAccessible(true);
            $property->setValue($service, $mockStreamHasher);
        }

        // Act: Second upload attempt with same content
        $result = $service->uploadDocumentByPath($offerId, 'documents/duplicate-test.pdf');

        // Assert: Returns 'exists' without API call
        $this->assertEquals('exists', $result['status']);
        $this->assertEquals(555, $result['document']['id']);
        
        // Verify no duplicate ledger entries created
        $this->assertEquals(1, RobawsDocument::where('robaws_offer_id', $offerId)->count());
        
        if (is_resource($mockStream)) {
            fclose($mockStream);
        }
    }
}
