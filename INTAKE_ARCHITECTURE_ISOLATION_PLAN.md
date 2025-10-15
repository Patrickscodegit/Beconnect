# 🔍 Intake Architecture - Isolation & Multi-Document Plan

## Executive Summary

The Intake system requires **two critical enhancements**:
1. **TYPE ISOLATION**: Email, Image, and PDF intakes must work independently
2. **MULTI-DOCUMENT UPLOAD**: Support uploading .eml + vehicle docs (PDF/images) + invoices together

**Key Business Requirement:** Multiple files (email + documents + images) create **ONE Robaws offer**, not separate offers.

---

## 📋 Typical Use Case

### Common Scenario:
```
Customer sends:
1. Email (.eml) - Enquiry text: "Need to ship 2 cars from Antwerp to Lagos"
2. Vehicle Reg PDF - Registration papers with VIN, dimensions
3. Vehicle Photo (JPG) - Picture of the car for visual inspection
4. Invoice PDF - Purchase invoice with value

Expected Result:
→ ONE intake with 4 files
→ Email provides: contact info, route, cargo description
→ Vehicle docs provide: make, model, dimensions, weight
→ Invoice provides: cargo value, additional details
→ All data merged → ONE Robaws offer with all 4 files attached
```

---

## 🔒 ISOLATION + MULTI-DOCUMENT ARCHITECTURE

### Solution: **Type-Isolated Pipelines with Unified Offer Creation**

```
User Upload: enquiry.eml + reg.pdf + car.jpg + invoice.pdf
              ↓
    IntakeCreationService.createFromMultipleFiles()
              ↓
   ┌────────────────────────────────────┐
   │  ONE Intake (id: 123)              │
   │  - is_multi_file: true             │
   │  - total_files: 4                  │
   │  - processed_files: 0              │
   └────────────────────────────────────┘
              ↓
   Create 4 IntakeFile records:
   - file #1: enquiry.eml (mime: message/rfc822)
   - file #2: reg.pdf (mime: application/pdf)
   - file #3: car.jpg (mime: image/jpeg)
   - file #4: invoice.pdf (mime: application/pdf)
              ↓
    IntakePipelineFactory (routes by mime type)
              ↓
    ┌─────────┴─────────┬──────────┬────────────┐
    ↓                   ↓          ↓            ↓
EmailPipeline      PdfPipeline  ImagePipeline  PdfPipeline
(queue: email)     (queue: pdf) (queue: image) (queue: pdf)
processes file #1  file #2      file #3        file #4
    ↓                   ↓          ↓            ↓
Extract contact    Extract VIN  OCR reg        Extract value
Extract route      Extract dims plate/specs   Extract details
Extract cargo desc                             
    ↓                   ↓          ↓            ↓
Update file #1      Update #2    Update #3     Update #4
status: completed   completed    completed     completed
extraction_data:    data:        data:         data:
{                   {            {             {
  contact: {...}      vin: "XX"    make: "Y"     value: 5000
  route: {...}        dims: {}     model: "Z"  }
  cargo: "2 cars"   }            }
}
    └─────────┬─────────┴──────────┴────────────┘
              ↓
    IntakeAggregationService.aggregateFileResults()
    Checks: processed_files == total_files (4 == 4) ✅
              ↓
    Merge extraction data intelligently:
    - Contact info: from email (priority)
    - Route: from email
    - Cargo: from email + vehicle docs
    - Vehicle details: from PDF + image
    - Cargo value: from invoice
              ↓
    Update Intake:
    {
      extraction_data: {
        contact: {...},        // from email
        route: {...},          // from email
        cargo: {
          description: "2 cars",  // from email
          vehicle1: {
            make: "Toyota",    // from image OCR
            model: "Camry",    // from image OCR
            vin: "XX123",      // from reg PDF
            dims: {...},       // from reg PDF
            value: 5000        // from invoice
          }
        }
      },
      status: 'processing_complete',
      processed_files: 4
    }
              ↓
    IntakeObserver (triggered on status: processing_complete)
              ↓
    Create ONE QuotationRequest with merged data
              ↓
    RobawsExportService.exportIntake()
              ↓
    ┌────────────────────────────────────────┐
    │  ONE ROBAWS OFFER                      │
    │  - Client: from email contact          │
    │  - Route: from email                   │
    │  - Cargo: merged from all files        │
    │  - Attachments: 4 files                │
    │    * enquiry.eml                       │
    │    * reg.pdf                           │
    │    * car.jpg                           │
    │    * invoice.pdf                       │
    └────────────────────────────────────────┘
```

---

## 🎯 KEY PRINCIPLES

### 1. **Isolation = Independent Processing**
- Email pipeline extracts contact/route → **doesn't touch** PDF/image code
- PDF pipeline extracts document data → **doesn't touch** email/image code
- Image pipeline does OCR → **doesn't touch** email/PDF code
- Each can fail/succeed independently

### 2. **Aggregation = Unified Output**
- Wait for **ALL** files to finish processing
- **Intelligently merge** extraction results (priority: email > PDF > image for contact)
- Create **ONE** Robaws offer with all files
- Create **ONE** quotation request

### 3. **Priority-Based Merging**
```php
// IntakeAggregationService logic
private function mergeExtractionData($files): array
{
    $merged = [];
    
    // Step 1: Email has highest priority for contact/route
    $emailFile = $files->firstWhere('mime_type', 'message/rfc822');
    if ($emailFile && !empty($emailFile->extraction_data['contact'])) {
        $merged['contact'] = $emailFile->extraction_data['contact'];
        $merged['route'] = $emailFile->extraction_data['route'] ?? [];
        $merged['cargo']['description'] = $emailFile->extraction_data['cargo'] ?? '';
    }
    
    // Step 2: PDFs provide technical details
    $pdfFiles = $files->where('mime_type', 'application/pdf');
    foreach ($pdfFiles as $pdf) {
        if (!empty($pdf->extraction_data['vehicle'])) {
            $merged['cargo']['vehicles'][] = $pdf->extraction_data['vehicle'];
        }
        if (!empty($pdf->extraction_data['invoice'])) {
            $merged['cargo']['value'] = $pdf->extraction_data['invoice']['value'];
        }
    }
    
    // Step 3: Images complement with visual data
    $imageFiles = $files->filter(fn($f) => str_starts_with($f->mime_type, 'image/'));
    foreach ($imageFiles as $image) {
        if (!empty($image->extraction_data['ocr_text'])) {
            // OCR might find make/model/plate number
            $merged['cargo']['visual_inspection'][] = $image->extraction_data;
        }
    }
    
    return $merged;
}
```

---

## 📋 IMPLEMENTATION PLAN

### Phase 0: Foundation (3-5 days) - CRITICAL

**Create Isolation Infrastructure:**

```bash
# Directory structure
app/Services/Intake/Pipelines/
├── EmailIntakePipeline.php
├── ImageIntakePipeline.php
├── PdfIntakePipeline.php
└── IntakePipelineFactory.php

app/Services/Intake/
└── IntakeAggregationService.php

app/Jobs/Intake/
├── ProcessEmailIntakeJob.php
├── ProcessImageIntakeJob.php
└── ProcessPdfIntakeJob.php
```

**Database Migrations:**

```php
// Migration: 2025_XX_XX_add_multi_file_support_to_intakes.php
Schema::table('intakes', function (Blueprint $table) {
    $table->boolean('is_multi_file')->default(false)->after('status');
    $table->integer('total_files')->default(1)->after('is_multi_file');
    $table->integer('processed_files')->default(0)->after('total_files');
    $table->json('file_processing_status')->nullable()->after('processed_files');
});

// Migration: 2025_XX_XX_add_processing_fields_to_intake_files.php
Schema::table('intake_files', function (Blueprint $table) {
    $table->string('processing_status')->default('pending')->after('file_size');
    $table->json('extraction_data')->nullable()->after('processing_status');
    $table->text('processing_error')->nullable()->after('extraction_data');
    $table->timestamp('processed_at')->nullable()->after('processing_error');
    
    $table->index(['intake_id', 'processing_status']);
});
```

**Queue Configuration:**

```php
// config/queue.php
'connections' => [
    'email-intakes' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => 'email-intakes',
        'retry_after' => 180,
        'block_for' => null,
    ],
    'image-intakes' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => 'image-intakes',
        'retry_after' => 300, // OCR can take longer
        'block_for' => null,
    ],
    'pdf-intakes' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => 'pdf-intakes',
        'retry_after' => 180,
        'block_for' => null,
    ],
],
```

**Tasks:**
- [ ] Create pipeline directory structure
- [ ] Create `IntakePipelineFactory.php` (mime type router)
- [ ] Create `IntakeAggregationService.php`
- [ ] Add multi-file database migrations
- [ ] Configure separate queues in `config/queue.php`
- [ ] Update `IntakeCreationService`:
  ```php
  public function createFromMultipleFiles(array $files, array $options = []): Intake
  {
      $intake = Intake::create([
          'is_multi_file' => count($files) > 1,
          'total_files' => count($files),
          'processed_files' => 0,
          // ... other fields
      ]);
      
      foreach ($files as $file) {
          $intakeFile = $this->storeFileForIntake($intake, $file);
          $this->dispatchPipelineForFile($intake, $intakeFile);
      }
      
      return $intake;
  }
  ```

---

### Phase 1: Email Isolation (Week 1)

**Create Email Pipeline:**

```php
// app/Services/Intake/Pipelines/EmailIntakePipeline.php
class EmailIntakePipeline
{
    public function process(Intake $intake, IntakeFile $file): void
    {
        ProcessEmailIntakeJob::dispatch($intake, $file)
            ->onQueue('email-intakes')
            ->tags(['intake', 'email', "intake:{$intake->id}", "file:{$file->id}"]);
    }
}

// app/Jobs/Intake/ProcessEmailIntakeJob.php
class ProcessEmailIntakeJob implements ShouldQueue
{
    public function __construct(
        public Intake $intake,
        public IntakeFile $file
    ) {}
    
    public function handle(EmailExtractionService $extractor): void
    {
        // Extract email-specific data
        $extractionData = $extractor->extract($this->file);
        
        // Update this file's status
        $this->file->update([
            'processing_status' => 'completed',
            'extraction_data' => $extractionData,
            'processed_at' => now(),
        ]);
        
        // Increment intake's processed files counter
        $this->intake->increment('processed_files');
        
        // Check if all files processed
        $aggregator = app(IntakeAggregationService::class);
        $aggregator->checkAndAggregate($this->intake->fresh());
    }
}
```

**Tests:**
- [ ] Single .eml upload → processes via email pipeline
- [ ] .eml + PDF → email pipeline for .eml, PDF pipeline for PDF
- [ ] .eml + image → email pipeline for .eml, image pipeline for image
- [ ] Feature flag: `INTAKE_USE_EMAIL_PIPELINE=true`

---

### Phase 2: Image Isolation (Week 2)

**Create Image Pipeline:**

```php
// app/Services/Intake/Pipelines/ImageIntakePipeline.php
class ImageIntakePipeline
{
    public function process(Intake $intake, IntakeFile $file): void
    {
        ProcessImageIntakeJob::dispatch($intake, $file)
            ->onQueue('image-intakes')
            ->tags(['intake', 'image', "intake:{$intake->id}", "file:{$file->id}"]);
    }
}

// app/Jobs/Intake/ProcessImageIntakeJob.php
class ProcessImageIntakeJob implements ShouldQueue
{
    public function handle(ImageOcrService $ocr): void
    {
        // OCR extraction
        $extractionData = $ocr->extract($this->file);
        
        $this->file->update([
            'processing_status' => 'completed',
            'extraction_data' => $extractionData,
            'processed_at' => now(),
        ]);
        
        $this->intake->increment('processed_files');
        
        app(IntakeAggregationService::class)->checkAndAggregate($this->intake->fresh());
    }
}
```

**Tests:**
- [ ] Single image upload
- [ ] Image + .eml multi-upload
- [ ] Image + PDF multi-upload
- [ ] Multiple images (2-3) in one batch

---

### Phase 3: PDF Isolation (Week 3)

**Create PDF Pipeline:**

```php
// app/Services/Intake/Pipelines/PdfIntakePipeline.php
class PdfIntakePipeline
{
    public function process(Intake $intake, IntakeFile $file): void
    {
        ProcessPdfIntakeJob::dispatch($intake, $file)
            ->onQueue('pdf-intakes')
            ->tags(['intake', 'pdf', "intake:{$intake->id}", "file:{$file->id}"]);
    }
}

// app/Jobs/Intake/ProcessPdfIntakeJob.php
class ProcessPdfIntakeJob implements ShouldQueue
{
    public function handle(PdfExtractionService $extractor): void
    {
        $extractionData = $extractor->extract($this->file);
        
        $this->file->update([
            'processing_status' => 'completed',
            'extraction_data' => $extractionData,
            'processed_at' => now(),
        ]);
        
        $this->intake->increment('processed_files');
        
        app(IntakeAggregationService::class)->checkAndAggregate($this->intake->fresh());
    }
}
```

**Tests:**
- [ ] Single PDF upload
- [ ] PDF + .eml multi-upload
- [ ] PDF + image multi-upload
- [ ] Multiple PDFs (reg + invoice) in one batch

---

### Phase 4: Aggregation & Robaws Integration (Week 4)

**Complete Aggregation Service:**

```php
// app/Services/Intake/IntakeAggregationService.php
class IntakeAggregationService
{
    public function checkAndAggregate(Intake $intake): void
    {
        if ($intake->processed_files < $intake->total_files) {
            Log::info('Waiting for more files to process', [
                'intake_id' => $intake->id,
                'processed' => $intake->processed_files,
                'total' => $intake->total_files,
            ]);
            return; // Wait for all files
        }
        
        Log::info('All files processed, starting aggregation', [
            'intake_id' => $intake->id,
        ]);
        
        $files = $intake->files;
        $mergedData = $this->mergeExtractionData($files);
        
        $intake->update([
            'extraction_data' => $mergedData,
            'status' => 'processing_complete',
        ]);
        
        // This triggers IntakeObserver → creates QuotationRequest
        // QuotationRequest → RobawsExport → ONE offer with all files
    }
    
    private function mergeExtractionData($files): array
    {
        // Priority-based merging (as shown above)
    }
}
```

**Robaws Export with All Files:**

```php
// app/Services/Robaws/RobawsExportService.php
public function exportIntake(Intake $intake): array
{
    $payload = $this->buildPayload($intake);
    
    // Create ONE offer in Robaws
    $offer = $this->apiClient->createOffer($payload);
    
    // Attach ALL intake files to this single offer
    foreach ($intake->files as $file) {
        $this->apiClient->attachFileToOffer($offer['id'], $file);
    }
    
    return $offer;
}
```

**Tests:**
- [ ] .eml + image + PDF → ONE quotation, ONE Robaws offer
- [ ] .eml + 2 PDFs + 1 image → ONE quotation with 4 files
- [ ] Partial failure: .eml succeeds, image fails → quotation created with available data
- [ ] All files fail → intake marked as failed, no quotation created

---

## 🧪 COMPREHENSIVE TESTING

### Unit Tests

```php
// tests/Unit/IntakeAggregationServiceTest.php
class IntakeAggregationServiceTest extends TestCase
{
    /** @test */
    public function aggregation_waits_for_all_files()
    {
        $intake = Intake::factory()->create([
            'is_multi_file' => true,
            'total_files' => 3,
            'processed_files' => 2, // Only 2 of 3 done
        ]);
        
        $service = new IntakeAggregationService();
        $service->checkAndAggregate($intake);
        
        // Should NOT aggregate yet
        $this->assertNotEquals('processing_complete', $intake->fresh()->status);
    }
    
    /** @test */
    public function aggregation_triggers_when_all_files_done()
    {
        $intake = Intake::factory()->create([
            'is_multi_file' => true,
            'total_files' => 3,
            'processed_files' => 3, // All done
        ]);
        
        $service = new IntakeAggregationService();
        $service->checkAndAggregate($intake);
        
        $this->assertEquals('processing_complete', $intake->fresh()->status);
    }
}
```

### Integration Tests

```php
// tests/Feature/Intake/MultiDocumentIntakeTest.php
class MultiDocumentIntakeTest extends TestCase
{
    /** @test */
    public function email_plus_vehicle_docs_creates_one_offer()
    {
        Queue::fake();
        
        $files = [
            UploadedFile::fake()->create('enquiry.eml', 100, 'message/rfc822'),
            UploadedFile::fake()->create('registration.pdf', 200, 'application/pdf'),
            UploadedFile::fake()->image('car.jpg'),
        ];
        
        $service = app(IntakeCreationService::class);
        $intake = $service->createFromMultipleFiles($files, [
            'customer_name' => 'John Doe',
            'contact_email' => 'john@example.com',
        ]);
        
        // Verify one intake created
        $this->assertDatabaseHas('intakes', [
            'id' => $intake->id,
            'is_multi_file' => true,
            'total_files' => 3,
        ]);
        
        // Verify 3 files created
        $this->assertCount(3, $intake->files);
        
        // Verify jobs dispatched to correct queues
        Queue::assertPushedOn('email-intakes', ProcessEmailIntakeJob::class);
        Queue::assertPushedOn('pdf-intakes', ProcessPdfIntakeJob::class);
        Queue::assertPushedOn('image-intakes', ProcessImageIntakeJob::class);
        
        // Simulate job processing
        $this->simulateJobProcessing($intake);
        
        // Verify ONE quotation created
        $this->assertCount(1, $intake->fresh()->quotationRequest);
        
        // Verify quotation has all 3 files
        $quotation = $intake->quotationRequest;
        $this->assertCount(3, $quotation->files);
    }
}
```

---

## 🚀 API ENDPOINTS

### Multi-Upload Endpoint

```php
// routes/api.php
Route::post('/intakes/multi-upload', [ApiIntakeController::class, 'createMultiUpload'])
    ->middleware('throttle:60,1')
    ->name('api.intakes.multi-upload');

// app/Http/Controllers/ApiIntakeController.php
public function createMultiUpload(Request $request)
{
    $request->validate([
        'files' => 'required|array|min:1|max:10',
        'files.*' => 'file|mimes:eml,pdf,jpg,jpeg,png|max:10240',
        'customer_name' => 'required|string',
        'contact_email' => 'required|email',
    ]);
    
    $service = app(IntakeCreationService::class);
    $intake = $service->createFromMultipleFiles(
        $request->file('files'),
        $request->only(['customer_name', 'contact_email', 'contact_phone'])
    );
    
    return response()->json([
        'success' => true,
        'intake_id' => $intake->id,
        'files_count' => $intake->total_files,
        'files' => $intake->files->map(fn($f) => [
            'id' => $f->id,
            'filename' => $f->filename,
            'type' => $this->getFileType($f->mime_type),
            'status' => $f->processing_status,
        ]),
        'message' => "Processing {$intake->total_files} files. Results will be aggregated into one offer.",
    ]);
}
```

---

## ⏱️ TIMELINE & MILESTONES

| Week | Phase | Deliverables | Success Metric |
|------|-------|--------------|----------------|
| 0 | Foundation | Pipelines, migrations, aggregation service | Structure created, migrations run |
| 1 | Email Isolation | Email pipeline + tests | Email files process independently |
| 2 | Image Isolation | Image pipeline + tests | Image files process independently |
| 3 | PDF Isolation | PDF pipeline + tests | PDF files process independently |
| 4 | Integration | Aggregation + multi-file API | .eml + docs → ONE offer |

---

## ✅ SUCCESS CRITERIA

1. ✅ **Isolation**: Delete `EmailPipeline.php` → Image/PDF still work
2. ✅ **Isolation**: Stop email queue → Image/PDF queues unaffected
3. ✅ **Multi-file**: Upload .eml + PDF + image → ONE intake created
4. ✅ **Multi-file**: All files process in parallel (check queue metrics)
5. ✅ **Aggregation**: Files wait for each other before creating quotation
6. ✅ **Robaws**: ONE offer created with all files attached
7. ✅ **Partial failure**: If 1 file fails, others still succeed + quotation created with available data
8. ✅ **Backward compat**: Single file uploads still work

---

## 🎯 FINAL ARCHITECTURE

```
┌─────────────────────────────────────────────┐
│  UPLOAD: enquiry.eml + reg.pdf + car.jpg    │
└─────────────────────────────────────────────┘
                    ↓
        ┌───────────────────────┐
        │  ONE Intake           │
        │  3 IntakeFiles        │
        └───────────────────────┘
                    ↓
    ┌───────────────┼────────────────┐
    ↓               ↓                ↓
Email Queue     PDF Queue      Image Queue
(isolated)      (isolated)     (isolated)
    ↓               ↓                ↓
Extract         Extract        OCR
Contact         VIN/Dims       Make/Model
Route           Value          
    ↓               ↓                ↓
Update File     Update File    Update File
    └───────────────┼────────────────┘
                    ↓
        ┌──────────────────────┐
        │  Aggregation Service │
        │  Merge all results   │
        └──────────────────────┘
                    ↓
        ┌──────────────────────┐
        │  QuotationRequest    │
        │  (with merged data)  │
        └──────────────────────┘
                    ↓
        ┌──────────────────────────┐
        │  ONE ROBAWS OFFER        │
        │  - Client: from email    │
        │  - Cargo: from all files │
        │  - Files: 3 attached     │
        └──────────────────────────┘
```

**Result:** Unbreakable isolation + unified offer creation! 🎉

---

**PRIORITY: START PHASE 0 IMMEDIATELY**


## 🚗 MULTI-COMMODITY AUTO-POPULATION

### Additional Requirement:
**Extracted intake data must auto-populate the multi-commodity quotation form (vehicles, machinery, boats, general cargo)**

### Commodity Integration Flow:

```
Email: "Ship 2 Toyota Camry sedans"
+ Vehicle Registration PDF (VIN, specs, dimensions)
+ Car Photo (visual inspection)
              ↓
     Extraction Pipelines (Isolated)
              ↓
Email: contact, route, "2 cars"
PDF: VIN=XX123, Make=Toyota, Model=Camry, Weight=1500kg, Dims=485x183x145cm
Image OCR: License plate, visual confirmation
              ↓
     IntakeAggregationService
              ↓
     CommodityMappingService (NEW)
              ↓
Auto-create commodity items:
[
  {
    commodity_type: 'vehicles',
    category: 'car',
    make: 'Toyota',
    type_model: 'Camry',
    vin: 'XX123',
    weight_kg: 1500,
    length_cm: 485,
    width_cm: 183,
    height_cm: 145,
    condition: 'used',
    fuel_type: 'petrol'
  }
]
              ↓
QuotationRequest with QuotationCommodityItems
              ↓
Admin sees pre-filled commodity form ✅
Can edit/add more items ✅
```

---

### 🛠️ New Service: CommodityMappingService

**Location:** `app/Services/Intake/CommodityMappingService.php`

**Purpose:** Map extracted intake data to commodity item fields

**Key Methods:**
- `mapToCommodityItems(array $extractionData): array` - Main mapper
- `mapToVehicles()` - Extract vehicle fields (make, model, VIN, etc.)
- `mapToMachinery()` - Extract machinery fields
- `mapToGeneralCargo()` - Extract cargo fields
- `mapToBoat()` - Extract boat fields
- `detectVehicleCategory()` - Auto-detect: car/SUV/van/truck
- `detectFuelType()` - Auto-detect: petrol/diesel/electric/hybrid
- `detectCondition()` - Auto-detect: new/used/damaged

**Example Implementation:**
```php
public function mapToVehicles(array $data): array
{
    $vehicles = $data['cargo']['vehicles'] ?? [];
    $items = [];
    
    foreach ($vehicles as $vehicle) {
        $items[] = [
            'commodity_type' => 'vehicles',
            'category' => $this->detectVehicleCategory($vehicle),
            'make' => $vehicle['make'] ?? null,
            'type_model' => $vehicle['model'] ?? $vehicle['type'] ?? null,
            'vin' => $vehicle['vin'] ?? null,
            'fuel_type' => $this->detectFuelType($vehicle),
            'condition' => $vehicle['condition'] ?? 'used',
            'weight_kg' => $vehicle['weight'] ?? null,
            'length_cm' => $vehicle['dimensions']['length'] ?? null,
            'width_cm' => $vehicle['dimensions']['width'] ?? null,
            'height_cm' => $vehicle['dimensions']['height'] ?? null,
            'wheelbase_cm' => $vehicle['wheelbase'] ?? null,
            'extra_info' => $vehicle['notes'] ?? null,
        ];
    }
    
    return $items;
}
```

---

### 📦 Enhanced IntakeObserver

**Update:** `app/Observers/IntakeObserver.php`

```php
protected function createQuotationFromIntake(Intake $intake): QuotationRequest
{
    $extractionData = $intake->extraction_data ?? [];
    
    // NEW: Map extracted data to commodity items
    $commodityMapper = app(CommodityMappingService::class);
    $commodityItems = $commodityMapper->mapToCommodityItems($extractionData);
    
    // Create quotation
    $quotation = QuotationRequest::create([
        'source' => 'intake',
        'intake_id' => $intake->id,
        // ... other fields
        'cargo_description' => $extractionData['cargo']['description'] 
            ?? ($commodityItems ? 'See commodity items' : 'Pending extraction'),
        'total_commodity_items' => count($commodityItems),
    ]);
    
    // Auto-create commodity item records
    foreach ($commodityItems as $index => $item) {
        $quotation->commodityItems()->create([
            'line_number' => $index + 1,
            ...$item, // Spread all mapped fields
        ]);
    }
    
    Log::info('Quotation auto-created with commodity items', [
        'quotation_id' => $quotation->id,
        'intake_id' => $intake->id,
        'commodity_items_count' => count($commodityItems),
        'commodity_types' => array_unique(array_column($commodityItems, 'commodity_type')),
    ]);
    
    return $quotation;
}
```

---

### 🎨 Filament Admin: Review Auto-Populated Items

**Update:** `app/Filament/Resources/QuotationRequestResource.php`

```php
Section::make('Intake Source')
    ->visible(fn ($record) => $record?->intake_id)
    ->schema([
        Placeholder::make('intake_info')
            ->label('Created From')
            ->content(fn ($record) => "✅ Intake #{$record->intake_id}"),
            
        Placeholder::make('auto_populated_count')
            ->label('Auto-Populated Items')
            ->content(fn ($record) => 
                "{$record->total_commodity_items} commodity items automatically extracted from documents"
            )
            ->helperText('Review and edit items below, or add additional items'),
    ]),

Section::make('Commodity Items')
    ->description('Auto-populated from intake documents - review and edit as needed')
    ->schema([
        Repeater::make('commodityItems')
            ->relationship()
            ->schema([
                // Same schema as customer/public commodity forms
                Select::make('commodity_type')->options(config('quotation.commodity_types')),
                // ... all other fields
            ])
            ->defaultItems(0) // Don't add empty items if already populated
            ->addActionLabel('Add Additional Item')
            ->itemLabel(fn ($state) => 
                trim(($state['make'] ?? '') . ' ' . ($state['type_model'] ?? 'Item')) ?: 'New Item'
            )
            ->collapsible(),
    ]),
```

---

### 🧪 Commodity Mapping Tests

**New Test File:** `tests/Unit/CommodityMappingServiceTest.php`

```php
/** @test */
public function maps_vehicle_extraction_to_commodity_items()
{
    $extractionData = [
        'cargo' => [
            'type' => 'vehicles',
            'vehicles' => [
                [
                    'make' => 'Toyota',
                    'model' => 'Camry',
                    'vin' => 'XX123',
                    'weight' => 1500,
                    'fuel' => 'petrol',
                    'dimensions' => ['length' => 485, 'width' => 183, 'height' => 145]
                ]
            ]
        ]
    ];
    
    $service = new CommodityMappingService();
    $items = $service->mapToCommodityItems($extractionData);
    
    $this->assertCount(1, $items);
    $this->assertEquals('vehicles', $items[0]['commodity_type']);
    $this->assertEquals('car', $items[0]['category']);
    $this->assertEquals('Toyota', $items[0]['make']);
    $this->assertEquals('Camry', $items[0]['type_model']);
    $this->assertEquals(1500, $items[0]['weight_kg']);
}

/** @test */
public function handles_multiple_vehicles_from_one_intake()
{
    $extractionData = [
        'cargo' => [
            'vehicles' => [
                ['make' => 'Toyota', 'model' => 'Camry'],
                ['make' => 'Honda', 'model' => 'Civic'],
            ]
        ]
    ];
    
    $service = new CommodityMappingService();
    $items = $service->mapToCommodityItems($extractionData);
    
    $this->assertCount(2, $items);
    $this->assertEquals('Toyota', $items[0]['make']);
    $this->assertEquals('Honda', $items[1]['make']);
}
```

---

### 📋 UPDATED IMPLEMENTATION PHASES

#### Phase 0: Foundation - COMMODITY ENHANCED
**Add to Phase 0:**
- [ ] Create `app/Services/Intake/CommodityMappingService.php`
- [ ] Update extraction strategies to capture commodity-specific fields:
  - [ ] Vehicle documents: VIN, make, model, dimensions, fuel type
  - [ ] Machinery documents: type, specs, parts info
  - [ ] General cargo: packaging, weights, dimensions
- [ ] Update `IntakeObserver` to use commodity mapping
- [ ] Add commodity mapping unit tests
- [ ] Test vehicle category auto-detection
- [ ] Test fuel type auto-detection

#### Phase 2.5: Commodity Integration Testing (NEW - Mid Week 2)
- [ ] Test: Vehicle PDF upload → auto-creates vehicle commodity items
- [ ] Test: Machinery document → auto-creates machinery items
- [ ] Test: General cargo invoice → auto-creates cargo items
- [ ] Test: Mixed commodities (vehicle + spare parts) → creates both types
- [ ] Test: Email says "2 cars" but only 1 PDF → creates 1 item + placeholder
- [ ] Validate: All commodity fields map to correct form fields
- [ ] Validate: CBM auto-calculation works with extracted dimensions
- [ ] Admin review flow works smoothly

#### Phase 4: Complete Integration - COMMODITY ENHANCED
**Add to Phase 4:**
- [ ] End-to-end: .eml + vehicle docs → quotation with pre-filled items
- [ ] Commodity validation works with auto-populated items
- [ ] Filament shows clear "Auto-populated from intake" indicators
- [ ] User can seamlessly add/edit/remove auto-populated items
- [ ] Robaws export includes all commodity item data

---

### 🎯 USER EXPERIENCE EXAMPLE

**Scenario: Customer uploads email + vehicle docs**

**Step 1: Upload**
```
Files uploaded:
- enquiry.eml (email from customer)
- toyota_camry_reg.pdf (registration document)
- car_photo.jpg (vehicle picture)
```

**Step 2: Automated Processing**
```
✅ Email extracted: Contact=John Doe, Route=BEANR→NGLOS, Service=RORO Export
✅ PDF extracted: Make=Toyota, Model=Camry, VIN=XX123, Weight=1500kg, Dims=485x183x145cm
✅ Image OCR: License plate verified, visual inspection OK
```

**Step 3: Quotation Auto-Created**
```
Quotation Request #QR-2025-0042
Source: Intake #156
Customer: John Doe (john@example.com)
Route: Antwerp → Lagos
Service: RORO Export

✅ Commodity Items (Auto-populated):
┌──────────────────────────────────────────┐
│ Item 1: Toyota Camry                     │
│ ├─ Type: Car                             │
│ ├─ VIN: XX123                            │
│ ├─ Weight: 1500 kg                       │
│ ├─ Dimensions: 485 x 183 x 145 cm        │
│ ├─ Fuel: Petrol                          │
│ ├─ Condition: Used                       │
│ └─ Source: Auto-extracted from intake    │
└──────────────────────────────────────────┘

[+ Add Additional Item] button available
```

**Step 4: Admin Action**
- Reviews auto-populated data ✅
- Edits if needed (e.g., add wheelbase) ✅
- Adds second vehicle manually (if email mentioned "2 cars") ✅
- Proceeds with pricing ✅

---

### ✅ FINAL SUCCESS CRITERIA - COMMODITY ENHANCED

1. ✅ Email, Image, PDF completely isolated (can deploy independently)
2. ✅ Multi-file uploads work (.eml + docs together)
3. ✅ ONE Robaws offer from multiple files
4. ✅ Files process in parallel on separate queues
5. ✅ Partial failures handled gracefully
6. ✅ **Extracted data auto-populates commodity items** ⭐
7. ✅ **Vehicle docs → auto-fill: make, model, VIN, dims, weight, fuel** ⭐
8. ✅ **Machinery docs → auto-fill: type, make, model, specs** ⭐
9. ✅ **General cargo → auto-fill: packaging, weights, dimensions** ⭐
10. ✅ **Admin can review/edit auto-populated items in Filament** ⭐
11. ✅ **Commodity validation works seamlessly with auto-data** ⭐
12. ✅ **Category auto-detection (car/SUV/truck) from extracted text** ⭐
13. ✅ **Fuel type auto-detection (petrol/diesel/electric/hybrid)** ⭐
14. ✅ **Multiple items from one intake (e.g., "2 cars" → 2 commodity items)** ⭐

---

**PRIORITY: IMPLEMENT PHASE 0 WITH COMMODITY MAPPING IMMEDIATELY**

**Next Step:** Create `CommodityMappingService` and update extraction strategies! 🚀
