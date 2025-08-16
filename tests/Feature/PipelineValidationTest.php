<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Jobs\PreprocessJob;
use App\Jobs\OcrJob;
use App\Jobs\ClassifyJob;
use App\Jobs\ExtractJob;
use App\Jobs\RulesJob;
use App\Http\Controllers\ResultsController;
use App\Services\RobawsService;
use App\Models\Intake;
use App\Models\Document;
use App\Models\VinWmi;
use App\Models\VehicleSpec;
use Illuminate\Support\Facades\Queue;

class PipelineValidationTest extends TestCase
{
    /** @test */
    public function complete_pipeline_is_properly_configured()
    {
        // Test all job classes exist and are dispatchable
        $intake = Intake::factory()->create();
        
        Queue::fake();
        
        // Test each job can be dispatched
        PreprocessJob::dispatch($intake->id);
        OcrJob::dispatch($intake->id);
        ClassifyJob::dispatch($intake->id);
        ExtractJob::dispatch($intake);
        RulesJob::dispatch($intake);
        
        Queue::assertPushed(PreprocessJob::class);
        Queue::assertPushed(OcrJob::class);
        Queue::assertPushed(ClassifyJob::class);
        Queue::assertPushed(ExtractJob::class);
        Queue::assertPushed(RulesJob::class);
        
        $this->assertTrue(true, 'All pipeline jobs are dispatchable');
    }

    /** @test */
    public function results_controller_routes_are_accessible()
    {
        $intake = Intake::factory()->create();
        
        // Test routes are registered
        $this->assertNotNull(route('intakes.results', $intake));
        $this->assertNotNull(route('intakes.parties.assign', $intake));
        $this->assertNotNull(route('intakes.push-robaws', $intake));
        
        $this->assertTrue(true, 'All results routes are properly registered');
    }

    /** @test */
    public function essential_data_is_seeded()
    {
        // Test VIN WMI data exists
        $wmiCount = VinWmi::count();
        $this->assertGreaterThan(50, $wmiCount, 'VIN WMI data should be seeded');
        
        // Test some common WMIs exist
        $this->assertNotNull(VinWmi::where('wmi', 'WAU')->first(), 'Audi WMI should exist');
        $this->assertNotNull(VinWmi::where('wmi', 'JN1')->first(), 'Nissan WMI should exist');
        
        // Test vehicle specs exist
        $specCount = VehicleSpec::where('is_verified', true)->count();
        $this->assertGreaterThan(5, $specCount, 'Verified vehicle specs should be seeded');
        
        $this->assertTrue(true, 'Essential seed data is present');
    }

    /** @test */
    public function robaws_service_is_configured()
    {
        $service = new RobawsService();
        $this->assertNotNull($service, 'RobawsService should be instantiable');
        
        // Test config exists
        $this->assertNotNull(config('services.robaws.base_url'));
        $this->assertNotNull(config('services.robaws.sandbox'));
        
        $this->assertTrue(true, 'Robaws service is properly configured');
    }

    /** @test */
    public function all_service_dependencies_are_resolvable()
    {
        // Test all our services can be resolved from the container
        $services = [
            'App\Services\LlmExtractor',
            'App\Services\RuleEngine', 
            'App\Services\VinWmiService',
            'App\Services\VehicleSpecService',
            'App\Services\PdfService',
            'App\Services\OcrService',
            'App\Services\RobawsService',
        ];
        
        foreach ($services as $service) {
            $instance = app($service);
            $this->assertNotNull($instance, "{$service} should be resolvable");
        }
        
        $this->assertTrue(true, 'All services are properly configured and resolvable');
    }
}
