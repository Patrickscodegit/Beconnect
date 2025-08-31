<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Intake;
use App\Services\Robaws\RobawsExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RobawsExportServiceCanonicalResponseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that exportIntake returns canonical shape
     */
    public function test_export_intake_returns_canonical_shape(): void
    {
        $intake = Intake::factory()->create();
        
        $service = app(RobawsExportService::class);
        $result = $service->exportIntake($intake);
        
        // Assert canonical structure exists
        foreach (['success', 'failed', 'uploaded', 'exists', 'skipped', 'stats'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
        
        // Assert all buckets are arrays
        $this->assertIsArray($result['success']);
        $this->assertIsArray($result['failed']);
        $this->assertIsArray($result['uploaded']);
        $this->assertIsArray($result['exists']);
        $this->assertIsArray($result['skipped']);
        
        // Assert stats structure
        $this->assertIsArray($result['stats']);
        foreach (['success', 'failed', 'uploaded', 'exists', 'skipped'] as $key) {
            $this->assertArrayHasKey($key, $result['stats'], "Missing stats key: {$key}");
            $this->assertIsInt($result['stats'][$key], "Stats key {$key} should be integer");
        }
    }
    
    /**
     * Test that stats are calculated correctly from buckets
     */
    public function test_stats_match_bucket_counts(): void
    {
        $intake = Intake::factory()->create();
        
        $service = app(RobawsExportService::class);
        $result = $service->exportIntake($intake);
        
        // Verify stats match actual bucket counts
        $this->assertEquals(count($result['success']), $result['stats']['success']);
        $this->assertEquals(count($result['failed']), $result['stats']['failed']);
        $this->assertEquals(count($result['uploaded']), $result['stats']['uploaded']);
        $this->assertEquals(count($result['exists']), $result['stats']['exists']);
        $this->assertEquals(count($result['skipped']), $result['stats']['skipped']);
    }
    
    /**
     * Test failure structure includes required fields
     */
    public function test_failure_entries_have_required_structure(): void
    {
        $intake = Intake::factory()->create();
        
        $service = app(RobawsExportService::class);
        $result = $service->exportIntake($intake);
        
        // There should be at least one failure (no approved documents)
        $this->assertGreaterThan(0, count($result['failed']));
        
        foreach ($result['failed'] as $failure) {
            $this->assertArrayHasKey('id', $failure);
            $this->assertArrayHasKey('type', $failure);
            $this->assertArrayHasKey('message', $failure);
            $this->assertArrayHasKey('meta', $failure);
            $this->assertIsString($failure['message']);
        }
    }
}
