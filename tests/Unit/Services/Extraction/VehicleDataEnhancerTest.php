<?php

namespace Tests\Unit\Services\Extraction;

use Tests\TestCase;
use App\Services\Extraction\VehicleDataEnhancer;
use App\Services\VehicleDatabase\VehicleDatabaseService;
use App\Services\AiRouter;
use App\Models\VehicleSpec;
use Mockery;
use Illuminate\Foundation\Testing\RefreshDatabase;

class VehicleDataEnhancerTest extends TestCase
{
    use RefreshDatabase;
    
    private VehicleDataEnhancer $enhancer;
    private $vehicleDbMock;
    private $aiRouterMock;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->vehicleDbMock = Mockery::mock(VehicleDatabaseService::class);
        $this->aiRouterMock = Mockery::mock(AiRouter::class);
        
        $this->enhancer = new VehicleDataEnhancer(
            $this->vehicleDbMock,
            $this->aiRouterMock
        );
    }

    /** @test */
    public function it_enhances_vehicle_data_from_database()
    {
        // Given extracted data with basic vehicle info
        $extractedData = [
            'vehicle' => [
                'make' => 'Toyota',
                'model' => 'Corolla',
                'year' => '2020'
            ]
        ];
        
        // Mock database vehicle
        $mockVehicle = new VehicleSpec([
            'id' => 1,
            'make' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2020,
            'weight_kg' => 1300,
            'length_m' => 4.63,
            'width_m' => 1.78,
            'height_m' => 1.44,
            'engine_cc' => 1800,
            'fuel_type' => 'petrol'
        ]);
        
        $this->vehicleDbMock->shouldReceive('findVehicle')
            ->once()
            ->andReturn($mockVehicle);
        
        // When
        $enhanced = $this->enhancer->enhance($extractedData);
        
        // Then
        $this->assertEquals(1300, $enhanced['vehicle']['weight']['value']);
        $this->assertEquals('kg', $enhanced['vehicle']['weight']['unit']);
        $this->assertEquals(4.63, $enhanced['vehicle']['dimensions']['length']);
        $this->assertEquals(1800, $enhanced['vehicle']['engine_cc']);
        $this->assertContains('vehicle.weight.value', $enhanced['data_sources']['database_enhanced']);
        $this->assertContains('vehicle.engine_cc', $enhanced['data_sources']['database_enhanced']);
    }

    /** @test */
    public function it_uses_ai_when_database_lacks_data()
    {
        // Given
        $extractedData = [
            'vehicle' => [
                'make' => 'Alfa',
                'model' => 'Giulietta',
                'year' => '1960'
            ]
        ];
        
        // Mock database returns no match
        $this->vehicleDbMock->shouldReceive('findVehicle')
            ->once()
            ->andReturn(null);
            
        // Mock AI response
        $this->aiRouterMock->shouldReceive('extract')
            ->once()
            ->andReturn([
                'weight_kg' => 1050,
                'dimensions' => [
                    'length_m' => 3.99,
                    'width_m' => 1.65,
                    'height_m' => 1.44
                ],
                'engine_cc' => 1290,
                'fuel_type' => 'petrol'
            ]);
        
        // When
        $enhanced = $this->enhancer->enhance($extractedData);
        
        // Then
        $this->assertEquals(1050, $enhanced['vehicle']['weight']['value']);
        $this->assertEquals(3.99, $enhanced['vehicle']['dimensions']['length']);
        $this->assertEquals(1290, $enhanced['vehicle']['engine_cc']);
        $this->assertContains('vehicle.weight.value', $enhanced['data_sources']['ai_enhanced']);
        $this->assertContains('vehicle.engine_cc', $enhanced['data_sources']['ai_enhanced']);
    }

    /** @test */
    public function it_calculates_derived_fields()
    {
        // Given data with dimensions
        $extractedData = [
            'vehicle' => [
                'make' => 'BMW',
                'model' => 'X5',
                'year' => '2020',
                'dimensions' => [
                    'length' => 4.9,
                    'width' => 2.0,
                    'height' => 1.8,
                    'unit' => 'm'
                ],
                'weight' => [
                    'value' => 2200,
                    'unit' => 'kg'
                ]
            ]
        ];
        
        // Mock no database match to focus on calculations
        $this->vehicleDbMock->shouldReceive('findVehicle')
            ->once()
            ->andReturn(null);
        
        // When
        $enhanced = $this->enhancer->enhance($extractedData);
        
        // Then
        $expectedVolume = 4.9 * 2.0 * 1.8; // 17.64
        $this->assertEquals(17.64, $enhanced['vehicle']['calculated_volume_m3']);
        $this->assertEquals('heavy', $enhanced['vehicle']['shipping_weight_class']);
        $this->assertEquals('20ft_container', $enhanced['vehicle']['recommended_container']); // 17.64 m³ fits in 20ft container
        $this->assertContains('vehicle.calculated_volume_m3', $enhanced['data_sources']['calculated']);
        $this->assertContains('vehicle.shipping_weight_class', $enhanced['data_sources']['calculated']);
    }

    /** @test */
    public function it_preserves_original_data_when_enhancement_fails()
    {
        // Given
        $extractedData = [
            'vehicle' => [
                'make' => 'TestMake',
                'model' => 'TestModel'
            ]
        ];
        
        // Mock database throws exception
        $this->vehicleDbMock->shouldReceive('findVehicle')
            ->once()
            ->andThrow(new \Exception('Database error'));
        
        // When
        $enhanced = $this->enhancer->enhance($extractedData);
        
        // Then - original data should be preserved
        $this->assertEquals('TestMake', $enhanced['vehicle']['make']);
        $this->assertEquals('TestModel', $enhanced['vehicle']['model']);
        $this->assertEmpty($enhanced['data_sources']['database_enhanced']);
    }

    /** @test */
    public function it_tracks_data_sources_correctly()
    {
        // Given
        $extractedData = [
            'vehicle' => [
                'make' => 'Honda',
                'model' => 'Civic',
                'year' => '2019',
                'color' => 'red' // This should be tracked as document extracted
            ]
        ];
        
        $this->vehicleDbMock->shouldReceive('findVehicle')->andReturn(null);
        
        // When
        $enhanced = $this->enhancer->enhance($extractedData);
        
        // Then
        $this->assertContains('vehicle.make', $enhanced['data_sources']['document_extracted']);
        $this->assertContains('vehicle.model', $enhanced['data_sources']['document_extracted']);
        $this->assertContains('vehicle.year', $enhanced['data_sources']['document_extracted']);
        $this->assertContains('vehicle.color', $enhanced['data_sources']['document_extracted']);
        
        $this->assertArrayHasKey('enhancement_metadata', $enhanced);
        $this->assertArrayHasKey('confidence', $enhanced['enhancement_metadata']);
        $this->assertArrayHasKey('sources_used', $enhanced['enhancement_metadata']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
