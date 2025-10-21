# Customer Sync Implementation Analysis

## Current State Review

### Existing Infrastructure

**What We Have:**
1. ✅ Robaws API client with customer/client methods (`RobawsApiClient`)
   - `listClients(page, size)` - Paginated client list
   - `getClientById(id)` - Fetch single client
   - `findClientByName(name)` - Search with fuzzy matching
   - `findOrCreateClient(clientData)` - Create new clients

2. ✅ Customer data already used in Intakes
   - `customer_name` (TEXT)
   - `contact_email` (TEXT)
   - `contact_phone` (TEXT)
   - `robaws_client_id` (TEXT) - Foreign key reference

3. ✅ Customer normalization service (`CustomerNormalizer`)
   - Standardizes customer data from extraction
   - Handles VAT, email, phone, website normalization

4. ✅ Client creation during export (`RobawsExportService`)
   - Automatically finds or creates clients in Robaws when exporting intakes

### Current Gaps

❌ **No local customer cache table**
- Currently, we search Robaws API every time we need customer data
- No persistent storage of Robaws customer records
- No customer dropdown in Intake form

❌ **No customer sync command**
- Can't bulk-sync customers from Robaws to local DB

❌ **No customer webhooks**
- Can't receive real-time updates when customers change in Robaws

❌ **No CustomerResource in Filament**
- Can't view/manage customers in admin panel

---

## Proposed Implementation: Option A (Recommended)

### Full Customer Sync with Webhooks (Mirror Articles Pattern)

**What We Build:**

1. **Database Table: `robaws_customers_cache`**
   ```sql
   CREATE TABLE robaws_customers_cache (
       id BIGSERIAL PRIMARY KEY,
       robaws_client_id TEXT UNIQUE NOT NULL,
       name TEXT NOT NULL,
       email TEXT NULLABLE,
       phone TEXT NULLABLE,
       vat_number TEXT NULLABLE,
       
       -- Address
       address_line1 TEXT NULLABLE,
       address_line2 TEXT NULLABLE,
       city TEXT NULLABLE,
       postal_code TEXT NULLABLE,
       country TEXT DEFAULT 'BE',
       
       -- Metadata
       is_active BOOLEAN DEFAULT TRUE,
       last_synced_at TIMESTAMP,
       created_at TIMESTAMP,
       updated_at TIMESTAMP,
       
       INDEX(name),
       INDEX(email),
       INDEX(is_active)
   );
   ```

2. **Model: `RobawsCustomerCache`**
   ```php
   class RobawsCustomerCache extends Model
   {
       protected $table = 'robaws_customers_cache';
       
       protected $fillable = [
           'robaws_client_id', 'name', 'email', 'phone', 
           'vat_number', 'address_line1', 'address_line2',
           'city', 'postal_code', 'country', 'is_active',
           'last_synced_at'
       ];
       
       protected $casts = [
           'is_active' => 'boolean',
           'last_synced_at' => 'datetime',
       ];
       
       public function intakes()
       {
           return $this->hasMany(Intake::class, 'robaws_client_id', 'robaws_client_id');
       }
   }
   ```

3. **Service: `RobawsCustomerSyncService`**
   ```php
   class RobawsCustomerSyncService
   {
       public function sync(): array
       {
           // Paginated sync of all customers from Robaws
       }
       
       public function syncIncremental(): array
       {
           // Sync only changed customers (if Robaws supports modified filter)
       }
       
       public function processCustomerFromWebhook(array $customerData): void
       {
           // Handle webhook updates
       }
   }
   ```

4. **Command: `robaws:sync-customers`**
   ```bash
   php artisan robaws:sync-customers
   php artisan robaws:sync-customers --full
   ```

5. **Webhook Handler**
   - Endpoint: `POST /api/webhooks/robaws/clients`
   - Events: `client.created`, `client.updated`, `client.deleted`
   - Signature verification (HMAC-SHA256)

6. **Filament Resource: `CustomerResource`**
   - List/view/edit customers
   - Header actions: "Sync Customers", "Full Sync"
   - Search by name, email, VAT

7. **Update Intake Form**
   ```php
   Forms\Components\Select::make('robaws_client_id')
       ->label('Customer')
       ->options(RobawsCustomerCache::where('is_active', true)
           ->orderBy('name')
           ->pluck('name', 'robaws_client_id'))
       ->searchable()
       ->preload()
       ->createOptionForm([
           Forms\Components\TextInput::make('name')->required(),
           Forms\Components\TextInput::make('email')->email(),
           Forms\Components\TextInput::make('phone'),
       ])
       ->createOptionUsing(function (array $data) {
           // Create in Robaws + cache locally
       })
   ```

### Pros ✅
- **Consistent architecture** - Same pattern as articles
- **Fast lookups** - No API calls needed for dropdown
- **Real-time updates** - Webhooks keep data fresh
- **Offline resilience** - Works even if Robaws API is down
- **Search/filter** - Can search customers in Filament
- **Data ownership** - Full control over customer data

### Cons ⚠️
- **More database storage** - Another cache table
- **Webhook complexity** - Need to register and handle webhooks
- **Initial sync time** - Need to sync all customers first (but only once)

---

## Alternative: Option B (Minimal)

### On-Demand API Lookups (No Cache)

**What We Build:**

1. **Update Intake Form Only**
   ```php
   Forms\Components\Select::make('robaws_client_id')
       ->label('Customer')
       ->searchable()
       ->getSearchResultsUsing(fn (string $search) => 
           app(RobawsApiClient::class)->findClientByName($search)
       )
       ->getOptionLabelUsing(fn ($value) => 
           app(RobawsApiClient::class)->getClientById($value)['name']
       )
   ```

2. **No database table**
3. **No sync command**
4. **No webhooks**

### Pros ✅
- **Minimal code** - Just update the form
- **Always current** - Reads directly from Robaws
- **No sync needed** - No cache to maintain

### Cons ⚠️
- **Slow** - Every search hits Robaws API
- **API quota usage** - Burns through daily quota
- **No offline mode** - Breaks if Robaws is down
- **No bulk operations** - Can't filter/report on customers
- **Bad UX** - Slow dropdown, no preload

---

## Recommendation: **Option A** ✅

### Why Option A is Better

1. **Consistency**: Same proven pattern as articles (which works well)
2. **Performance**: Instant dropdowns vs slow API calls
3. **UX**: Better user experience with fast search
4. **Scalability**: Won't hit API rate limits
5. **Future-proof**: Can add customer analytics, reporting, filtering

### Implementation Phases

**Phase 1: Basic Sync (30 min)**
- Create migration for `robaws_customers_cache` table
- Create `RobawsCustomerCache` model
- Create `RobawsCustomerSyncService` (sync logic)
- Create `robaws:sync-customers` command
- Run initial sync

**Phase 2: Intake Integration (15 min)**
- Update Intake migration (keep existing fields, no breaking changes)
- Update IntakeResource form with customer dropdown
- Test customer selection

**Phase 3: Admin UI (20 min)**
- Create `CustomerResource` in Filament
- Add "Sync Customers" header actions
- Add search/filters

**Phase 4: Webhooks (30 min)**
- Create webhook controller for `client.*` events
- Register webhook with Robaws
- Test real-time updates

**Phase 5: Schedule (5 min)**
- Add daily safety sync to `routes/console.php`

**Total: ~2 hours**

---

## Questions to Clarify

1. **Webhook Support**: Does Robaws support `client.created`, `client.updated` webhooks?
   - 📝 **Action**: Check Robaws API docs or ask support
   - 🔄 **Fallback**: Daily scheduled sync if no webhooks

2. **Customer Volume**: How many customers do you have in Robaws?
   - 📊 This affects initial sync time
   - 💡 If < 500: sync instantly
   - 💡 If 1000-5000: takes 5-10 minutes
   - 💡 If > 5000: need batch processing

3. **Customer Fields**: Do you need any extra Robaws customer fields?
   - Company registration number?
   - Industry/sector?
   - Account manager?
   - Credit limit?

4. **Two-way Sync**: Should we allow creating customers from Intake form?
   - ✅ **Yes** (recommended): Add "Create New Customer" button
   - ❌ **No**: Only select existing customers

---

## Final Architecture (Option A)

```
┌─────────────────────────────────────────────────────────────┐
│                        Robaws API                            │
│  /api/v2/clients (GET, POST, PUT, DELETE)                   │
└──────────────────┬──────────────────────────────────────────┘
                   │
                   ├─────────────┐
                   │             │
            Webhook Events    API Calls
            (real-time)      (on-demand)
                   │             │
                   ▼             ▼
         ┌──────────────────────────────┐
         │  RobawsCustomerSyncService   │
         │  - sync()                    │
         │  - syncIncremental()         │
         │  - processFromWebhook()      │
         └──────────────┬───────────────┘
                        │
                        ▼
         ┌──────────────────────────────┐
         │  robaws_customers_cache      │
         │  (PostgreSQL Table)          │
         └──────────────┬───────────────┘
                        │
          ┌─────────────┼─────────────┐
          │             │             │
          ▼             ▼             ▼
    IntakeResource  CustomerResource  Reports
    (dropdown)      (admin CRUD)    (analytics)
```

---

## Decision

**Proceed with Option A?**
- [x] Yes - Full sync with webhooks (recommended)
- [ ] No - Use Option B (minimal, API lookups only)
- [ ] Custom - Let's discuss modifications

**Next Steps if "Yes":**
1. Confirm customer count in Robaws
2. Check webhook support (`client.*` events)
3. Begin Phase 1 implementation

