# Complete Bi-Directional Pricing & Customer Sync System

## 🎯 Executive Summary

Implement a **bi-directional sync system** (Robaws ↔ Bconnect) for:
- **Customers** (4,017 customers with roles)
- **Carrier Prices** (imported from Robaws)
- **Articles** (auto-generated from carrier prices with margins)
- **Role Adjustments** (customer-specific pricing rules)
- **Quote Generation** (dynamic pricing with full audit trail)

**Architecture**: Same proven pattern as article sync (webhooks + scheduled sync + manual triggers)

---

## 📊 Pricing Model (Final Clarification)

### Three-Tier Pricing System

**Tier 1: Carrier Prices (Source)**
```
Robaws → Bconnect
Carrier: Maersk
Unit Type: 20ft DV
Carrier Price: €1,200 (what carrier charges us)
```

**Tier 2: Article Sale Prices (Calculated)**
```
Bconnect calculates & pushes back to Robaws
Carrier Price: €1,200
+ Standard Margin: €300
= Article Sale Price: €1,500 ✓
```

**Tier 3: Customer Quote Prices (Dynamic)**
```
Bconnect generates quotes
Article Sale Price: €1,500
+ Customer Role Adjustment (FORWARDER): -€100
= Quote Price: €1,400
```

### Pricing Flow Example

**Step 1**: Import carrier price from Robaws
```
Maersk 20ft DV Seafreight
Carrier Price: €1,200
```

**Step 2**: Calculate article sale price in Bconnect
```
€1,200 + €300 margin = €1,500
Auto-create article "Maersk 20ft DV"
Sync back to Robaws with sale_price = €1,500
```

**Step 3**: Generate customer quote
```
Customer: Aeon Shipping LLC (FORWARDER)
Article: Maersk 20ft DV (€1,500)
FORWARDER adjustment: -€100
Quote price: €1,400 × 2 = €2,800
```

---

## 🔄 Bi-Directional Sync Architecture

### Sync Flow Diagram

```
┌─────────────────────────────────────────────────────────┐
│                        ROBAWS                           │
│  - Customers (4,017)                                    │
│  - Carrier Prices (per carrier/unit)                    │
│  - Articles (generated)                                 │
└──────────────┬──────────────────────┬───────────────────┘
               │                      │
        Webhooks │                 API │ Scheduled Sync
               ↓                      ↓
┌─────────────────────────────────────────────────────────┐
│                       BCONNECT                          │
│  ┌─────────────────────────────────────────────────┐   │
│  │ 1. Import & Cache                               │   │
│  │    - Customers (robaws_customers_cache)         │   │
│  │    - Carrier Prices (carrier_prices)            │   │
│  ├─────────────────────────────────────────────────┤   │
│  │ 2. Calculate & Enrich                           │   │
│  │    - Add standard margins                       │   │
│  │    - Calculate article sale prices              │   │
│  │    - Apply role adjustments                     │   │
│  ├─────────────────────────────────────────────────┤   │
│  │ 3. Auto-Generate                                │   │
│  │    - Create articles from carrier prices        │   │
│  │    - Maintain price history                     │   │
│  └─────────────────────────────────────────────────┘   │
└──────────────┬──────────────────────┬───────────────────┘
               │                      │
     Sync Back │                 API │ Push Updates
               ↓                      ↓
┌─────────────────────────────────────────────────────────┐
│                        ROBAWS                           │
│  - Updated article sale prices                          │
│  - Customer updates (if edited in Bconnect)             │
└─────────────────────────────────────────────────────────┘
```

---

## 📋 Database Schema (Complete)

### Table 1: `robaws_customers_cache`

```php
Schema::create('robaws_customers_cache', function (Blueprint $table) {
    $table->id();
    $table->string('robaws_client_id')->unique();
    $table->string('name')->index();
    $table->string('role')->index(); // FORWARDER, POV, BROKER, etc.
    $table->string('email')->nullable();
    $table->string('phone')->nullable();
    $table->text('address')->nullable();
    $table->string('city')->nullable()->index();
    $table->string('country')->nullable()->index();
    $table->string('postal_code')->nullable();
    $table->string('vat_number')->nullable();
    $table->decimal('default_role_adjustment', 10, 2)->default(0.00); // Default discount/premium
    $table->boolean('is_active')->default(true);
    $table->json('metadata')->nullable();
    $table->timestamp('last_synced_at')->nullable();
    $table->timestamp('last_pushed_to_robaws_at')->nullable(); // Track push
    $table->timestamps();
    
    $table->index(['role', 'is_active']);
    $table->index(['last_synced_at']);
});
```

### Table 2: `unit_types`

```php
Schema::create('unit_types', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique(); // 20FT_DV, CBM, LM, W/M
    $table->string('name'); // "20ft Dry Van"
    $table->string('robaws_unit_code')->nullable(); // Robaws internal code
    $table->string('category')->nullable(); // container, volume, weight, lumpsum
    $table->boolean('is_active')->default(true);
    $table->integer('sort_order')->default(0);
    $table->timestamps();
    
    $table->index(['code', 'is_active']);
});
```

### Table 3: `carriers`

```php
Schema::create('carriers', function (Blueprint $table) {
    $table->id();
    $table->string('robaws_carrier_id')->unique()->nullable(); // Robaws ID
    $table->string('name'); // Maersk, MSC, CMA CGM
    $table->string('code')->unique();
    $table->string('type')->nullable(); // shipping_line, airline, trucker, customs
    $table->boolean('is_active')->default(true);
    $table->json('metadata')->nullable();
    $table->timestamp('last_synced_at')->nullable();
    $table->timestamps();
    
    $table->index(['code', 'is_active']);
});
```

### Table 4: `carrier_prices` (with history)

```php
Schema::create('carrier_prices', function (Blueprint $table) {
    $table->id();
    $table->string('robaws_price_id')->unique()->nullable(); // Robaws reference
    $table->foreignId('carrier_id')->constrained()->onDelete('cascade');
    $table->foreignId('unit_type_id')->constrained('unit_types')->onDelete('cascade');
    
    // Pricing data
    $table->decimal('carrier_price', 10, 2); // What carrier charges us
    $table->decimal('standard_margin', 10, 2)->default(0.00); // Our markup
    $table->decimal('article_sale_price', 10, 2)->storedAs('carrier_price + standard_margin'); // Computed
    
    $table->string('currency', 3)->default('EUR');
    
    // Validity & History
    $table->date('valid_from')->nullable();
    $table->date('valid_until')->nullable();
    $table->timestamp('price_updated_at')->nullable();
    $table->boolean('is_active')->default(true);
    $table->boolean('is_current')->default(true); // Latest version flag
    
    // Sync tracking
    $table->timestamp('last_synced_at')->nullable();
    $table->timestamp('last_pushed_to_robaws_at')->nullable();
    
    $table->text('notes')->nullable();
    $table->timestamps();
    
    // Multiple versions per carrier/unit (for history)
    $table->index(['carrier_id', 'unit_type_id', 'is_current']);
    $table->index(['valid_from', 'valid_until']);
    $table->index(['is_active', 'is_current']);
});
```

### Table 5: `customer_role_adjustments`

```php
Schema::create('customer_role_adjustments', function (Blueprint $table) {
    $table->id();
    $table->string('role'); // FORWARDER, POV, etc.
    $table->foreignId('unit_type_id')->nullable()->constrained('unit_types')->onDelete('cascade');
    
    // Adjustment logic
    $table->decimal('adjustment_amount', 10, 2)->default(0.00); // + or -
    $table->boolean('is_default')->default(false); // Default role-wide adjustment
    
    $table->boolean('is_active')->default(true);
    $table->text('notes')->nullable();
    $table->timestamps();
    
    // Unique: One default per role, or one per role+unit
    $table->unique(['role', 'unit_type_id', 'is_default']);
    $table->index(['role', 'is_active']);
});
```

### Table 6: Update `robaws_articles_cache`

```php
Schema::table('robaws_articles_cache', function (Blueprint $table) {
    $table->foreignId('carrier_id')->nullable()->after('robaws_article_id')->constrained('carriers');
    $table->foreignId('carrier_price_id')->nullable()->after('carrier_id')->constrained('carrier_prices');
    $table->foreignId('unit_type_id')->nullable()->after('carrier_price_id')->constrained('unit_types');
    
    $table->decimal('carrier_price', 10, 2)->nullable()->after('cost_price');
    $table->decimal('standard_margin', 10, 2)->nullable()->after('carrier_price');
    // sale_price already exists - this is the final article sale price
    
    $table->date('price_valid_from')->nullable()->after('sale_price');
    $table->date('price_valid_until')->nullable()->after('price_valid_from');
    $table->timestamp('price_last_updated_at')->nullable()->after('price_valid_until');
    
    $table->timestamp('last_pushed_to_robaws_at')->nullable()->after('last_synced_at');
});
```

---

## 🔧 Core Services

### Service 1: `RobawsCustomerSyncService.php`

**Location**: `app/Services/Robaws/RobawsCustomerSyncService.php`

**Responsibilities**:
- Fetch customers from Robaws API (`/api/v2/clients`)
- Process and cache customer data (4,017 customers)
- Extract role field (FORWARDER, POV, etc.)
- Push customer updates back to Robaws (if edited in Bconnect)
- Handle webhooks (`client.created`, `client.updated`)

**Key Methods**:
```php
syncAllCustomers(bool $fullSync = false): array
syncSingleCustomer(string $clientId): RobawsCustomerCache
processCustomerFromWebhook(array $webhookData): RobawsCustomerCache
pushCustomerToRobaws(RobawsCustomerCache $customer): bool
```

### Service 2: `RobawsCarrierSyncService.php` (NEW)

**Location**: `app/Services/Robaws/RobawsCarrierSyncService.php`

**Responsibilities**:
- Fetch carrier data from Robaws API
- Sync carrier list (Maersk, MSC, CMA CGM, etc.)
- Handle carrier updates

**Key Methods**:
```php
syncAllCarriers(): array
syncSingleCarrier(string $carrierId): Carrier
```

### Service 3: `RobawsCarrierPriceSyncService.php` (NEW)

**Location**: `app/Services/Robaws/RobawsCarrierPriceSyncService.php`

**Responsibilities**:
- Import carrier prices from Robaws
- Calculate article sale prices (carrier_price + standard_margin)
- Maintain price history (create new version when price changes)
- Auto-create/update articles
- Push article sale prices back to Robaws

**Key Methods**:
```php
syncCarrierPrices(bool $fullSync = false): array
processCarrierPriceFromRobaws(array $priceData): CarrierPrice
calculateArticleSalePrice(CarrierPrice $carrierPrice): float
autoCreateOrUpdateArticle(CarrierPrice $carrierPrice): RobawsArticleCache
pushArticleSalePriceToRobaws(RobawsArticleCache $article): bool
archiveOldPrice(CarrierPrice $oldPrice): void // Mark as not current
```

**Price History Logic**:
```php
// When carrier price changes:
1. Mark old CarrierPrice record as is_current=false (archive)
2. Create new CarrierPrice record with is_current=true
3. Update article sale_price
4. Push new sale_price to Robaws
5. Keep both records for history
```

### Service 4: `CustomerPricingService.php`

**Location**: `app/Services/Pricing/CustomerPricingService.php`

**Responsibilities**:
- Calculate final quote prices (article_sale_price + role_adjustment)
- Apply default role-wide adjustments
- Apply unit-specific adjustments (overrides)
- Generate complete quotes with line items

**Key Methods**:
```php
calculateQuote(RobawsCustomerCache $customer, array $quoteItems): array
getAdjustmentForCustomer(RobawsCustomerCache $customer, string $unitTypeCode): float
applyAdjustment(float $articleSalePrice, float $adjustment): float
```

**Adjustment Priority Logic**:
```php
1. Check for unit-specific adjustment (role + unit_type)
2. If not found, use default role-wide adjustment (role + is_default=true)
3. If not found, use customer's default_role_adjustment
4. If none, adjustment = 0
```

---

## 🎯 Artisan Commands

### Command 1: `SyncRobawsCustomers.php`

```bash
php artisan robaws:sync-customers              # Incremental sync
php artisan robaws:sync-customers --full       # Full sync (4,017 customers)
php artisan robaws:sync-customers --push       # Push local changes to Robaws
```

### Command 2: `SyncRobawsCarriers.php` (NEW)

```bash
php artisan robaws:sync-carriers               # Sync carrier list
```

### Command 3: `SyncRobawsCarrierPrices.php` (NEW)

```bash
php artisan robaws:sync-carrier-prices         # Incremental sync
php artisan robaws:sync-carrier-prices --full  # Full sync
php artisan robaws:sync-carrier-prices --push  # Push article prices to Robaws
php artisan robaws:sync-carrier-prices --carrier=maersk  # Sync specific carrier
```

### Command 4: `ImportCarrierPricesFromCsv.php` (NEW)

```bash
php artisan robaws:import-carrier-prices path/to/prices.csv
```

**CSV Format**:
```csv
carrier_code,unit_type_code,carrier_price,standard_margin,valid_from,valid_until
maersk,20FT_DV,1200.00,300.00,2025-01-01,2025-12-31
maersk,40FT_DV,1800.00,400.00,2025-01-01,2025-12-31
msc,CBM,5.00,3.00,2025-01-01,2025-12-31
```

### Command 5: `RegisterCustomerWebhooks.php`

```bash
php artisan robaws:register-customer-webhooks
```

Registers: `client.created`, `client.updated`

---

## 🌐 Webhooks (Bi-Directional)

### Inbound Webhooks (Robaws → Bconnect)

**Route 1**: Customer webhooks
```php
Route::post('/webhooks/robaws/customers', [RobawsWebhookController::class, 'handleCustomer'])
    ->middleware('throttle:60,1')
    ->name('webhooks.robaws.customers');
```

**Route 2**: Carrier price webhooks (if supported by Robaws)
```php
Route::post('/webhooks/robaws/carrier-prices', [RobawsWebhookController::class, 'handleCarrierPrice'])
    ->middleware('throttle:60,1')
    ->name('webhooks.robaws.carrier_prices');
```

### Outbound Sync (Bconnect → Robaws)

**Trigger Points**:
1. Article created from carrier price → Push `sale_price` to Robaws
2. Article `sale_price` updated → Push update to Robaws
3. Customer edited in Bconnect → Push update to Robaws
4. Scheduled push (daily) for any missed updates

---

## 🎨 Filament Resources (Complete)

### Resource 1: `RobawsCustomerResource.php`

**Table Columns**:
- Name, Role (badge with color), Email, Phone
- City, Country, VAT Number
- Default Role Adjustment (€)
- Active status, Last synced

**Filters**:
- Role (16 options)
- City, Country
- Active status
- Has adjustment

**Actions**:
- Edit customer
- **Push to Robaws** (individual)
- View sync history

**Header Actions**:
- **Sync Customers** (incremental from Robaws)
- **Full Sync** (all 4,017 customers)
- **Push All Changes** (to Robaws)
- Import from CSV

### Resource 2: `CarrierResource.php` (NEW)

**Table**: List of carriers (Maersk, MSC, CMA CGM, etc.)

**Header Actions**:
- **Sync Carriers** (from Robaws)
- Add carrier manually

### Resource 3: `CarrierPriceResource.php` (NEW) ⭐⭐⭐

**THE MAIN PRICING MANAGEMENT PAGE**

**Table View**:
```
┌──────────────────────────────────────────────────────────────────────────────┐
│ Carrier Prices & Margins                        [Sync] [Import CSV] [Export] │
├──────────────────────────────────────────────────────────────────────────────┤
│ Carrier  Unit     Carrier  Margin  Sale    Valid From  Valid Until  Current  │
│ Maersk   20ft DV  €1,200   €300    €1,500  2025-01-01  2025-12-31   ✅       │
│ Maersk   20ft DV  €1,150   €300    €1,450  2024-01-01  2024-12-31   ❌ OLD   │
│ Maersk   40ft DV  €1,800   €400    €2,200  2025-01-01  2025-12-31   ✅       │
│ MSC      CBM      €5.00    €3.00   €8.00   2025-01-01  2025-12-31   ✅       │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Filters**:
- Carrier
- Unit Type
- Is Current (show only latest)
- Date range (valid_from/valid_until)

**Form**:
```
┌─────────────────────────────────────────────────────────┐
│ Carrier Price Configuration                             │
├─────────────────────────────────────────────────────────┤
│ Carrier: [Select: Maersk, MSC, ...]                     │
│ Unit Type: [Select: 20ft DV, 40ft DV, CBM, ...]         │
│                                                          │
│ Carrier Price: € _____.__                               │
│ Standard Margin: € _____.__                             │
│ ──────────────────────────────────────────────────────  │
│ Article Sale Price: € 1,500.00 ✓ (auto-calculated)      │
│                                                          │
│ Valid From: [date picker]                               │
│ Valid Until: [date picker]                              │
│ Currency: [EUR]                                         │
│                                                          │
│ Notes: [text area]                                      │
│                                                          │
│ ☑ Auto-create article (if doesn't exist)                │
│ ☑ Update existing article sale price                    │
│ ☑ Push to Robaws immediately                            │
│                                                          │
│ [Save] [Save & Create Another]                          │
└─────────────────────────────────────────────────────────┘
```

**Bulk Actions**:
- **Bulk Import from CSV**
- **Create Articles** (from selected carrier prices)
- **Push to Robaws** (selected prices)
- **Archive Old Prices** (mark as not current)
- **Clone to Another Carrier** (copy price structure)

**Header Actions**:
- **Sync from Robaws** (import carrier prices)
- **Import CSV**
- **Export Current Prices** (Excel)
- **View Price History** (show archived prices)

### Resource 4: `UnitTypeResource.php`

Simple CRUD for unit types (20ft DV, CBM, LM, etc.)

### Resource 5: `CustomerRoleAdjustmentResource.php`

**Price Adjustment Management**

**Table View**:
```
┌────────────────────────────────────────────────────────────────┐
│ Customer Role Adjustments              [Add] [Bulk Import]     │
├────────────────────────────────────────────────────────────────┤
│ Role       Unit Type  Adjustment  Default  Notes               │
│ FORWARDER  -          -€50        ✅       Default discount    │
│ FORWARDER  20ft DV    -€100       ❌       Container discount  │
│ FORWARDER  CBM        -€1.00      ❌       Volume discount     │
│ TOURIST    -          +€100       ✅       Retail markup       │
│ TOURIST    20ft DV    +€200       ❌       Container premium   │
│ POV        -          -€25        ✅       Partner discount    │
└────────────────────────────────────────────────────────────────┘
```

**Form**:
```
┌──────────────────────────────────────────────────┐
│ Role Adjustment Configuration                    │
├──────────────────────────────────────────────────┤
│ Customer Role: [Select: FORWARDER, POV, ...]     │
│                                                   │
│ Adjustment Type:                                 │
│   ○ Default (applies to all units)               │
│   ○ Unit-Specific (override for specific unit)   │
│                                                   │
│ Unit Type: [Select if unit-specific]             │
│                                                   │
│ Adjustment Amount: € _____.__                    │
│   (+ for markup, - for discount)                 │
│                                                   │
│ Notes: [text area]                               │
│                                                   │
│ [Save]                                           │
└──────────────────────────────────────────────────┘
```

### Resource 6: Update `RobawsArticleResource.php`

**Add columns**:
- Carrier (relation)
- Carrier Price
- Standard Margin
- Sale Price
- Price Valid From/Until

**Add actions**:
- **Recalculate Sale Price** (from carrier price + margin)
- **Push to Robaws**
- **View Price History**

---

## 🗓️ Scheduling (Automated Sync)

### `routes/console.php`

```php
use Illuminate\Support\Facades\Schedule;

// === CUSTOMER SYNC ===
// Daily incremental customer sync (Robaws → Bconnect)
Schedule::command('robaws:sync-customers')
    ->daily()
    ->at('03:00')
    ->withoutOverlapping();

// Weekly full customer sync (safety net)
Schedule::command('robaws:sync-customers --full')
    ->weekly()
    ->sundays()
    ->at('04:00');

// Push customer updates (Bconnect → Robaws)
Schedule::command('robaws:sync-customers --push')
    ->daily()
    ->at('22:00'); // Evening push

// === CARRIER & PRICING SYNC ===
// Daily carrier sync
Schedule::command('robaws:sync-carriers')
    ->daily()
    ->at('02:00');

// Daily carrier price sync (Robaws → Bconnect)
Schedule::command('robaws:sync-carrier-prices')
    ->daily()
    ->at('02:30')
    ->withoutOverlapping();

// Push article prices to Robaws (Bconnect → Robaws)
Schedule::command('robaws:sync-carrier-prices --push')
    ->daily()
    ->at('23:00'); // Evening push

// Weekly full carrier price sync (safety net)
Schedule::command('robaws:sync-carrier-prices --full')
    ->weekly()
    ->sundays()
    ->at('05:00');

// === ARTICLE SYNC (existing) ===
Schedule::command('robaws:sync-articles')
    ->daily()
    ->at('01:00');
```

---

## 🧪 Testing Workflow

### Phase 1: Customer Sync Testing

1. Run `php artisan robaws:sync-customers --full`
2. Verify 4,017 customers imported
3. Check "Aeon Shipping LLC" has role "FORWARDER"
4. Edit a customer in Bconnect (change email)
5. Run `php artisan robaws:sync-customers --push`
6. Verify update in Robaws

### Phase 2: Carrier Price Testing

1. Run `php artisan robaws:sync-carriers`
2. Verify carriers imported (Maersk, MSC, etc.)
3. Run `php artisan robaws:sync-carrier-prices --full`
4. Verify carrier prices imported
5. Check articles auto-created with sale_price
6. Verify prices pushed to Robaws

### Phase 3: Bulk Import Testing

1. Create CSV with carrier prices
2. Run `php artisan robaws:import-carrier-prices prices.csv`
3. Verify articles created
4. Check price history

### Phase 4: Role Adjustment Testing

1. Create default FORWARDER adjustment: -€50
2. Create unit-specific adjustment: FORWARDER + 20ft DV = -€100
3. Generate quote for FORWARDER customer
4. Verify:
   - 20ft DV uses -€100 (unit-specific override)
   - CBM uses -€50 (default)

### Phase 5: Price Update Testing

1. Update carrier price in Robaws (€1,200 → €1,250)
2. Webhook triggers or scheduled sync runs
3. Verify:
   - Old price archived (is_current=false)
   - New price created (is_current=true)
   - Article sale_price updated
   - New price pushed to Robaws

---

## 📦 Implementation Checklist

### Database (8 migrations)
- [ ] Create `robaws_customers_cache` table
- [ ] Create `unit_types` table
- [ ] Create `carriers` table
- [ ] Create `carrier_prices` table (with history support)
- [ ] Create `customer_role_adjustments` table
- [ ] Update `robaws_articles_cache` table (add carrier/pricing fields)
- [ ] Run all migrations
- [ ] Seed `unit_types` (29 types)

### Models (6 models)
- [ ] Create `RobawsCustomerCache` model
- [ ] Create `UnitType` model
- [ ] Create `Carrier` model
- [ ] Create `CarrierPrice` model
- [ ] Create `CustomerRoleAdjustment` model
- [ ] Update `RobawsArticleCache` model (add relationships)

### Services (4 services)
- [ ] Create `RobawsCustomerSyncService` (bi-directional)
- [ ] Create `RobawsCarrierSyncService`
- [ ] Create `RobawsCarrierPriceSyncService` (bi-directional, with auto-article creation)
- [ ] Create `CustomerPricingService` (quote generation)

### Commands (5 commands)
- [ ] Create `SyncRobawsCustomers` command
- [ ] Create `SyncRobawsCarriers` command
- [ ] Create `SyncRobawsCarrierPrices` command
- [ ] Create `ImportCarrierPricesFromCsv` command
- [ ] Create `RegisterCustomerWebhooks` command

### Webhooks (2 endpoints)
- [ ] Add `POST /webhooks/robaws/customers` route
- [ ] Add `handleCustomer()` method to `RobawsWebhookController`
- [ ] Add `POST /webhooks/robaws/carrier-prices` route (if supported)
- [ ] Add `handleCarrierPrice()` method to `RobawsWebhookController`
- [ ] Register webhooks with Robaws

### Filament Resources (6 resources)
- [ ] Create `RobawsCustomerResource` (with sync/push buttons)
- [ ] Create `CarrierResource`
- [ ] Create `CarrierPriceResource` (main pricing page)
- [ ] Create `UnitTypeResource`
- [ ] Create `CustomerRoleAdjustmentResource`
- [ ] Update `RobawsArticleResource` (add carrier/pricing columns)

### Integration
- [ ] Update `Intake` model (add customer relationship)
- [ ] Update intake Filament form (customer selector)
- [ ] Create quote generation UI (future)

### Scheduling
- [ ] Add customer sync schedule (daily + weekly)
- [ ] Add carrier sync schedule (daily)
- [ ] Add carrier price sync schedule (daily + weekly)
- [ ] Add push schedules (evening)

### Testing & Deployment
- [ ] Test customer sync (import 4,017)
- [ ] Test carrier price import
- [ ] Test article auto-creation
- [ ] Test bulk CSV import
- [ ] Test price history
- [ ] Test role adjustments (default + unit-specific)
- [ ] Test quote generation
- [ ] Test bi-directional push to Robaws
- [ ] Deploy to production
- [ ] Register webhooks on production

---

## 🎯 Success Criteria

✅ **Customer Sync**:
- 4,017 customers imported from Robaws
- Roles correctly extracted (Aeon = FORWARDER)
- Bi-directional sync working (Robaws ↔ Bconnect)
- Webhooks processing customer updates

✅ **Carrier Pricing**:
- Carrier prices imported from Robaws
- Articles auto-created with calculated sale prices
- Price history maintained with validity dates
- Bulk CSV import working

✅ **Role Adjustments**:
- Default role-wide adjustments configured
- Unit-specific overrides working
- Adjustment priority logic correct

✅ **Quote Generation**:
- Final prices calculated correctly:
  `carrier_price + margin + role_adjustment = quote_price`
- Full audit trail with all price components

✅ **Bi-Directional Sync**:
- Article sale prices pushed back to Robaws
- Customer updates synced both ways
- Webhooks + scheduled sync both working
- No sync conflicts or data loss

---

## 🚀 Future Enhancements

1. **Quote Export to Robaws** - Generate quotes and push to Robaws
2. **Price Approval Workflow** - Require approval before price changes
3. **Margin Analytics** - Dashboard showing margin trends
4. **Customer-Specific Pricing** - Override role adjustments per customer
5. **Competitive Pricing** - Compare carrier prices across carriers
6. **Price Alerts** - Notify when carrier prices change significantly

---

**Ready to implement this complete system?**

