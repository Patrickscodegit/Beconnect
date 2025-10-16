<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Intake;
use App\Models\Document;
use App\Services\IntakeAggregationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MultiDocumentIntakeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test multi-document intake aggregation
     */
    public function test_multi_document_intake_aggregates_data_correctly(): void
    {
        // Create a multi-document intake
        $intake = Intake::create([
            'status' => 'processing',
            'source' => 'test_multi_upload',
            'is_multi_document' => true,
            'total_documents' => 3,
            'processed_documents' => 0,
            'customer_name' => 'Test Customer',
            'contact_email' => 'test@example.com',
        ]);

        // Create 3 documents with different extraction data
        
        // Document 1: Email (highest priority)
        $doc1 = Document::create([
            'intake_id' => $intake->id,
            'filename' => 'enquiry.eml',
            'file_path' => 'documents/enquiry.eml',
            'mime_type' => 'message/rfc822',
            'file_size' => 1024,
            'storage_disk' => 'local',
            'extraction_data' => [
                'contact' => [
                    'name' => 'John Doe',
                    'email' => 'john@example.com',
                ],
                'shipment' => [
                    'type' => 'RORO Export',
                ],
                'route' => [
                    'pol' => 'BEANR',
                    'pod' => 'NGLOS',
                ],
            ],
            'extraction_status' => 'completed',
        ]);

        // Document 2: PDF (medium priority)
        $doc2 = Document::create([
            'intake_id' => $intake->id,
            'filename' => 'registration.pdf',
            'file_path' => 'documents/registration.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 2048,
            'storage_disk' => 'local',
            'extraction_data' => [
                'vehicle' => [
                    'make' => 'Toyota',
                    'model' => 'Camry',
                    'vin' => 'ABC123XYZ',
                    'dimensions' => [
                        'length' => 4.85,
                        'width' => 1.83,
                        'height' => 1.45,
                    ],
                    'weight' => ['value' => 1500, 'unit' => 'kg'],
                ],
            ],
            'extraction_status' => 'completed',
        ]);

        // Document 3: Image (lowest priority)
        $doc3 = Document::create([
            'intake_id' => $intake->id,
            'filename' => 'car_photo.jpg',
            'file_path' => 'documents/car_photo.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 512,
            'storage_disk' => 'local',
            'extraction_data' => [
                'vehicle' => [
                    'condition' => 'good',
                    'color' => 'silver',
                ],
            ],
            'extraction_status' => 'completed',
        ]);

        // Test aggregation
        $aggregationService = app(IntakeAggregationService::class);
        $aggregatedData = $aggregationService->aggregateExtractionData($intake);

        // Assertions
        $this->assertNotEmpty($aggregatedData);
        
        // Check contact data (from email - highest priority)
        $this->assertArrayHasKey('contact', $aggregatedData);
        $this->assertArrayHasKey('name', $aggregatedData['contact']);
        $this->assertEquals('John Doe', $aggregatedData['contact']['name']);
        $this->assertEquals('john@example.com', $aggregatedData['contact']['email']);
        
        // Check shipment data (from email)
        $this->assertEquals('RORO Export', $aggregatedData['shipment']['type']);
        
        // Check route data (from email)
        $this->assertEquals('BEANR', $aggregatedData['route']['pol']);
        $this->assertEquals('NGLOS', $aggregatedData['route']['pod']);
        
        // Check vehicle data (merged from PDF and image)
        $this->assertEquals('Toyota', $aggregatedData['vehicle']['make']);
        $this->assertEquals('Camry', $aggregatedData['vehicle']['model']);
        $this->assertEquals('ABC123XYZ', $aggregatedData['vehicle']['vin']);
        $this->assertEquals('good', $aggregatedData['vehicle']['condition']);
        $this->assertEquals('silver', $aggregatedData['vehicle']['color']);
        
        // Check metadata
        $this->assertArrayHasKey('metadata', $aggregatedData);
        $this->assertCount(3, $aggregatedData['metadata']['sources']);
        
        // Verify aggregated data was stored on intake
        $intake->refresh();
        $this->assertNotEmpty($intake->aggregated_extraction_data);
        
        echo PHP_EOL . "✅ Multi-document aggregation test PASSED!" . PHP_EOL;
        echo "   - Email data prioritized correctly" . PHP_EOL;
        echo "   - Vehicle data merged from PDF + Image" . PHP_EOL;
        echo "   - 3 sources tracked in metadata" . PHP_EOL;
    }

    /**
     * Test data priority (Email > PDF > Image)
     */
    public function test_aggregation_respects_priority(): void
    {
        $intake = Intake::create([
            'status' => 'processing',
            'is_multi_document' => true,
            'total_documents' => 2,
        ]);

        // Both have 'name', but email should win
        $email = Document::create([
            'intake_id' => $intake->id,
            'filename' => 'email.eml',
            'file_path' => 'documents/email.eml',
            'mime_type' => 'message/rfc822',
            'file_size' => 1024,
            'storage_disk' => 'local',
            'extraction_data' => [
                'contact' => ['name' => 'From Email'],
            ],
        ]);

        $image = Document::create([
            'intake_id' => $intake->id,
            'filename' => 'image.jpg',
            'file_path' => 'documents/image.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 512,
            'storage_disk' => 'local',
            'extraction_data' => [
                'contact' => ['name' => 'From Image'],
            ],
        ]);

        $service = app(IntakeAggregationService::class);
        $result = $service->aggregateExtractionData($intake);

        // Email should win
        $this->assertEquals('From Email', $result['contact']['name']);
        
        echo PHP_EOL . "✅ Priority test PASSED! Email data took precedence over image." . PHP_EOL;
    }
}

