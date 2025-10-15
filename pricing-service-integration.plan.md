# 🔍 Intake Architecture - Isolation + Multi-Commodity Integration Plan

## Executive Summary

The Intake system requires **three critical enhancements**:

1. **TYPE ISOLATION**: Email, Image, and PDF intakes must work independently - changes to one type won't break others
2. **MULTI-DOCUMENT UPLOAD**: Support uploading .eml + vehicle docs (PDF/images) + invoices together
3. **MULTI-COMMODITY AUTO-POPULATION**: Extracted data auto-fills the multi-commodity quotation form

**Key Business Flow:** Email enquiry + vehicle registration PDF + car photo → ONE Robaws offer with auto-populated vehicle commodity items

---

## 🔒 ISOLATION + MULTI-DOCUMENT + COMMODITY ARCHITECTURE

### Combined Requirements:

1. ✅ Email changes don't break image/PDF processing
2. ✅ Image changes don't break email/PDF processing  
3. ✅ PDF changes don't break email/image processing
4. ✅ Upload .eml + screenshots + PDFs together in one batch
5. ✅ Each file processes through its isolated pipeline
6. ✅ Aggregate results into ONE Robaws offer
7. ✅ **NEW:** Auto-populate commodity items from extracted data
8. ✅ **NEW:** Vehicle docs → auto-fill vehicle form fields
9. ✅ **NEW:** Admin can review/edit auto-populated items

### Solution: **Type-Isolated Pipelines with Commodity Mapping**

```
User Upload: enquiry.eml + registration.pdf + car.jpg
              ↓
    IntakeCreationService.createFromMultipleFiles()
              ↓
   ONE Intake + THREE IntakeFiles
              ↓
    ┌─────────┴─────────┬────────────┐
    ↓                   ↓            ↓
EmailPipeline      PdfPipeline   ImagePipeline
(queue: email)     (queue: pdf)  (queue: image)
    ↓                   ↓            ↓
Extract contact    Extract VIN   OCR license
Extract route      Extract dims  plate/specs
    ↓                   ↓            ↓
Update file #1     Update #2     Update #3
status: completed  completed     completed
    └─────────┬─────────┴────────────┘
              ↓
    IntakeAggregationService
    (waits for ALL files to complete)
              ↓
    CommodityMappingService (NEW!)
    Maps extracted data to commodity items:
    - Detect type: vehicles/machinery/cargo/boat
    - Map fields: make, model, VIN, dims, weight, fuel
              ↓
    Create QuotationRequest with auto-populated items:
    {
      commodity_items: [
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
          fuel_type: 'petrol',
          condition: 'used'
        }
      ]
    }
              ↓
    Admin reviews pre-filled commodity form ✅
    Can edit/add more items ✅
              ↓
    Export to Robaws → ONE offer with all files
```

---

## 📋 IMPLEMENTATION PLAN

### Phase 0: Foundation (Week 0 - Critical)

**Create Isolation Infrastructure:**

- [ ] Create `app/Services/Intake/Pipelines/` directory
- [ ] Create `EmailIntakePipeline.php`
- [ ] Create `ImageIntakePipeline.php`
- [ ] Create `PdfIntakePipeline.php`
- [ ] Create `IntakePipelineFactory.php` (routes by mime type)
- [ ] Set up 3 separate queues in `config/queue.php`:
  ```php
  'email-intakes' => [...],
  'image-intakes' => [...],
  'pdf-intakes' => [...]
  ```

**Add Multi-Document Support:**

- [ ] Migration: Add to `intakes` table:
  ```php
  $table->boolean('is_multi_file')->default(false);
  $table->integer('total_files')->default(1);
  $table->integer('processed_files')->default(0);
  $table->json('file_processing_status')->nullable();
  ```

- [ ] Migration: Add to `intake_files` table:
  ```php
  $table->string('processing_status')->default('pending');
  $table->json('extraction_data')->nullable();
  $table->text('processing_error')->nullable();
  $table->timestamp('processed_at')->nullable();
  ```

- [ ] Create `IntakeAggregationService.php`
- [ ] Update `IntakeCreationService`:
  ```php
  public function createFromMultipleFiles(array $files, array $options = []): Intake
  ```

**Add Commodity Mapping:**

- [ ] Create `app/Services/Intake/CommodityMappingService.php`
- [ ] Implement methods:
  - `mapToCommodityItems(array $extractionData): array`
  - `mapToVehicles(array $data): array`
  - `mapToMachinery(array $data): array`
  - `mapToGeneralCargo(array $data): array`
  - `mapToBoat(array $data): array`
  - `detectVehicleCategory()` - auto-detect car/SUV/van/truck
  - `detectFuelType()` - auto-detect petrol/diesel/electric/hybrid
  - `detectCondition()` - auto-detect new/used/damaged

- [ ] Update `IntakeObserver` to use commodity mapping:
  ```php
  $commodityItems = app(CommodityMappingService::class)
      ->mapToCommodityItems($intake->extraction_data);
  
  foreach ($commodityItems as $item) {
      $quotation->commodityItems()->create($item);
  }
  ```

- [ ] Add commodity mapping unit tests

---

### Phase 1: Email Isolation (Week 1)

- [ ] Implement `EmailIntakePipeline` with all email-specific logic
- [ ] Create `ProcessEmailIntakeJob` (dedicated email queue)
- [ ] Update extraction to capture commodity hints (e.g., "2 cars")
- [ ] Test: Single .eml upload
- [ ] Test: .eml + image multi-upload
- [ ] Test: .eml + PDF multi-upload
- [ ] Feature flag: `INTAKE_USE_EMAIL_PIPELINE=true`
- [ ] Monitor for 1 week

---

### Phase 2: Image Isolation (Week 2)

- [ ] Implement `ImageIntakePipeline` with all OCR logic
- [ ] Create `ProcessImageIntakeJob` (dedicated image queue)
- [ ] Enhance OCR to extract: license plate, make/model, visible specs
- [ ] Test: Single image upload
- [ ] Test: Image + .eml multi-upload
- [ ] Test: Image + PDF multi-upload
- [ ] Feature flag: `INTAKE_USE_IMAGE_PIPELINE=true`
- [ ] Monitor for 1 week

---

### Phase 2.5: Commodity Integration Testing (Mid-Week 2)

- [ ] Test: Vehicle registration PDF → auto-creates vehicle commodity items
- [ ] Test: Machinery document → auto-creates machinery items
- [ ] Test: General cargo invoice → auto-creates cargo items
- [ ] Test: Mixed commodities (vehicle + spare parts) → creates both types
- [ ] Test: Email says "2 cars" but only 1 PDF → creates 1 full item + 1 placeholder
- [ ] Validate: All commodity fields map correctly to quotation form
- [ ] Validate: CBM auto-calculation works with extracted dimensions
- [ ] Admin review flow works smoothly

---

### Phase 3: PDF Isolation (Week 3)

- [ ] Implement `PdfIntakePipeline` with all text extraction logic
- [ ] Create `ProcessPdfIntakeJob` (dedicated PDF queue)
- [ ] Enhance PDF extraction to capture: VIN, dimensions, weight, cargo value
- [ ] Test: Single PDF upload
- [ ] Test: PDF + .eml multi-upload
- [ ] Test: PDF + image multi-upload
- [ ] Feature flag: `INTAKE_USE_PDF_PIPELINE=true`
- [ ] Monitor for 1 week

---

### Phase 4: Complete Integration (Week 4)

- [ ] Test: .eml + image + PDF (all three together)
- [ ] Test: Multiple files of same type (3 images, 2 PDFs)
- [ ] Test: Partial failure scenarios (1 file fails, others succeed)
- [ ] Finalize aggregation logic
- [ ] Create multi-upload API endpoint: `POST /api/intakes/multi-upload`
- [ ] Update Filament admin for multi-file uploads
- [ ] Filament shows "Auto-populated from intake" indicators
- [ ] User can seamlessly add/edit/remove auto-populated commodity items
- [ ] Delete old shared `IntakeOrchestratorJob`
- [ ] Remove feature flags (make isolated pipelines default)
- [ ] End-to-end: .eml + vehicle docs → quotation with pre-filled commodity items
- [ ] Commodity validation works with auto-populated data
- [ ] Robaws export includes all commodity item data

---

## 🧪 VALIDATION CHECKLIST

### Isolation Tests:

- [ ] Delete `ImageIntakePipeline.php` → Email still works ✅
- [ ] Delete `EmailIntakePipeline.php` → Image still works ✅
- [ ] Delete `PdfIntakePipeline.php` → Email/Image still work ✅
- [ ] Stop email queue → Image/PDF queues unaffected ✅
- [ ] Crash email extractor → Image/PDF processing continues ✅

### Multi-Document Tests:

- [ ] Upload .eml + image → Both process, single quotation ✅
- [ ] Upload .eml + PDF → Both process, single quotation ✅
- [ ] Upload image + PDF → Both process, single quotation ✅
- [ ] Upload .eml + image + PDF → All three process, single quotation ✅
- [ ] Upload fails one file → Other files still process ✅
- [ ] Aggregation waits for all files before creating quotation ✅

### Commodity Auto-Population Tests:

- [ ] Vehicle PDF → auto-fills: make, model, VIN, dimensions, weight, fuel ✅
- [ ] Machinery doc → auto-fills: type, make, model, specs ✅
- [ ] General cargo → auto-fills: packaging, weights, dimensions ✅
- [ ] Multiple vehicles extracted → creates multiple commodity items ✅
- [ ] Category auto-detection works (car/SUV/truck from text) ✅
- [ ] Fuel type auto-detection works (petrol/diesel/electric/hybrid) ✅
- [ ] Condition auto-detection works (new/used/damaged) ✅
- [ ] Admin can edit auto-populated fields ✅
- [ ] Validation works seamlessly with auto-data ✅

---

## 🚀 API ENDPOINTS

### New Multi-Upload Endpoint:

```php
POST /api/intakes/multi-upload
Content-Type: multipart/form-data

{
  "files[]": [enquiry.eml, registration.pdf, car.jpg],
  "customer_name": "John Doe",
  "contact_email": "john@example.com"
}

Response:
{
  "success": true,
  "intake_id": 123,
  "files_count": 3,
  "files": [
    {"id": 1, "filename": "enquiry.eml", "type": "email", "status": "processing"},
    {"id": 2, "filename": "registration.pdf", "type": "pdf", "status": "processing"},
    {"id": 3, "filename": "car.jpg", "type": "image", "status": "processing"}
  ],
  "message": "Processing 3 files. Results will be aggregated into one quotation."
}
```

---

## 🎨 FILAMENT ADMIN: REVIEW AUTO-POPULATED ITEMS

```php
// QuotationRequestResource.php - Enhanced UI
Section::make('Intake Source')
    ->visible(fn ($record) => $record?->intake_id)
    ->schema([
        Placeholder::make('intake_info')
            ->label('Created From')
            ->content(fn ($record) => "✅ Intake #{$record->intake_id}"),
            
        Placeholder::make('auto_populated_count')
            ->label('Auto-Populated Items')
            ->content(fn ($record) => 
                "{$record->total_commodity_items} commodity items automatically extracted"
            )
            ->helperText('Review and edit below, or add additional items'),
    ]),

Section::make('Commodity Items')
    ->description('Auto-populated from intake - review and edit as needed')
    ->schema([
        Repeater::make('commodityItems')
            ->relationship()
            ->schema([/* same as customer/public forms */])
            ->itemLabel(fn ($state) => 
                trim(($state['make'] ?? '') . ' ' . ($state['type_model'] ?? 'Item'))
            )
            ->defaultItems(0) // Don't add empty if already populated
            ->addActionLabel('Add Additional Item'),
    ]),
```

---

## 💡 USER EXPERIENCE EXAMPLE

### Scenario: Customer uploads email + vehicle documents

**Step 1: Upload**
```
Files: enquiry.eml + toyota_camry_reg.pdf + car_photo.jpg
```

**Step 2: Automated Processing**
```
✅ Email extracted: Contact=John Doe, Route=Antwerp→Lagos, Service=RORO Export
✅ PDF extracted: Make=Toyota, Model=Camry, VIN=XX123, Weight=1500kg, Dims=485x183x145cm
✅ Image OCR: License plate verified, visual inspection OK
```

**Step 3: Quotation Auto-Created**
```
Quotation Request #QR-2025-0042
Source: Intake #156
Customer: John Doe (john@example.com)
Route: BEANR → NGLOS

✅ Commodity Items (Auto-populated):
┌──────────────────────────────────────────┐
│ Vehicle 1: Toyota Camry                  │
│ ├─ Category: Car                         │
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
- Edits if needed (e.g., add wheelbase for airfreight) ✅
- Proceeds with pricing ✅
- Exports to Robaws → ONE offer with vehicle data + 3 files attached ✅

---

## ⏱️ ESTIMATED TIMELINE

- **Week 0:** Foundation (pipelines + multi-file + commodity mapper) - 3-5 days
- **Week 1:** Email isolation + multi-file email - 5 days
- **Week 2:** Image isolation + commodity integration - 5 days
- **Week 3:** PDF isolation + enhanced extraction - 5 days
- **Week 4:** Complete integration + testing + cleanup - 5 days

**Total:** 4-5 weeks for complete isolation + multi-document + commodity auto-population

---

## 🎯 SUCCESS CRITERIA

### Core Requirements:
1. ✅ Email, Image, PDF completely isolated (can deploy independently)
2. ✅ Single file uploads work (backward compatible)
3. ✅ Multi-file uploads work (.eml + image + PDF together)
4. ✅ Files process in parallel on separate queues
5. ✅ Partial failures handled (some files succeed, others fail)
6. ✅ ONE quotation + ONE Robaws offer from multi-file uploads
7. ✅ Each type has dedicated tests
8. ✅ Monitoring shows queue independence

### Commodity Integration:
9. ✅ Extracted data auto-populates commodity items
10. ✅ Vehicle docs → auto-fill: make, model, VIN, dimensions, weight, fuel, condition
11. ✅ Machinery docs → auto-fill: type, make, model, specs, parts info
12. ✅ General cargo → auto-fill: packaging, weights, dimensions
13. ✅ Boat docs → auto-fill: dimensions, weight, trailer/cradle options
14. ✅ Category auto-detection (car/SUV/truck) from extracted text
15. ✅ Fuel type auto-detection (petrol/diesel/electric/hybrid)
16. ✅ Multiple items from one intake (e.g., "2 cars" → 2 commodity items)
17. ✅ Admin can review/edit auto-populated items in Filament
18. ✅ Commodity validation works seamlessly with auto-data
19. ✅ CBM auto-calculation works with extracted dimensions

---

## 📚 DOCUMENTATION

### Files Created/Updated:
- ✅ `INTAKE_ARCHITECTURE_ISOLATION_PLAN.md` - Detailed technical spec
- ✅ `pricing-service-integration.plan.md` - This implementation plan
- [ ] `docs/INTAKE_API.md` - API documentation (to be created)
- [ ] `docs/COMMODITY_MAPPING.md` - Commodity mapping guide (to be created)

---

**PRIORITY: START WITH PHASE 0 IMMEDIATELY**

**Next Steps:**
1. Run database migrations for multi-file support
2. Create pipeline directory structure
3. Implement `CommodityMappingService`
4. Begin email pipeline isolation

**Ready to execute? Just say "start implementation" and I'll begin!** 🚀
