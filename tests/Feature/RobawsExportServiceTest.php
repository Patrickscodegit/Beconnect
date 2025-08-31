<?php

namespace Tests\Feature;

u        $mock->shouldReceive('uploadDocument')
            ->once()
            ->withArgs(function($offerId, $fileData) {
                return $offerId === 'offer-123' && 
                       isset($fileData['filename']) && 
                       is_resource($fileData['stream']);
            })
            ->andReturn([
                'document' => ['id'=>101, 'name'=>'mail.eml', 'mime'=>'message/rfc822', 'size'=>strlen($body)],
            ]);s\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use App\Services\RobawsExportService;
use App\Services\RobawsClient;
use Mockery;

class RobawsExportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test small file hashing and normalized response
     */
    public function test_upload_hash_small_file_and_normalizes_response()
    {
        Storage::fake('documents');
        $body = "From: a@example.com\r\n\r\nHello";
        Storage::disk('documents')->put('mail.eml', $body);

        $mock = Mockery::mock(RobawsClient::class);
        $this->app->instance(RobawsClient::class, $mock);
        
        // Mock the client method that the service actually calls
        $mock->shouldReceive('uploadFileToOffer')
            ->once()
            ->andReturn([
                'status'   => 'ok',
                'file_id'  => 101,
                'document' => ['id'=>101, 'name'=>'mail.eml', 'mime'=>'message/rfc822', 'size'=>strlen($body)],
            ]);

        // Mock the remote file check
        $mock->shouldReceive('getOfferFiles')
            ->once()
            ->andReturn([]);

        $svc = app(RobawsExportService::class);
        
        // Create a dummy document to work with
        $document = new \App\Models\Document([
            'file_path' => 'mail.eml',
            'original_filename' => 'mail.eml',
            'file_name' => 'mail.eml',
            'mime_type' => 'message/rfc822'
        ]);
        $document->id = 1;

        $res = $svc->uploadDocumentToRobaws($document, 'offer-123');

        $this->assertSame('uploaded', $res['status']);
        $this->assertSame(101, $res['document']['id']);
        $this->assertSame('message/rfc822', $res['document']['mime']);
        $this->assertSame(strlen($body), $res['document']['size']);
        $this->assertSame(hash('sha256', $body), $res['document']['sha256']);
    }

    /**
     * Test large file streaming hash computation
     */
    public function test_upload_hash_large_file_streaming()
    {
        Storage::fake('documents');
        $chunk = str_repeat('Z', 1024 * 1024); // 1 MB
        $body  = str_repeat($chunk, 3) . 'tail'; // ~3MB+
        Storage::disk('documents')->put('big.eml', $body);

        $mock = Mockery::mock(RobawsClient::class);
        $this->app->instance(RobawsClient::class, $mock);
        
        $mock->shouldReceive('uploadFileToOffer')
            ->once()
            ->andReturn(['code'=>201, 'id'=>202, 'file_id'=>202]);

        $mock->shouldReceive('getOfferFiles')
            ->once()
            ->andReturn([]);

        $svc = app(RobawsExportService::class);
        
        $document = new \App\Models\Document([
            'file_path' => 'big.eml',
            'original_filename' => 'big.eml',
            'file_name' => 'big.eml',
            'mime_type' => 'message/rfc822'
        ]);
        $document->id = 1;

        $res = $svc->uploadDocumentToRobaws($document, 'offer-9');

        $this->assertSame('uploaded', $res['status']);
        $this->assertSame(hash('sha256', $body), $res['document']['sha256']);
        $this->assertSame(strlen($body), $res['document']['size']);
        $this->assertSame(202, $res['document']['id']);
    }

    /**
     * Test error path still returns sha256 and size
     */
    public function test_upload_error_still_returns_sha_and_size()
    {
        Storage::fake('documents');
        $body = str_repeat('Q', 777);
        Storage::disk('documents')->put('x.eml', $body);

        $mock = Mockery::mock(RobawsClient::class);
        $this->app->instance(RobawsClient::class, $mock);
        
        $mock->shouldReceive('getOfferFiles')
            ->once()
            ->andReturn([]);
            
        $mock->shouldReceive('uploadFileToOffer')
            ->once()
            ->andThrow(new \RuntimeException('Boom'));

        $svc = app(RobawsExportService::class);
        
        $document = new \App\Models\Document([
            'file_path' => 'x.eml',
            'original_filename' => 'x.eml',
            'file_name' => 'x.eml',
            'mime_type' => 'message/rfc822'
        ]);
        $document->id = 1;

        $res = $svc->uploadDocumentToRobaws($document, 'offer-1');

        $this->assertSame('failed', $res['status']);
        $this->assertStringContainsString('Boom', $res['reason']);
        $this->assertSame(hash('sha256', $body), $res['document']['sha256']);
        $this->assertSame(strlen($body), $res['document']['size']);
    }

    /**
     * Test file not found scenario
     */
    public function test_upload_file_not_found()
    {
        Storage::fake('documents');
        // Don't create the file

        $svc = app(RobawsExportService::class);
        
        $document = new \App\Models\Document([
            'file_path' => 'missing.eml',
            'original_filename' => 'missing.eml',
            'file_name' => 'missing.eml',
            'mime_type' => 'message/rfc822'
        ]);
        $document->id = 1;

        $res = $svc->uploadDocumentToRobaws($document, 'offer-1');

        $this->assertSame('error', $res['status']);
        $this->assertStringContainsString('File not found', $res['reason']);
        $this->assertSame('missing.eml', $res['filename']);
    }

    /**
     * Test that existing documents are detected via local ledger
     */
    public function test_upload_detects_existing_via_local_ledger()
    {
        Storage::fake('documents');
        $body = "From: test@example.com\r\n\r\nTest content";
        Storage::disk('documents')->put('test.eml', $body);

        // Create an existing RobawsDocument record
        $existingRecord = \App\Models\RobawsDocument::create([
            'document_id' => 1,
            'robaws_offer_id' => 'offer-123',
            'robaws_document_id' => 'existing-doc-id',
            'sha256' => hash('sha256', $body),
            'filename' => 'test.eml',
            'filesize' => strlen($body)
        ]);

        $svc = app(RobawsExportService::class);
        
        $document = new \App\Models\Document([
            'file_path' => 'test.eml',
            'original_filename' => 'test.eml',
            'file_name' => 'test.eml',
            'mime_type' => 'message/rfc822'
        ]);
        $document->id = 1;

        $res = $svc->uploadDocumentToRobaws($document, 'offer-123');

        $this->assertSame('exists', $res['status']);
        $this->assertStringContainsString('local ledger', $res['reason']);
        $this->assertSame('existing-doc-id', $res['document']['id']);
        $this->assertSame(hash('sha256', $body), $res['document']['sha256']);
    }
}
