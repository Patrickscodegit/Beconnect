<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\Commodity\CommodityMappingService;
use App\Services\VehicleDatabase\VehicleDatabaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CommodityMappingServiceTest extends TestCase
{
    use RefreshDatabase;

    private CommodityMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock vehicle database service
        $vehicleDbMock = $this->createMock(VehicleDatabaseService::class);
        $vehicleDbMock->method('decodeVIN')
            ->willReturn([
                'manufacturer' => 'Honda',
                'year' => 2021
            ]);

        $this->service = new CommodityMappingService($vehicleDbMock);
    }

    public function test_maps_vehicle_data_correctly(): void
    {
        $extractionData = [
            'vehicle' => [
                'make' => 'Toyota',
                'model' => 'Camry',
                'year' => '2022',
                'vin' => '1HGBH41JXMN109186',
                'condition' => 'used',
                'fuel_type' => 'gasoline',
                'dimensions' => '4.9m x 1.8m x 1.4m',
                'weight' => '1500kg',
                'color' => 'Blue',
            ]
        ];

        $result = $this->service->mapFromExtractionData($extractionData);

        $this->assertCount(1, $result);
        $this->assertEquals('vehicles', $result[0]['commodity_type']);
        $this->assertEquals('Toyota', $result[0]['make']);
        $this->assertEquals('Camry', $result[0]['type_model']);
        $this->assertEquals('1HGBH41JXMN109186', $result[0]['vin']);
        $this->assertEquals(490, $result[0]['length_cm']);
        $this->assertEquals(180, $result[0]['width_cm']);
        $this->assertEquals(140, $result[0]['height_cm']);
        $this->assertEquals(1500, $result[0]['weight_kg']);
        $this->assertEquals('used', $result[0]['condition']);
        $this->assertEquals('gasoline', $result[0]['fuel_type']);
    }

    public function test_parses_dimensions_in_various_formats(): void
    {
        // Test meters
        $data1 = ['vehicle' => ['dimensions' => '4.9m x 1.8m x 1.4m']];
        $result1 = $this->service->mapFromExtractionData($data1);
        $this->assertEquals(490, $result1[0]['length_cm']);

        // Test centimeters
        $data2 = ['vehicle' => ['dimensions' => '490cm x 180cm x 140cm']];
        $result2 = $this->service->mapFromExtractionData($data2);
        $this->assertEquals(490, $result2[0]['length_cm']);

        // Test feet
        $data3 = ['vehicle' => ['dimensions' => '16ft x 6ft x 5ft']];
        $result3 = $this->service->mapFromExtractionData($data3);
        $this->assertEquals(487.68, $result3[0]['length_cm']);
    }

    public function test_parses_weight_in_various_formats(): void
    {
        // Test kg
        $data1 = ['vehicle' => ['weight' => '1500kg']];
        $result1 = $this->service->mapFromExtractionData($data1);
        $this->assertEquals(1500, $result1[0]['weight_kg']);

        // Test lbs
        $data2 = ['vehicle' => ['weight' => '3306lbs']];
        $result2 = $this->service->mapFromExtractionData($data2);
        $this->assertEquals(1499.58, $result2[0]['weight_kg']);

        // Test numeric
        $data3 = ['vehicle' => ['weight' => 1500]];
        $result3 = $this->service->mapFromExtractionData($data3);
        $this->assertEquals(1500, $result3[0]['weight_kg']);
    }

    public function test_detects_vehicle_category(): void
    {
        // SUV
        $data1 = ['vehicle' => ['make' => 'Toyota', 'model' => 'RAV4 SUV']];
        $result1 = $this->service->mapFromExtractionData($data1);
        $this->assertEquals('suv', $result1[0]['vehicle_category']);

        // Truck
        $data2 = ['vehicle' => ['make' => 'Ford', 'model' => 'F-150 Truck']];
        $result2 = $this->service->mapFromExtractionData($data2);
        $this->assertEquals('truck', $result2[0]['vehicle_category']);

        // Default (car)
        $data3 = ['vehicle' => ['make' => 'Honda', 'model' => 'Civic']];
        $result3 = $this->service->mapFromExtractionData($data3);
        $this->assertEquals('car', $result3[0]['vehicle_category']);
    }

    public function test_calculates_cbm_from_dimensions(): void
    {
        $data = [
            'vehicle' => [
                'make' => 'Toyota',
                'dimensions' => '490cm x 180cm x 140cm'
            ]
        ];

        $result = $this->service->mapFromExtractionData($data);

        // 4.9 * 1.8 * 1.4 = 12.348 CBM
        $this->assertEquals(12.348, $result[0]['cbm']);
    }

    public function test_handles_flat_structure_from_image_extraction(): void
    {
        $extractionData = [
            'vehicle_make' => 'Toyota',
            'vehicle_model' => 'Camry',
            'vehicle_year' => '2022',
            'vin' => '1HGBH41JXMN109186',
            'dimensions' => '4.9m x 1.8m x 1.4m',
            'weight' => '1500kg',
        ];

        $result = $this->service->mapFromExtractionData($extractionData);

        $this->assertCount(1, $result);
        $this->assertEquals('Toyota', $result[0]['make']);
        $this->assertEquals('Camry', $result[0]['type_model']);
    }

    public function test_handles_nested_raw_data_structure(): void
    {
        $extractionData = [
            'raw_data' => [
                'vehicle' => [
                    'make' => 'Toyota',
                    'model' => 'Camry',
                ]
            ]
        ];

        $result = $this->service->mapFromExtractionData($extractionData);

        $this->assertCount(1, $result);
        $this->assertEquals('Toyota', $result[0]['make']);
    }

    public function test_maps_machinery_data(): void
    {
        $extractionData = [
            'cargo' => [
                [
                    'type' => 'machinery',
                    'make' => 'Caterpillar',
                    'model' => 'D6T',
                    'dimensions' => '5m x 2m x 2.5m',
                    'weight' => '18000kg',
                    'condition' => 'used',
                    'includes_parts' => true,
                    'parts_description' => 'Extra blade included'
                ]
            ]
        ];

        $result = $this->service->mapFromExtractionData($extractionData);

        $this->assertCount(1, $result);
        $this->assertEquals('machinery', $result[0]['commodity_type']);
        $this->assertEquals('Caterpillar', $result[0]['make']);
        $this->assertEquals('D6T', $result[0]['type_model']);
        $this->assertEquals(500, $result[0]['length_cm']);
        $this->assertEquals(18000, $result[0]['weight_kg']);
        $this->assertTrue($result[0]['parts']);
        $this->assertEquals('Extra blade included', $result[0]['parts_description']);
    }

    public function test_maps_boat_data(): void
    {
        $extractionData = [
            'cargo' => [
                [
                    'type' => 'boat',
                    'dimensions' => '8m x 2.5m',
                    'weight' => '2000kg',
                    'condition' => 'used',
                    'description' => 'Boat with wooden cradle and trailer'
                ]
            ]
        ];

        $result = $this->service->mapFromExtractionData($extractionData);

        $this->assertCount(1, $result);
        $this->assertEquals('boat', $result[0]['commodity_type']);
        $this->assertEquals(800, $result[0]['length_cm']);
        $this->assertEquals(2000, $result[0]['weight_kg']);
        $this->assertTrue($result[0]['trailer']);
        $this->assertTrue($result[0]['wooden_cradle']);
    }

    public function test_maps_general_cargo_data(): void
    {
        $extractionData = [
            'cargo_description' => 'Palletized machinery parts',
            'dimensions' => '2m x 1.5m x 1m',
            'weight' => '500kg',
        ];

        $result = $this->service->mapFromExtractionData($extractionData);

        $this->assertCount(1, $result);
        $this->assertEquals('general_cargo', $result[0]['commodity_type']);
        $this->assertEquals('palletized', $result[0]['cargo_type']);
        $this->assertEquals(200, $result[0]['length_cm']);
        $this->assertEquals(500, $result[0]['bruto_weight_kg']);
    }

    public function test_handles_multiple_vehicles(): void
    {
        $extractionData = [
            'cargo' => [
                [
                    'type' => 'vehicle',
                    'make' => 'Toyota',
                    'model' => 'Camry',
                ],
                [
                    'type' => 'vehicle',
                    'make' => 'Honda',
                    'model' => 'Accord',
                ]
            ]
        ];

        $result = $this->service->mapFromExtractionData($extractionData);

        $this->assertCount(2, $result);
        $this->assertEquals('Toyota', $result[0]['make']);
        $this->assertEquals('Honda', $result[1]['make']);
    }

    public function test_handles_missing_data_gracefully(): void
    {
        $extractionData = [
            'vehicle' => [
                'make' => 'Toyota',
                // Missing model, dimensions, weight, etc.
            ]
        ];

        $result = $this->service->mapFromExtractionData($extractionData);

        $this->assertCount(1, $result);
        $this->assertEquals('Toyota', $result[0]['make']);
        $this->assertArrayNotHasKey('type_model', $result[0]);
        $this->assertArrayNotHasKey('dimensions', $result[0]);
    }

    public function test_normalizes_condition_values(): void
    {
        $data1 = ['vehicle' => ['make' => 'Toyota', 'condition' => 'Brand New']];
        $result1 = $this->service->mapFromExtractionData($data1);
        $this->assertEquals('new', $result1[0]['condition']);

        $data2 = ['vehicle' => ['make' => 'Toyota', 'condition' => 'Pre-owned']];
        $result2 = $this->service->mapFromExtractionData($data2);
        $this->assertEquals('used', $result2[0]['condition']);

        $data3 = ['vehicle' => ['make' => 'Toyota', 'condition' => 'Damaged in transport']];
        $result3 = $this->service->mapFromExtractionData($data3);
        $this->assertEquals('damaged', $result3[0]['condition']);
    }

    public function test_normalizes_fuel_type_values(): void
    {
        $data1 = ['vehicle' => ['make' => 'Toyota', 'fuel_type' => 'petrol']];
        $result1 = $this->service->mapFromExtractionData($data1);
        $this->assertEquals('gasoline', $result1[0]['fuel_type']);

        $data2 = ['vehicle' => ['make' => 'Toyota', 'fuel_type' => 'diesel']];
        $result2 = $this->service->mapFromExtractionData($data2);
        $this->assertEquals('diesel', $result2[0]['fuel_type']);

        $data3 = ['vehicle' => ['make' => 'Toyota', 'fuel_type' => 'hybrid electric']];
        $result3 = $this->service->mapFromExtractionData($data3);
        $this->assertEquals('hybrid', $result3[0]['fuel_type']);
    }

    public function test_includes_extra_info_from_additional_fields(): void
    {
        $extractionData = [
            'vehicle' => [
                'make' => 'Toyota',
                'model' => 'Camry',
                'mileage_km' => '50000',
                'engine_cc' => '2500',
                'description' => 'Well maintained, single owner'
            ]
        ];

        $result = $this->service->mapFromExtractionData($extractionData);

        $this->assertArrayHasKey('extra_info', $result[0]);
        $this->assertStringContainsString('Mileage: 50000 km', $result[0]['extra_info']);
        $this->assertStringContainsString('Engine: 2500 cc', $result[0]['extra_info']);
        $this->assertStringContainsString('Well maintained', $result[0]['extra_info']);
    }

    public function test_returns_empty_array_for_empty_extraction_data(): void
    {
        $result = $this->service->mapFromExtractionData([]);
        $this->assertEmpty($result);
    }
}

