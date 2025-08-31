<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Intake;
use Illuminate\Foundation\Testing\RefreshDatabase;

class FilamentExportIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that export service provides defensive patterns for Filament
     */
    public function test_export_service_supports_defensive_filament_patterns(): void
    {
        // Create admin user and intake
        $admin = User::factory()->create([
            'email' => 'test@admin.com',
            'email_verified_at' => now(),
        ]);
        
        $intake = Intake::factory()->create();
        
        // Test that the service returns canonical structure
        $service = app(\App\Services\Robaws\RobawsExportService::class);
        $result = $service->exportIntake($intake);
        
        // Verify canonical structure exists (what Filament expects)
        $requiredKeys = ['success', 'failed', 'uploaded', 'exists', 'skipped', 'stats'];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Canonical response missing key: {$key}");
        }
        
        // Test defensive access patterns that Filament actions use
        // These should all work safely with ?? operators:
        $failed   = $result['failed']   ?? [];
        $success  = $result['success']  ?? [];
        $uploaded = $result['uploaded'] ?? [];
        $exists   = $result['exists']   ?? [];
        $skipped  = $result['skipped']  ?? [];
        $stats    = $result['stats']    ?? [
            'success' => count($success),
            'failed'  => count($failed),
            'uploaded'=> count($uploaded),
            'exists'  => count($exists),
            'skipped' => count($skipped),
        ];
        
        // These should all be safe without throwing "Undefined array key" errors
        $this->assertIsArray($failed);
        $this->assertIsArray($success);
        $this->assertIsArray($uploaded);
        $this->assertIsArray($exists);
        $this->assertIsArray($skipped);
        $this->assertIsArray($stats);
        
        // Test that stats calculations work
        $calculatedSuccess = $stats['success'] ?? 0;
        $calculatedFailed = $stats['failed'] ?? 0;
        
        $this->assertIsInt($calculatedSuccess);
        $this->assertIsInt($calculatedFailed);
        
        // Verify that we can access these safely for notification formatting
        $summary = sprintf(
            'Exported: %d • Failed: %d • Uploaded: %d • Exists: %d • Skipped: %d',
            $stats['success'] ?? 0, 
            $stats['failed'] ?? 0, 
            $stats['uploaded'] ?? 0, 
            $stats['exists'] ?? 0, 
            $stats['skipped'] ?? 0
        );
        
        $this->assertIsString($summary);
        $this->assertStringContainsString('Exported:', $summary);
    }
}
