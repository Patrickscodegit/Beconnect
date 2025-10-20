<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\RobawsWebhookLog;
use App\Models\RobawsArticleCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class WebhookIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create webhook configuration
        DB::table('webhook_configurations')->insert([
            'provider' => 'robaws',
            'webhook_id' => 'test-webhook-123',
            'secret' => 'test-secret-key',
            'url' => 'http://localhost/api/webhooks/robaws/articles',
            'events' => json_encode(['article.created', 'article.updated', 'article.stock-changed']),
            'is_active' => true,
            'registered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test */
    public function it_rejects_webhook_without_signature()
    {
        $response = $this->postJson('/api/webhooks/robaws/articles', [
            'event' => 'article.updated',
            'id' => 'webhook-123',
            'data' => [
                'id' => 'ART-123',
                'name' => 'Test Article',
            ],
        ]);

        $response->assertStatus(401);
        $this->assertDatabaseMissing('robaws_webhook_logs', [
            'robaws_id' => 'webhook-123',
        ]);
    }

    /** @test */
    public function it_rejects_webhook_with_invalid_signature()
    {
        $response = $this->postJson('/api/webhooks/robaws/articles', [
            'event' => 'article.updated',
            'id' => 'webhook-123',
            'data' => [
                'id' => 'ART-123',
                'name' => 'Test Article',
            ],
        ], [
            'Robaws-Signature' => 't=' . time() . ',v1=invalidsignature',
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function it_rejects_webhook_with_old_timestamp()
    {
        $oldTimestamp = time() - 400; // 6+ minutes ago
        $payload = json_encode([
            'event' => 'article.updated',
            'id' => 'webhook-123',
            'data' => ['id' => 'ART-123'],
        ]);
        
        $signedPayload = $oldTimestamp . '.' . $payload;
        $signature = hash_hmac('sha256', $signedPayload, 'test-secret-key');

        $response = $this->withHeaders([
            'Robaws-Signature' => "t={$oldTimestamp},v1={$signature}",
        ])->postJson('/api/webhooks/robaws/articles', json_decode($payload, true));

        $response->assertStatus(401);
    }

    /** @test */
    public function it_accepts_webhook_with_valid_signature()
    {
        $timestamp = time();
        $payload = [
            'event' => 'article.updated',
            'id' => 'webhook-123',
            'data' => [
                'id' => 'ART-123',
                'code' => 'TEST-CODE',
                'name' => 'Test Article - Seafreight ACL Export',
                'description' => 'Test article',
                'unitPrice' => 100.00,
                'isActive' => true,
            ],
        ];
        
        $payloadJson = json_encode($payload);
        $signedPayload = $timestamp . '.' . $payloadJson;
        $signature = hash_hmac('sha256', $signedPayload, 'test-secret-key');

        $response = $this->withHeaders([
            'Robaws-Signature' => "t={$timestamp},v1={$signature}",
            'Content-Type' => 'application/json',
        ])->postJson('/api/webhooks/robaws/articles', $payload);

        $response->assertStatus(200);
        
        // Check webhook log was created
        $this->assertDatabaseHas('robaws_webhook_logs', [
            'robaws_id' => 'webhook-123',
            'event_type' => 'article.updated',
            'status' => 'processed',
        ]);
    }

    /** @test */
    public function it_creates_article_from_webhook()
    {
        $timestamp = time();
        $payload = [
            'event' => 'article.created',
            'id' => 'webhook-create-123',
            'data' => [
                'id' => 'ART-NEW-123',
                'code' => 'NEW-CODE',
                'name' => 'New Article - Seafreight MSC Import',
                'description' => 'New test article',
                'unitPrice' => 150.00,
                'isActive' => true,
            ],
        ];
        
        $payloadJson = json_encode($payload);
        $signedPayload = $timestamp . '.' . $payloadJson;
        $signature = hash_hmac('sha256', $signedPayload, 'test-secret-key');

        $response = $this->withHeaders([
            'Robaws-Signature' => "t={$timestamp},v1={$signature}",
        ])->postJson('/api/webhooks/robaws/articles', $payload);

        $response->assertStatus(200);
        
        // Check article was created
        $this->assertDatabaseHas('robaws_articles_cache', [
            'robaws_article_id' => 'ART-NEW-123',
            'article_code' => 'NEW-CODE',
            'article_name' => 'New Article - Seafreight MSC Import',
        ]);
    }

    /** @test */
    public function it_updates_existing_article_from_webhook()
    {
        // Create existing article
        $article = RobawsArticleCache::create([
            'robaws_article_id' => 'ART-EXISTING',
            'article_code' => 'OLD-CODE',
            'article_name' => 'Old Article Name',
            'description' => 'Old description',
            'category' => 'seafreight',
            'unit_price' => 100.00,
            'is_active' => true,
            'last_synced_at' => now(),
        ]);

        $timestamp = time();
        $payload = [
            'event' => 'article.updated',
            'id' => 'webhook-update-123',
            'data' => [
                'id' => 'ART-EXISTING',
                'code' => 'UPDATED-CODE',
                'name' => 'Updated Article Name - Seafreight Grimaldi',
                'description' => 'Updated description',
                'unitPrice' => 200.00,
                'isActive' => true,
            ],
        ];
        
        $payloadJson = json_encode($payload);
        $signedPayload = $timestamp . '.' . $payloadJson;
        $signature = hash_hmac('sha256', $signedPayload, 'test-secret-key');

        $response = $this->withHeaders([
            'Robaws-Signature' => "t={$timestamp},v1={$signature}",
        ])->postJson('/api/webhooks/robaws/articles', $payload);

        $response->assertStatus(200);
        
        // Check article was updated
        $article->refresh();
        $this->assertEquals('UPDATED-CODE', $article->article_code);
        $this->assertEquals('Updated Article Name - Seafreight Grimaldi', $article->article_name);
        $this->assertEquals(200.00, $article->unit_price);
    }

    /** @test */
    public function it_logs_webhook_processing_duration()
    {
        $timestamp = time();
        $payload = [
            'event' => 'article.updated',
            'id' => 'webhook-duration-test',
            'data' => [
                'id' => 'ART-DURATION',
                'code' => 'DUR-CODE',
                'name' => 'Duration Test Article',
                'unitPrice' => 100.00,
            ],
        ];
        
        $payloadJson = json_encode($payload);
        $signedPayload = $timestamp . '.' . $payloadJson;
        $signature = hash_hmac('sha256', $signedPayload, 'test-secret-key');

        $this->withHeaders([
            'Robaws-Signature' => "t={$timestamp},v1={$signature}",
        ])->postJson('/api/webhooks/robaws/articles', $payload);

        $log = RobawsWebhookLog::where('robaws_id', 'webhook-duration-test')->first();
        
        $this->assertNotNull($log);
        $this->assertNotNull($log->processing_duration_ms);
        $this->assertIsInt($log->processing_duration_ms);
        $this->assertGreaterThanOrEqual(0, $log->processing_duration_ms); // Can be 0 in fast tests
    }

    /** @test */
    public function it_links_webhook_log_to_article()
    {
        $timestamp = time();
        $payload = [
            'event' => 'article.updated',
            'id' => 'webhook-link-test',
            'data' => [
                'id' => 'ART-LINK',
                'code' => 'LINK-CODE',
                'name' => 'Link Test Article',
                'unitPrice' => 100.00,
            ],
        ];
        
        $payloadJson = json_encode($payload);
        $signedPayload = $timestamp . '.' . $payloadJson;
        $signature = hash_hmac('sha256', $signedPayload, 'test-secret-key');

        $this->withHeaders([
            'Robaws-Signature' => "t={$timestamp},v1={$signature}",
        ])->postJson('/api/webhooks/robaws/articles', $payload);

        $log = RobawsWebhookLog::where('robaws_id', 'webhook-link-test')->first();
        $article = RobawsArticleCache::where('robaws_article_id', 'ART-LINK')->first();
        
        $this->assertNotNull($log);
        $this->assertNotNull($article);
        $this->assertEquals($article->id, $log->article_id);
    }

    /** @test */
    public function it_respects_rate_limiting()
    {
        $timestamp = time();
        $payload = [
            'event' => 'article.updated',
            'id' => 'webhook-rate-test',
            'data' => ['id' => 'ART-RATE'],
        ];
        
        $payloadJson = json_encode($payload);
        $signedPayload = $timestamp . '.' . $payloadJson;
        $signature = hash_hmac('sha256', $signedPayload, 'test-secret-key');

        // Send 61 requests (should hit rate limit at 60)
        for ($i = 0; $i < 61; $i++) {
            $response = $this->withHeaders([
                'Robaws-Signature' => "t={$timestamp},v1={$signature}",
            ])->postJson('/api/webhooks/robaws/articles', $payload);
            
            if ($i < 60) {
                $this->assertEquals(200, $response->status(), "Request {$i} should succeed");
            } else {
                $this->assertEquals(429, $response->status(), "Request {$i} should be rate limited");
            }
        }
    }

    /** @test */
    public function it_handles_webhook_processing_errors_gracefully()
    {
        $timestamp = time();
        // Send malformed data to trigger error
        $payload = [
            'event' => 'article.updated',
            'id' => 'webhook-error-test',
            'data' => [], // Missing required fields
        ];
        
        $payloadJson = json_encode($payload);
        $signedPayload = $timestamp . '.' . $payloadJson;
        $signature = hash_hmac('sha256', $signedPayload, 'test-secret-key');

        $response = $this->withHeaders([
            'Robaws-Signature' => "t={$timestamp},v1={$signature}",
        ])->postJson('/api/webhooks/robaws/articles', $payload);

        // Should still return 200 to prevent Robaws retries
        $response->assertStatus(200);
        
        // But log should show failure
        $log = RobawsWebhookLog::where('robaws_id', 'webhook-error-test')->first();
        $this->assertNotNull($log);
        $this->assertEquals('failed', $log->status);
        $this->assertNotNull($log->error_message);
    }
}

