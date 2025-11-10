<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Intake;
use App\Models\Document;
use App\Models\IntakeFile;
use App\Services\IntakeAggregationService;
use Tests\Support\Pipeline\PipelineTestHelper;

/** @group pipeline */
class MultiDocumentIntakeTest extends TestCase
{
    protected function setUp(): void
    {
        PipelineTestHelper::prepare();
        parent::setUp();

        PipelineTestHelper::boot($this);
    }

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
        $doc1Path = 'documents/enquiry.eml';
        $doc1 = Document::create([
            'intake_id' => $intake->id,
            'filename' => 'enquiry.eml',
            'file_path' => $doc1Path,
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
                'pol' => 'BEANR',
                'pod' => 'NGLOS',
            ],
            'extraction_status' => 'completed',
        ]);

        IntakeFile::create([
            'intake_id' => $intake->id,
            'filename' => 'enquiry.eml',
            'storage_path' => $doc1Path,
            'storage_disk' => 'local',
            'mime_type' => 'message/rfc822',
            'file_size' => 1024,
        ]);

        // Document 2: PDF (medium priority)
        $doc2Path = 'documents/registration.pdf';
        $doc2 = Document::create([
            'intake_id' => $intake->id,
            'filename' => 'registration.pdf',
            'file_path' => $doc2Path,
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

        IntakeFile::create([
            'intake_id' => $intake->id,
            'filename' => 'registration.pdf',
            'storage_path' => $doc2Path,
            'storage_disk' => 'local',
            'mime_type' => 'application/pdf',
            'file_size' => 2048,
        ]);

        // Document 3: Image (lowest priority)
        $doc3Path = 'documents/car_photo.jpg';
        $doc3 = Document::create([
            'intake_id' => $intake->id,
            'filename' => 'car_photo.jpg',
            'file_path' => $doc3Path,
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

        IntakeFile::create([
            'intake_id' => $intake->id,
            'filename' => 'car_photo.jpg',
            'storage_path' => $doc3Path,
            'storage_disk' => 'local',
            'mime_type' => 'image/jpeg',
            'file_size' => 512,
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
        $emailPath = 'documents/email.eml';
        $email = Document::create([
            'intake_id' => $intake->id,
            'filename' => 'email.eml',
            'file_path' => $emailPath,
            'mime_type' => 'message/rfc822',
            'file_size' => 1024,
            'storage_disk' => 'local',
            'extraction_data' => [
                'contact' => ['name' => 'From Email'],
            ],
        ]);

        IntakeFile::create([
            'intake_id' => $intake->id,
            'filename' => 'email.eml',
            'storage_path' => $emailPath,
            'storage_disk' => 'local',
            'mime_type' => 'message/rfc822',
            'file_size' => 1024,
        ]);

        $imagePath = 'documents/image.jpg';
        $image = Document::create([
            'intake_id' => $intake->id,
            'filename' => 'image.jpg',
            'file_path' => $imagePath,
            'mime_type' => 'image/jpeg',
            'file_size' => 512,
            'storage_disk' => 'local',
            'extraction_data' => [
                'contact' => ['name' => 'From Image'],
            ],
        ]);

        IntakeFile::create([
            'intake_id' => $intake->id,
            'filename' => 'image.jpg',
            'storage_path' => $imagePath,
            'storage_disk' => 'local',
            'mime_type' => 'image/jpeg',
            'file_size' => 512,
        ]);

        $service = app(IntakeAggregationService::class);
        $result = $service->aggregateExtractionData($intake);

        // Email should win
        $this->assertEquals('From Email', $result['contact']['name']);
        
        echo PHP_EOL . "✅ Priority test PASSED! Email data took precedence over image." . PHP_EOL;
    }
}

