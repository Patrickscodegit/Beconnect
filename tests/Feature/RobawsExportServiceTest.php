<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use App\Services\RobawsClient;
use App\Services\RobawsExportService;
use App\Models\RobawsDocument;

class RobawsExportServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_uploads_new_document_and_normalizes_to_uploaded()
    {
        Storage::fake('documents');
        $body = "From: a@example.com\r\n\r\nHello";
        Storage::disk('documents')->put('t/mail.eml', $body);

        // Mock the real method the service calls
        $client = Mockery::mock(RobawsClient::class);
        $this->app->instance(RobawsClient::class, $client);

        $client->shouldReceive('uploadDocument')
            ->once()
            ->withArgs(function ($offerId, $fileData) use ($body) {
                $this->assertSame('999', (string) $offerId);
                $this->assertSame('mail.eml', $fileData['filename']);
                $this->assertSame('message/rfc822', $fileData['mime']);
                $this->assertTrue(is_resource($fileData['stream']));
                $this->assertSame(strlen($body), $fileData['size']);
                return true;
            })
            ->andReturn([
                'status'   => 'ok',
                'document' => [
                    'id'   => 77,
                    'name' => 'mail.eml',
                    'mime' => 'message/rfc822',
                    'size' => strlen($body),
                ],
            ]);

        $svc = app(RobawsExportService::class);
        $res = $svc->uploadDocumentToOffer(999, 'documents/t/mail.eml');

        $this->assertSame('uploaded', $res['status']);
        $this->assertSame(77, $res['document']['id']);
        $this->assertSame('message/rfc822', $res['document']['mime']);
        $this->assertSame(strlen($body), $res['document']['size']);
        $this->assertSame(hash('sha256', $body), $res['document']['sha256']);
    }

    /** @test */
    public function it_returns_exists_when_sha256_is_found_in_local_ledger()
    {
        Storage::fake('documents');
        $body = str_repeat('X', 321);
        Storage::disk('documents')->put('t/existing.eml', $body);
        $sha = hash('sha256', $body);

        // Seed local ledger row (adjust attributes to your model)
        RobawsDocument::query()->create([
            'robaws_offer_id' => 1234,
            'filename' => 'existing.eml',
            'filesize' => strlen($body),
            'sha256' => $sha,
            'robaws_document_id' => 555,
        ]);

        $svc = app(RobawsExportService::class);
        $res = $svc->uploadDocumentToOffer(1234, 'documents/t/existing.eml');

        $this->assertSame('exists', $res['status']);
        $this->assertStringContainsString('local ledger', $res['reason']);
        $this->assertSame(555, $res['document']['id']);
        $this->assertSame('message/rfc822', $res['document']['mime']);
        $this->assertSame(strlen($body), $res['document']['size']);
        $this->assertSame($sha, $res['document']['sha256']);
    }

    /** @test */
    public function it_returns_error_when_file_missing()
    {
        $svc = app(RobawsExportService::class);
        $res = $svc->uploadDocumentToOffer(1, 'documents/does/not/exist.eml');

        $this->assertSame('error', $res['status']);
        $this->assertArrayHasKey('error', $res);
        $this->assertNull($res['document']['mime']);
        $this->assertNull($res['document']['size']);
        $this->assertNull($res['document']['sha256']);
    }

    /** @test */
    public function it_returns_error_when_client_throws_but_keeps_sha_and_size()
    {
        Storage::fake('documents');
        $body = str_repeat('Q', 777);
        Storage::disk('documents')->put('t/x.eml', $body);

        $client = Mockery::mock(RobawsClient::class);
        $this->app->instance(RobawsClient::class, $client);

        $client->shouldReceive('uploadDocument')
            ->once()
            ->andThrow(new \RuntimeException('Boom'));

        $svc = app(RobawsExportService::class);
        $res = $svc->uploadDocumentToOffer(1, 'documents/t/x.eml');

        $this->assertSame('error', $res['status']);
        $this->assertSame('Boom', $res['error']);
        $this->assertSame(hash('sha256', $body), $res['document']['sha256']);
        $this->assertSame(strlen($body), $res['document']['size']);
    }
}
