<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use App\Models\RobawsArticleCache;
use App\Services\Robaws\RobawsArticleProvider;
use App\Services\Export\Clients\RobawsApiClient;
use Mockery;

class ResilientArticleMetadataSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_article_metadata_uses_api_when_available()
    {
        // Create test article manually
        $article = RobawsArticleCache::create([
            'robaws_article_id' => 'TEST123',
            'article_code' => 'TEST123',
            'article_name' => 'MSC FCL EXPORT ST 332',
            'description' => 'Test article',
            'category' => 'test',
            'is_active' => true,
            'last_synced_at' => now(),
        ]);

        // Mock the RobawsArticleProvider to return API data
        $provider = Mockery::mock(RobawsArticleProvider::class)->makePartial();
        $provider->shouldReceive('getArticleDetails')
            ->once()
            ->with('TEST123')
            ->andReturn([
                'extraFields' => [
                    'SHIPPING LINE' => [
                        'type' => 'SELECT',
                        'stringValue' => 'MSC'
                    ],
                    'SERVICE TYPE' => [
                        'type' => 'SELECT',
                        'stringValue' => 'FCL EXPORT'
                    ],
                    'POL TERMINAL' => [
                        'type' => 'SELECT',
                        'stringValue' => 'ST 332'
                    ],
                    'PARENT ITEM' => [
                        'type' => 'CHECKBOX',
                        'booleanValue' => true
                    ],
                    'UPDATE DATE' => [
                        'type' => 'TEXT',
                        'stringValue' => '2024-01-01'
                    ],
                    'VALIDITY DATE' => [
                        'type' => 'TEXT',
                        'stringValue' => '2024-12-31'
                    ]
                ],
                'description' => 'Test article',
                'name' => 'MSC FCL EXPORT ST 332'
            ]);

        // Call syncArticleMetadata
        $result = $provider->syncArticleMetadata($article->id);

        // Verify API data was used
        $this->assertEquals('MSC', $result['shipping_line']);
        $this->assertEquals('FCL EXPORT', $result['service_type']);
        $this->assertEquals('ST 332', $result['pol_terminal']);
        $this->assertTrue($result['is_parent_item']);
        $this->assertEquals('2024-01-01', $result['update_date']);
        $this->assertEquals('2024-12-31', $result['validity_date']);
    }

    public function test_sync_article_metadata_uses_fallback_when_api_fails()
    {
        // Create test article with descriptive name
        $article = RobawsArticleCache::create([
            'robaws_article_id' => 'TEST123',
            'article_code' => 'TEST123',
            'article_name' => 'MSC FCL EXPORT ST 332 COMPLETE PACKAGE',
            'description' => 'Test article',
            'category' => 'test',
            'is_active' => true,
            'last_synced_at' => now(),
        ]);

        // Mock the RobawsArticleProvider to return null (API failure)
        $provider = Mockery::mock(RobawsArticleProvider::class)->makePartial();
        $provider->shouldReceive('getArticleDetails')
            ->once()
            ->with('TEST123')
            ->andReturn(null);

        // Call syncArticleMetadata
        $result = $provider->syncArticleMetadata($article->id);

        // Verify fallback extraction was used
        $this->assertEquals('MSC', $result['shipping_line']);
        $this->assertEquals('FCL EXPORT', $result['service_type']);
        $this->assertEquals('ST 332', $result['pol_terminal']);
        // Parent status cannot be determined from description alone
        // Only the Robaws API "PARENT ITEM" checkbox is authoritative
        $this->assertNull($result['is_parent_item']); // Unknown when API unavailable
        $this->assertNull($result['update_date']); // Cannot extract from description
        $this->assertNull($result['validity_date']); // Cannot extract from description
        $this->assertEquals('Extracted from description (API unavailable)', $result['article_info']);
    }

    public function test_sync_composite_items_gracefully_handles_api_failure()
    {
        // Create test parent article
        $parent = RobawsArticleCache::create([
            'robaws_article_id' => 'PARENT123',
            'article_code' => 'PARENT123',
            'article_name' => 'Complete Package',
            'description' => 'Test parent article',
            'category' => 'test',
            'is_active' => true,
            'last_synced_at' => now(),
        ]);

        // Mock the RobawsArticleProvider to return null (API failure)
        $provider = Mockery::mock(RobawsArticleProvider::class)->makePartial();
        $provider->shouldReceive('getArticleDetails')
            ->once()
            ->with('PARENT123')
            ->andReturn(null);

        // Should not throw exception
        $provider->syncCompositeItems($parent->id);

        // Verify no children were created (graceful failure)
        $this->assertEquals(0, $parent->children()->count());
    }

    public function test_extract_pol_terminal_from_description()
    {
        $provider = new RobawsArticleProvider(
            app(RobawsApiClient::class)
        );

        // Use reflection to access private method
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('extractPolTerminalFromDescription');
        $method->setAccessible(true);

        // Test various terminal patterns
        $this->assertEquals('ST 332', $method->invoke($provider, 'MSC FCL EXPORT ST 332'));
        $this->assertEquals('ST 740', $method->invoke($provider, 'Terminal 740 Package'));
        $this->assertEquals('ST 1234', $method->invoke($provider, 'ANR 1234 Service'));
        $this->assertNull($method->invoke($provider, 'No Terminal Info'));
    }

    public function test_is_parent_article_detection()
    {
        $provider = new RobawsArticleProvider(
            app(RobawsApiClient::class)
        );

        // Use reflection to access private method
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('isParentArticle');
        $method->setAccessible(true);

        // Test parent indicators (based on actual improved logic)
        // Parent = contains ('seafreight', 'fcl', 'lcl', 'roro', etc.) 
        //          AND NOT ('surcharge', 'additional', 'courrier', 'admin', 'customs', 'waiver', etc.)
        $this->assertTrue($method->invoke($provider, 'MSC Seafreight Service'));
        $this->assertTrue($method->invoke($provider, 'FCL Export Seafreight'));
        $this->assertTrue($method->invoke($provider, 'RORO Seafreight Transport'));
        $this->assertTrue($method->invoke($provider, 'Sallaum(ANR 332/740) Conakry Guinea, LM Seafreight')); // Real example
        $this->assertTrue($method->invoke($provider, 'Basic FCL Export LM')); // Contains 'fcl' = parent indicator
        $this->assertTrue($method->invoke($provider, 'LCL Container Service')); // Contains 'lcl'
        
        // Test non-parent articles (contains exclusions)
        $this->assertFalse($method->invoke($provider, 'Seafreight Surcharge')); // Contains 'surcharge'
        $this->assertFalse($method->invoke($provider, 'Additional Seafreight Fee')); // Contains 'additional'
        $this->assertFalse($method->invoke($provider, 'Courrier Seafreight')); // Contains 'courrier'
        $this->assertFalse($method->invoke($provider, 'FCL Handling Fee')); // Contains 'handling'
        $this->assertFalse($method->invoke($provider, 'Random Article Name')); // No parent indicators
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
