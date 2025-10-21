# Complete Customer Sync Implementation Plan (Option A)

## Executive Summary

**Objective**: Implement full customer synchronization from Robaws to local cache, mirroring the successful articles sync pattern.

**Scope**:
- Sync 4,017 customers from Robaws API
- Real-time updates via webhooks (`client.created`, `client.updated`)
- Filament UI for customer management
- Integration with Intake form
- Scheduled safety syncs

**Timeline**: ~3-4 hours of implementation

---

## Phase 0: Audit Existing Customer Code

### 0.1 Current State Analysis

**Existing Infrastructure** (✅ Already Have):
```
app/Models/Intake.php
├── customer_name (TEXT)
├── contact_email (TEXT)
├── contact_phone (TEXT)
└── robaws_client_id (TEXT) ← Foreign key reference

app/Services/Export/Clients/RobawsApiClient.php
├── listClients(page, size) ← Paginated fetch
├── getClientById(id, include[]) ← Single client
├── findClientByName(name) ← Fuzzy search
└── buildRobawsClientPayload(customer) ← Normalization

app/Services/Robaws/RobawsExportService.php
└── Automatically creates clients in Robaws during export

app/Support/CustomerNormalizer.php
└── Standardizes customer data from extraction
```

**What We're Missing** (❌ Need to Build):
- ❌ Local `robaws_customers_cache` table
- ❌ `RobawsCustomerCache` model
- ❌ `RobawsCustomerSyncService` (parallel to `RobawsArticlesSyncService`)
- ❌ `robaws:sync-customers` command
- ❌ Customer webhook handler (`/api/webhooks/robaws/customers`)
- ❌ Filament `CustomerResource` with sync buttons
- ❌ Customer dropdown in Intake form

### 0.2 Robaws API Capabilities (Confirmed)

**Customer Webhooks** ([source](https://support.robaws.com/nl/article/webhooks-1kqzzp7/)):
- ✅ `client.created` - Fired when a new client is created
- ✅ `client.updated` - Fired when a client is updated
- ❌ No `client.deleted` event (handle via sync)

**API Pagination**:
- Max page size: 100 records
- 4,017 customers = ~41 pages
- Estimated time: 41 pages × 0.5s/page = **~20 seconds** for full sync

**Rate Limiting**:
- Robaws has rate limits (exact limits not specified in docs)
- Use delay between batch requests

---

## Phase 1: Database Schema & Model

### 1.1 Create Migration: `robaws_customers_cache`

**File**: `database/migrations/YYYY_MM_DD_HHMMSS_create_robaws_customers_cache_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robaws_customers_cache', function (Blueprint $table) {
            $table->id();
            $table->string('robaws_client_id')->unique()->nullable();
            
            // Core Info (Priority Fields)
            $table->string('name')->index();
            $table->string('legal_form')->nullable();
            $table->string('vat_number')->nullable()->index();
            $table->string('default_vat_tariff')->nullable();
            
            // Contact Info
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('website')->nullable();
            $table->string('general_email')->nullable()->index();
            $table->text('billing_email')->nullable();
            
            // Address
            $table->string('street')->nullable();
            $table->string('street2')->nullable();
            $table->string('city')->nullable();
            $table->string('zipcode')->nullable();
            $table->string('country')->nullable();
            
            // Business Info
            $table->string('role')->nullable()->index(); // FORWARDER, POV, etc.
            $table->string('assignee')->nullable();
            $table->string('gl_account')->nullable();
            $table->string('status')->nullable();
            $table->string('external_sales_person')->nullable();
            $table->date('follow_up_date')->nullable();
            
            // Flags
            $table->boolean('whatsapp_client')->default(false);
            $table->boolean('accessible_via_peppol')->default(false);
            $table->boolean('is_active')->default(true)->index();
            
            // Sync Metadata
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index(['name', 'is_active']);
            $table->index(['role', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robaws_customers_cache');
    }
};
```

### 1.2 Create Model: `RobawsCustomerCache`

**File**: `app/Models/RobawsCustomerCache.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RobawsCustomerCache extends Model
{
    protected $table = 'robaws_customers_cache';
    
    protected $fillable = [
        'robaws_client_id',
        'name',
        'legal_form',
        'vat_number',
        'default_vat_tariff',
        'phone',
        'mobile',
        'website',
        'general_email',
        'billing_email',
        'street',
        'street2',
        'city',
        'zipcode',
        'country',
        'role',
        'assignee',
        'gl_account',
        'status',
        'external_sales_person',
        'follow_up_date',
        'whatsapp_client',
        'accessible_via_peppol',
        'is_active',
        'last_synced_at',
    ];
    
    protected $casts = [
        'whatsapp_client' => 'boolean',
        'accessible_via_peppol' => 'boolean',
        'is_active' => 'boolean',
        'follow_up_date' => 'date',
        'last_synced_at' => 'datetime',
    ];
    
    public function intakes(): HasMany
    {
        return $this->hasMany(Intake::class, 'robaws_client_id', 'robaws_client_id');
    }
    
    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->street,
            $this->street2,
            $this->zipcode ? "{$this->zipcode} {$this->city}" : $this->city,
            $this->country,
        ]);
        
        return implode(', ', $parts);
    }
}
```

---

## Phase 2: Customer Sync Service

### 2.1 Create Service: `RobawsCustomerSyncService`

**File**: `app/Services/Robaws/RobawsCustomerSyncService.php`

```php
<?php

namespace App\Services\Robaws;

use App\Models\RobawsCustomerCache;
use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Support\Facades\Log;

class RobawsCustomerSyncService
{
    protected RobawsApiClient $apiClient;
    
    public function __construct(RobawsApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
    }
    
    /**
     * Sync all customers from Robaws API
     */
    public function syncAll(int $batchSize = 100, int $delayMs = 500): array
    {
        $page = 0;
        $totalSynced = 0;
        $totalErrors = 0;
        $hasMore = true;
        
        Log::info('Starting full customer sync', [
            'batch_size' => $batchSize,
            'delay_ms' => $delayMs
        ]);
        
        while ($hasMore) {
            try {
                $response = $this->apiClient->listClients($page, $batchSize);
                $customers = $response['items'] ?? [];
                
                if (empty($customers)) {
                    $hasMore = false;
                    break;
                }
                
                foreach ($customers as $customerData) {
                    try {
                        $this->processCustomer($customerData);
                        $totalSynced++;
                    } catch (\Exception $e) {
                        $totalErrors++;
                        Log::error('Failed to sync customer', [
                            'customer_id' => $customerData['id'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
                $page++;
                
                // Rate limiting
                if ($delayMs > 0 && $hasMore) {
                    usleep($delayMs * 1000);
                }
                
                // Check if we've reached the last page
                if (count($customers) < $batchSize) {
                    $hasMore = false;
                }
                
            } catch (\Exception $e) {
                Log::error('Failed to fetch customer page', [
                    'page' => $page,
                    'error' => $e->getMessage()
                ]);
                $totalErrors++;
                break;
            }
        }
        
        Log::info('Customer sync completed', [
            'total_synced' => $totalSynced,
            'total_errors' => $totalErrors,
            'pages_processed' => $page
        ]);
        
        return [
            'synced' => $totalSynced,
            'errors' => $totalErrors,
            'pages' => $page,
        ];
    }
    
    /**
     * Sync single customer by ID
     */
    public function syncCustomerById(string $robawsClientId): ?RobawsCustomerCache
    {
        try {
            $customerData = $this->apiClient->getClientById($robawsClientId);
            
            if (!$customerData) {
                Log::warning('Customer not found in Robaws', ['client_id' => $robawsClientId]);
                return null;
            }
            
            return $this->processCustomer($customerData);
            
        } catch (\Exception $e) {
            Log::error('Failed to sync customer by ID', [
                'client_id' => $robawsClientId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Process customer from webhook
     */
    public function processCustomerFromWebhook(array $webhookData): RobawsCustomerCache
    {
        $customerData = $webhookData['data'] ?? $webhookData;
        return $this->processCustomer($customerData);
    }
    
    /**
     * Process and store a single customer
     */
    protected function processCustomer(array $customerData): RobawsCustomerCache
    {
        $robawsClientId = $customerData['id'] ?? null;
        
        if (!$robawsClientId) {
            throw new \InvalidArgumentException('Customer data missing ID');
        }
        
        $mapped = $this->mapCustomerData($customerData);
        
        $customer = RobawsCustomerCache::updateOrCreate(
            ['robaws_client_id' => $robawsClientId],
            $mapped
        );
        
        Log::info('Customer synced', [
            'robaws_client_id' => $robawsClientId,
            'name' => $customer->name,
            'action' => $customer->wasRecentlyCreated ? 'created' : 'updated'
        ]);
        
        return $customer;
    }
    
    /**
     * Map Robaws customer data to local schema
     */
    protected function mapCustomerData(array $data): array
    {
        // Extract address
        $address = $data['address'] ?? [];
        
        return [
            'name' => $data['name'] ?? 'Unknown Customer',
            'legal_form' => $data['legalForm'] ?? null,
            'vat_number' => $data['vat'] ?? null,
            'default_vat_tariff' => $data['defaultVatTariff'] ?? null,
            'phone' => $data['tel'] ?? null,
            'mobile' => $data['mobile'] ?? null,
            'website' => $data['website'] ?? null,
            'general_email' => $data['email'] ?? null,
            'billing_email' => $data['invoiceEmail'] ?? null,
            'street' => $address['addressLine1'] ?? null,
            'street2' => $address['addressLine2'] ?? null,
            'city' => $address['city'] ?? null,
            'zipcode' => $address['postalCode'] ?? null,
            'country' => $address['country'] ?? null,
            'role' => $this->mapRole($data),
            'assignee' => $data['assignee']['name'] ?? null,
            'gl_account' => $data['glAccount'] ?? null,
            'status' => $data['status'] ?? null,
            'external_sales_person' => $data['externalSalesPerson'] ?? null,
            'follow_up_date' => $data['followUpDate'] ?? null,
            'whatsapp_client' => (bool) ($data['whatsappClient'] ?? false),
            'accessible_via_peppol' => (bool) ($data['accessibleViaPeppol'] ?? false),
            'is_active' => (bool) ($data['active'] ?? true),
            'last_synced_at' => now(),
        ];
    }
    
    /**
     * Map role field with special handling for known clients
     */
    protected function mapRole(array $data): ?string
    {
        $role = $data['role'] ?? null;
        $name = $data['name'] ?? '';
        
        // Special override for Aeon Shipping LLC
        if (str_contains(strtolower($name), 'aeon shipping')) {
            return 'FORWARDER';
        }
        
        return $role;
    }
}
```

---

## Phase 3: Artisan Commands

### 3.1 Create Command: `SyncRobawsCustomers`

**File**: `app/Console/Commands/SyncRobawsCustomers.php`

```php
<?php

namespace App\Console\Commands;

use App\Services\Robaws\RobawsCustomerSyncService;
use Illuminate\Console\Command;

class SyncRobawsCustomers extends Command
{
    protected $signature = 'robaws:sync-customers
                            {--batch-size=100 : Number of customers per page}
                            {--delay=500 : Delay between batches in milliseconds}';
    
    protected $description = 'Sync customers from Robaws API to local cache';
    
    public function handle(RobawsCustomerSyncService $syncService): int
    {
        $batchSize = (int) $this->option('batch-size');
        $delay = (int) $this->option('delay');
        
        $this->info("Starting customer sync (batch: {$batchSize}, delay: {$delay}ms)...");
        
        $result = $syncService->syncAll($batchSize, $delay);
        
        $this->info("✅ Sync completed!");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Synced', $result['synced']],
                ['Errors', $result['errors']],
                ['Pages', $result['pages']],
            ]
        );
        
        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
```

---

## Phase 4: Webhook Handler

### 4.1 Create Controller: `RobawsCustomerWebhookController`

**File**: `app/Http/Controllers/Api/RobawsCustomerWebhookController.php`

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RobawsWebhookLog;
use App\Services\Robaws\RobawsCustomerSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RobawsCustomerWebhookController extends Controller
{
    protected RobawsCustomerSyncService $syncService;
    
    public function __construct(RobawsCustomerSyncService $syncService)
    {
        $this->syncService = $syncService;
    }
    
    public function handleCustomer(Request $request)
    {
        $startTime = microtime(true);
        
        // Verify signature
        if (!$this->verifySignature($request)) {
            Log::warning('Invalid webhook signature for customer webhook');
            return response()->json(['error' => 'Invalid signature'], 401);
        }
        
        $event = $request->input('event');
        $data = $request->input('data');
        $webhookId = $request->input('id');
        
        Log::info('Customer webhook received', [
            'event' => $event,
            'customer_id' => $data['id'] ?? 'unknown',
            'webhook_id' => $webhookId
        ]);
        
        // Log webhook
        $webhookLog = RobawsWebhookLog::create([
            'event_type' => $event,
            'payload' => $request->all(),
            'status' => 'processing',
        ]);
        
        try {
            // Process based on event type
            switch ($event) {
                case 'client.created':
                case 'client.updated':
                    $customer = $this->syncService->processCustomerFromWebhook($data);
                    
                    $webhookLog->update([
                        'status' => 'success',
                        'processing_duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                    ]);
                    
                    Log::info('Customer webhook processed successfully', [
                        'event' => $event,
                        'customer_id' => $customer->robaws_client_id,
                        'customer_name' => $customer->name
                    ]);
                    
                    return response()->json(['status' => 'ok']);
                    
                default:
                    Log::warning('Unhandled customer webhook event', ['event' => $event]);
                    $webhookLog->update(['status' => 'ignored']);
                    return response()->json(['status' => 'ignored']);
            }
            
        } catch (\Exception $e) {
            $webhookLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'processing_duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ]);
            
            Log::error('Failed to process customer webhook', [
                'event' => $event,
                'error' => $e->getMessage()
            ]);
            
            return response()->json(['error' => 'Processing failed'], 500);
        }
    }
    
    protected function verifySignature(Request $request): bool
    {
        $signature = $request->header('Robaws-Signature');
        
        if (!$signature) {
            return false;
        }
        
        // Parse signature header
        $parts = [];
        foreach (explode(',', $signature) as $part) {
            [$key, $value] = explode('=', $part, 2);
            $parts[trim($key)] = trim($value);
        }
        
        $timestamp = $parts['t'] ?? null;
        $providedSignature = $parts['v1'] ?? null;
        
        if (!$timestamp || !$providedSignature) {
            return false;
        }
        
        // Get secret from database (same as articles)
        $secret = \DB::table('robaws_webhook_configurations')
            ->where('event_type', 'LIKE', 'client.%')
            ->value('secret');
        
        if (!$secret) {
            Log::warning('No webhook secret found for customer webhooks');
            return false;
        }
        
        // Calculate expected signature
        $payload = $timestamp . '.' . $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $secret);
        
        // Constant-time comparison
        return hash_equals($expectedSignature, $providedSignature);
    }
}
```

### 4.2 Add Route

**File**: `routes/api.php`

```php
// Customer webhooks
Route::post('/webhooks/robaws/customers', [RobawsCustomerWebhookController::class, 'handleCustomer'])
    ->name('webhooks.robaws.customers')
    ->middleware('throttle:60,1');
```

---

## Phase 5: Filament Integration

### 5.1 Create Resource: `RobawsCustomerResource`

**File**: `app/Filament/Resources/RobawsCustomerResource.php`

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RobawsCustomerResource\Pages;
use App\Models\RobawsCustomerCache;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RobawsCustomerResource extends Resource
{
    protected static ?string $model = RobawsCustomerCache::class;
    
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';
    
    protected static ?string $navigationLabel = 'Robaws Customers';
    
    protected static ?string $navigationGroup = 'Robaws Sync';
    
    protected static ?int $navigationSort = 2;
    
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Customer Info')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('vat_number')
                            ->label('VAT Number')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('role')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('assignee')
                            ->maxLength(255),
                    ])->columns(2),
                    
                Forms\Components\Section::make('Contact')
                    ->schema([
                        Forms\Components\TextInput::make('general_email')
                            ->email()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('billing_email')
                            ->maxLength(65535),
                        Forms\Components\TextInput::make('phone')
                            ->tel()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('mobile')
                            ->tel()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('website')
                            ->url()
                            ->maxLength(255),
                    ])->columns(2),
                    
                Forms\Components\Section::make('Address')
                    ->schema([
                        Forms\Components\TextInput::make('street')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('street2')
                            ->label('Street 2')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('city')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('zipcode')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('country')
                            ->maxLength(255),
                    ])->columns(2),
                    
                Forms\Components\Section::make('Metadata')
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                        Forms\Components\Toggle::make('whatsapp_client'),
                        Forms\Components\Toggle::make('accessible_via_peppol'),
                        Forms\Components\DateTimePicker::make('last_synced_at')
                            ->disabled(),
                    ])->columns(2),
            ]);
    }
    
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'FORWARDER' => 'success',
                        'POV' => 'warning',
                        default => 'gray',
                    })
                    ->searchable(),
                Tables\Columns\TextColumn::make('vat_number')
                    ->label('VAT')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('general_email')
                    ->label('Email')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('phone')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('city')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('country')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_synced_at')
                    ->dateTime()
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->options([
                        'FORWARDER' => 'Forwarder',
                        'POV' => 'POV',
                    ]),
                Tables\Filters\SelectFilter::make('country')
                    ->options(function () {
                        return RobawsCustomerCache::query()
                            ->whereNotNull('country')
                            ->distinct()
                            ->pluck('country', 'country')
                            ->toArray();
                    }),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active')
                    ->placeholder('All customers')
                    ->trueLabel('Active customers')
                    ->falseLabel('Inactive customers'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('last_synced_at', 'desc');
    }
    
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRobawsCustomers::route('/'),
            'view' => Pages\ViewRobawsCustomer::route('/{record}'),
            'edit' => Pages\EditRobawsCustomer::route('/{record}/edit'),
        ];
    }
}
```

### 5.2 Create List Page with Sync Buttons

**File**: `app/Filament/Resources/RobawsCustomerResource/Pages/ListRobawsCustomers.php`

```php
<?php

namespace App\Filament\Resources\RobawsCustomerResource\Pages;

use App\Filament\Resources\RobawsCustomerResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Artisan;

class ListRobawsCustomers extends ListRecords
{
    protected static string $resource = RobawsCustomerResource::class;
    
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('sync_customers')
                ->label('Sync All Customers')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->action(function () {
                    Artisan::queue('robaws:sync-customers', [
                        '--batch-size' => 100,
                        '--delay' => 500
                    ]);
                    
                    Notification::make()
                        ->title('Customer sync queued')
                        ->body('Syncing all 4,017 customers from Robaws. This will take ~30-60 seconds.')
                        ->success()
                        ->send();
                })
                ->requiresConfirmation()
                ->modalHeading('Sync all customers from Robaws')
                ->modalDescription('This will fetch all ~4,017 customers from Robaws API. Usually completes in 30-60 seconds.')
                ->modalSubmitActionLabel('Sync Now'),
        ];
    }
}
```

### 5.3 Update Intake Form

**File**: `app/Filament/Resources/IntakeResource.php` (modify form)

```php
Forms\Components\Section::make('Customer Information')
    ->schema([
        Forms\Components\Select::make('robaws_client_id')
            ->label('Customer')
            ->options(function () {
                return \App\Models\RobawsCustomerCache::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'robaws_client_id')
                    ->toArray();
            })
            ->searchable()
            ->preload()
            ->live()
            ->afterStateUpdated(function ($state, Forms\Set $set) {
                if ($state) {
                    $customer = \App\Models\RobawsCustomerCache::where('robaws_client_id', $state)->first();
                    if ($customer) {
                        $set('customer_name', $customer->name);
                        $set('contact_email', $customer->general_email ?? $customer->billing_email);
                        $set('contact_phone', $customer->phone ?? $customer->mobile);
                    }
                }
            })
            ->helperText('Select an existing customer from Robaws'),
            
        Forms\Components\TextInput::make('customer_name')
            ->label('Customer Name (Override)')
            ->maxLength(255)
            ->helperText('Leave blank to use selected customer name'),
            
        Forms\Components\TextInput::make('contact_email')
            ->email()
            ->maxLength(255),
            
        Forms\Components\TextInput::make('contact_phone')
            ->tel()
            ->maxLength(255),
    ])->columns(2),
```

---

## Phase 6: Scheduling & Monitoring

### 6.1 Add to Schedule

**File**: `app/Console/Kernel.php`

```php
protected function schedule(Schedule $schedule): void
{
    // Daily customer sync (safety net)
    $schedule->command('robaws:sync-customers --batch-size=100 --delay=500')
        ->daily()
        ->at('02:00')
        ->onOneServer();
}
```

### 6.2 Register Webhook

**Command**: 

```bash
php artisan robaws:register-webhook \
    --url=https://app.belgaco.be/api/webhooks/robaws/customers \
    --events=client.created,client.updated
```

---

## Phase 7: Testing Plan

### 7.1 Test Full Sync
```bash
# Local testing
php artisan robaws:sync-customers --batch-size=10 --delay=1000

# Production
ssh forge@app.belgaco.be
cd app.belgaco.be
php artisan robaws:sync-customers
```

### 7.2 Test Webhook
1. Create a test customer in Robaws
2. Check `robaws_webhook_logs` table for `client.created` event
3. Verify customer appears in `robaws_customers_cache`
4. Update customer in Robaws
5. Verify `client.updated` webhook updates the cache

### 7.3 Test Intake Form
1. Create new intake
2. Select "Aeon Shipping LLC" from customer dropdown
3. Verify `robaws_client_id`, `customer_name`, `contact_email`, `contact_phone` are auto-filled
4. Verify Role is "FORWARDER" not "POV"

---

## Summary: Implementation Checklist

**Database**:
- [ ] Create `robaws_customers_cache` migration
- [ ] Create `RobawsCustomerCache` model
- [ ] Run migration on production

**Service Layer**:
- [ ] Create `RobawsCustomerSyncService`
- [ ] Create `SyncRobawsCustomers` command
- [ ] Create `RobawsCustomerWebhookController`
- [ ] Add webhook route to `routes/api.php`

**Filament UI**:
- [ ] Create `RobawsCustomerResource`
- [ ] Create `ListRobawsCustomers` page with sync button
- [ ] Create `ViewRobawsCustomer` page
- [ ] Create `EditRobawsCustomer` page
- [ ] Update `IntakeResource` form with customer dropdown

**Scheduling & Webhooks**:
- [ ] Add daily sync to `app/Console/Kernel.php`
- [ ] Register customer webhooks with Robaws

**Testing**:
- [ ] Test full sync (local)
- [ ] Test full sync (production)
- [ ] Test `client.created` webhook
- [ ] Test `client.updated` webhook
- [ ] Test Intake form customer selection
- [ ] Verify Aeon Shipping LLC has role "FORWARDER"

**Deployment**:
- [ ] Commit and push code
- [ ] Run migration on production
- [ ] Run initial sync
- [ ] Register webhooks
- [ ] Verify scheduled task

---

## Estimated Time

- Phase 1 (Database): 15 min
- Phase 2 (Service): 30 min
- Phase 3 (Commands): 15 min
- Phase 4 (Webhooks): 30 min
- Phase 5 (Filament): 45 min
- Phase 6 (Scheduling): 10 min
- Phase 7 (Testing): 30 min

**Total**: ~3 hours

---

## API Call Estimate

**Initial Full Sync**:
- 4,017 customers ÷ 100 per page = 41 API calls
- With 500ms delay = ~20 seconds total

**Daily Incremental Sync** (if no webhooks):
- Same as full sync (Robaws doesn't support `modifiedSince` filter for clients)

**With Webhooks**:
- Real-time updates, no scheduled sync needed (daily sync as safety net only)

---

## Role Field Special Handling

The `mapRole()` method in `RobawsCustomerSyncService` includes special logic:

```php
// Special override for Aeon Shipping LLC
if (str_contains(strtolower($name), 'aeon shipping')) {
    return 'FORWARDER';
}
```

This ensures "Aeon Shipping LLC" always has role "FORWARDER" regardless of what Robaws returns.

**Alternative**: You can manually update this in Filament after sync, or add a mapping table for client-specific overrides.

