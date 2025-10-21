# Customer Sync Implementation Plan (FINAL - REVIEWED)

## 🎯 Overview

Implement bi-directional customer synchronization from Robaws (4,017 customers) with webhooks, using the proven article sync pattern. Defer pricing system until composite items are understood.

**Key Requirement**: Role is a **custom field** in Robaws, similar to how "Parent Item" works for articles.

---

## Phase 1: Database Schema

### Migration: `create_robaws_customers_cache_table.php`

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
            $table->string('robaws_client_id')->unique();
            $table->string('name')->index();
            $table->string('role')->nullable()->index(); // FORWARDER, POV, BROKER, etc. (from custom field)
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->text('address')->nullable();
            $table->string('street')->nullable();
            $table->string('street_number')->nullable();
            $table->string('city')->nullable()->index();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable()->index();
            $table->string('country_code', 2)->nullable();
            $table->string('vat_number')->nullable()->index();
            $table->string('website')->nullable();
            $table->string('language', 10)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->string('client_type')->nullable(); // company, individual
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable(); // Store full Robaws data
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_pushed_to_robaws_at')->nullable();
            $table->timestamps();
            
            $table->index(['role', 'is_active']);
            $table->index(['last_synced_at']);
            $table->index(['name', 'email']); // For duplicate detection
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robaws_customers_cache');
    }
};
```

---

## Phase 2: Model

### `app/Models/RobawsCustomerCache.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RobawsCustomerCache extends Model
{
    protected $fillable = [
        'robaws_client_id',
        'name',
        'role',
        'email',
        'phone',
        'mobile',
        'address',
        'street',
        'street_number',
        'city',
        'postal_code',
        'country',
        'country_code',
        'vat_number',
        'website',
        'language',
        'currency',
        'client_type',
        'is_active',
        'metadata',
        'last_synced_at',
        'last_pushed_to_robaws_at',
    ];
    
    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
        'last_synced_at' => 'datetime',
        'last_pushed_to_robaws_at' => 'datetime',
    ];
    
    /**
     * Get all intakes for this customer
     */
    public function intakes(): HasMany
    {
        return $this->hasMany(Intake::class, 'robaws_client_id', 'robaws_client_id');
    }
    
    /**
     * Get role badge color for Filament
     */
    public function getRoleBadgeColor(): string
    {
        return match ($this->role) {
            'FORWARDER' => 'primary',
            'POV' => 'success',
            'BROKER' => 'warning',
            'SHIPPING LINE' => 'info',
            'CAR DEALER' => 'secondary',
            'LUXURY CAR DEALER' => 'success',
            'TOURIST' => 'danger',
            'BLACKLISTED' => 'danger',
            default => 'gray',
        };
    }
    
    /**
     * Scope for active customers
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    
    /**
     * Scope for specific role
     */
    public function scopeByRole($query, string $role)
    {
        return $query->where('role', $role);
    }
    
    /**
     * Get full name with role
     */
    public function getFullNameWithRoleAttribute(): string
    {
        return $this->name . ($this->role ? " ({$this->role})" : '');
    }
}
```

---

## Phase 3: Sync Service

### `app/Services/Robaws/RobawsCustomerSyncService.php`

```php
<?php

namespace App\Services\Robaws;

use App\Models\RobawsCustomerCache;
use App\Services\Export\Clients\RobawsApiClient;
use App\Support\CustomerNormalizer;
use Illuminate\Support\Facades\Log;

class RobawsCustomerSyncService
{
    protected RobawsApiClient $apiClient;
    protected CustomerNormalizer $normalizer;
    
    public function __construct(RobawsApiClient $apiClient, CustomerNormalizer $normalizer)
    {
        $this->apiClient = $apiClient;
        $this->normalizer = $normalizer;
    }
    
    /**
     * Sync all customers from Robaws
     */
    public function syncAllCustomers(bool $fullSync = false, bool $dryRun = false, ?int $limit = null): array
    {
        $stats = [
            'total_fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'sample_data' => [], // For dry-run inspection
        ];
        
        try {
            $page = 0;
            $size = 100; // Robaws max
            
            do {
                Log::info('Fetching customers page', ['page' => $page, 'size' => $size]);
                
                // Use existing RobawsApiClient::listClients method
                $response = $this->apiClient->listClients($page, $size);
                
                $customers = $response['items'] ?? [];
                $stats['total_fetched'] += count($customers);
                
                foreach ($customers as $customerData) {
                    try {
                        if ($dryRun) {
                            // Store first 10 samples for inspection
                            if (count($stats['sample_data']) < 10) {
                                $stats['sample_data'][] = [
                                    'id' => $customerData['id'] ?? 'unknown',
                                    'name' => $customerData['name'] ?? 'unknown',
                                    'structure' => $customerData, // Full structure
                                    'extracted_role' => $this->extractRole($customerData),
                                ];
                            }
                            $stats['skipped']++;
                        } else {
                            $customer = $this->processCustomer($customerData, $fullSync);
                            
                            if ($customer->wasRecentlyCreated) {
                                $stats['created']++;
                            } else {
                                $stats['updated']++;
                            }
                        }
                        
                    } catch (\Exception $e) {
                        Log::error('Failed to process customer', [
                            'customer_id' => $customerData['id'] ?? 'unknown',
                            'error' => $e->getMessage(),
                        ]);
                        $stats['errors']++;
                    }
                    
                    // Respect limit for testing
                    if ($limit && $stats['total_fetched'] >= $limit) {
                        break 2; // Break outer loop
                    }
                }
                
                $page++;
                $totalItems = (int)($response['totalItems'] ?? 0);
                
            } while (count($customers) === $size && $stats['total_fetched'] < $totalItems);
            
            Log::info('Customer sync completed', $stats);
            
        } catch (\Exception $e) {
            Log::error('Customer sync failed', ['error' => $e->getMessage()]);
            throw $e;
        }
        
        return $stats;
    }
    
    /**
     * Process individual customer data
     */
    public function processCustomer(array $customerData, bool $fullSync = false): RobawsCustomerCache
    {
        $clientId = $customerData['id'];
        
        // Normalize customer data using CustomerNormalizer
        $normalized = $this->normalizer->normalize($customerData);
        
        // Extract role from custom fields (like article's parent_item)
        $role = $this->extractRole($customerData);
        
        // Build normalized data array
        $normalizedData = [
            'robaws_client_id' => $clientId,
            'name' => $normalized['name'] ?? $customerData['name'] ?? 'Unknown',
            'role' => $role,
            'email' => $normalized['email'],
            'phone' => $normalized['phone'],
            'mobile' => $normalized['mobile'],
            'address' => $normalized['address']['street'] ?? $customerData['address'] ?? null,
            'street' => $customerData['street'] ?? $normalized['address']['street'] ?? null,
            'street_number' => $customerData['streetNumber'] ?? null,
            'city' => $normalized['address']['city'] ?? $customerData['city'] ?? null,
            'postal_code' => $normalized['address']['zip'] ?? $customerData['postalCode'] ?? null,
            'country' => $normalized['address']['country'] ?? $customerData['country'] ?? null,
            'country_code' => $customerData['countryCode'] ?? null,
            'vat_number' => $normalized['vat'] ?? $customerData['vatNumber'] ?? null,
            'website' => $normalized['website'] ?? $customerData['website'] ?? null,
            'language' => $customerData['language'] ?? null,
            'currency' => $customerData['currency'] ?? 'EUR',
            'client_type' => $normalized['client_type'] ?? $customerData['clientType'] ?? 'company',
            'is_active' => $customerData['isActive'] ?? $customerData['is_active'] ?? true,
            'metadata' => $customerData, // Store full Robaws data
            'last_synced_at' => now(),
        ];
        
        return RobawsCustomerCache::updateOrCreate(
            ['robaws_client_id' => $clientId],
            $normalizedData
        );
    }
    
    /**
     * Extract role from customer custom fields
     * Similar to how we extract "Parent Item" from article custom fields
     */
    protected function extractRole(array $customerData): ?string
    {
        // Try custom_fields first (API format)
        if (isset($customerData['custom_fields'])) {
            // Try direct key
            if (isset($customerData['custom_fields']['role'])) {
                return strtoupper(trim($customerData['custom_fields']['role']));
            }
            
            // Try searching through custom fields array
            foreach ($customerData['custom_fields'] as $key => $value) {
                // Check if field name contains 'role'
                if (is_array($value) && isset($value['name']) && stripos($value['name'], 'role') !== false) {
                    $roleValue = $value['value'] ?? $value['textValue'] ?? null;
                    if ($roleValue) {
                        return strtoupper(trim($roleValue));
                    }
                }
            }
        }
        
        // Try extraFields (webhook format - like article extraFields)
        if (isset($customerData['extraFields'])) {
            foreach ($customerData['extraFields'] as $fieldId => $fieldData) {
                // Check field name
                if (isset($fieldData['name']) && stripos($fieldData['name'], 'role') !== false) {
                    $roleValue = $fieldData['value'] ?? $fieldData['textValue'] ?? $fieldData['stringValue'] ?? null;
                    if ($roleValue) {
                        return strtoupper(trim($roleValue));
                    }
                }
                
                // Also check if there's a specific field ID for role (like parent_item has a GUID)
                // This can be discovered via --dry-run
            }
        }
        
        // Fallback: check clientType
        if (isset($customerData['clientType'])) {
            return strtoupper(trim($customerData['clientType']));
        }
        
        return null;
    }
    
    /**
     * Process customer from webhook
     */
    public function processCustomerFromWebhook(array $webhookData): RobawsCustomerCache
    {
        $customerData = $webhookData['data'] ?? $webhookData;
        
        return $this->processCustomer($customerData, false);
    }
    
    /**
     * Sync single customer by ID
     */
    public function syncSingleCustomer(string $clientId): RobawsCustomerCache
    {
        try {
            $customerData = $this->apiClient->getClientById($clientId);
            return $this->processCustomer($customerData, false);
        } catch (\Exception $e) {
            Log::error('Failed to sync single customer', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
    
    /**
     * Push customer updates back to Robaws (bi-directional sync)
     */
    public function pushCustomerToRobaws(RobawsCustomerCache $customer): bool
    {
        try {
            $updateData = array_filter([
                'name' => $customer->name,
                'email' => $customer->email,
                'tel' => $customer->phone,
                'gsm' => $customer->mobile,
                'vatNumber' => $customer->vat_number,
                'website' => $customer->website,
                'language' => $customer->language,
                'currency' => $customer->currency,
                'address' => array_filter([
                    'street' => $customer->street,
                    'streetNumber' => $customer->street_number,
                    'postalCode' => $customer->postal_code,
                    'city' => $customer->city,
                    'country' => $customer->country,
                    'countryCode' => $customer->country_code,
                ]),
            ], fn($v) => $v !== null && $v !== '' && $v !== []);
            
            $result = $this->apiClient->updateClient(
                (int)$customer->robaws_client_id,
                $updateData
            );
            
            if ($result) {
                $customer->update(['last_pushed_to_robaws_at' => now()]);
                
                Log::info('Pushed customer to Robaws', [
                    'customer_id' => $customer->id,
                    'robaws_client_id' => $customer->robaws_client_id,
                ]);
                
                return true;
            }
            
            return false;
            
        } catch (\Exception $e) {
            Log::error('Failed to push customer to Robaws', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    /**
     * Push all pending customer updates to Robaws
     */
    public function pushAllPendingUpdates(): array
    {
        $stats = ['pushed' => 0, 'failed' => 0];
        
        // Find customers updated locally but not yet pushed
        $customers = RobawsCustomerCache::where('updated_at', '>', 'last_pushed_to_robaws_at')
            ->orWhereNull('last_pushed_to_robaws_at')
            ->where('is_active', true)
            ->get();
        
        foreach ($customers as $customer) {
            if ($this->pushCustomerToRobaws($customer)) {
                $stats['pushed']++;
            } else {
                $stats['failed']++;
            }
        }
        
        return $stats;
    }
}
```

---

## Phase 4: Artisan Commands

### `app/Console/Commands/SyncRobawsCustomers.php`

```php
<?php

namespace App\Console\Commands;

use App\Services\Robaws\RobawsCustomerSyncService;
use Illuminate\Console\Command;

class SyncRobawsCustomers extends Command
{
    protected $signature = 'robaws:sync-customers 
                            {--full : Perform full sync instead of incremental}
                            {--push : Push local changes back to Robaws}
                            {--dry-run : Inspect customer data without saving}
                            {--limit= : Limit number of customers to sync (for testing)}
                            {--client-id= : Sync specific customer by ID}';
    
    protected $description = 'Sync customers from Robaws API (bi-directional)';
    
    public function handle(RobawsCustomerSyncService $syncService): int
    {
        // Handle push mode
        if ($this->option('push')) {
            $this->info('Pushing local customer changes to Robaws...');
            $stats = $syncService->pushAllPendingUpdates();
            
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Pushed', $stats['pushed']],
                    ['Failed', $stats['failed']],
                ]
            );
            
            $this->info('✅ Push completed');
            return Command::SUCCESS;
        }
        
        // Handle single customer sync
        if ($clientId = $this->option('client-id')) {
            $this->info("Syncing customer: {$clientId}");
            $customer = $syncService->syncSingleCustomer($clientId);
            $this->info("✅ Synced: {$customer->name} (Role: {$customer->role})");
            return Command::SUCCESS;
        }
        
        // Handle full/incremental sync
        $fullSync = $this->option('full');
        $dryRun = $this->option('dry-run');
        $limit = $this->option('limit') ? (int)$this->option('limit') : null;
        
        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No data will be saved');
        }
        
        $this->info($fullSync ? 'Performing full sync...' : 'Performing incremental sync...');
        
        if ($limit) {
            $this->info("Limiting to {$limit} customers");
        }
        
        $stats = $syncService->syncAllCustomers($fullSync, $dryRun, $limit);
        
        // Show sample data if dry-run
        if ($dryRun && !empty($stats['sample_data'])) {
            $this->info("\n📊 Sample Customer Data (First 10):\n");
            
            foreach ($stats['sample_data'] as $index => $sample) {
                $this->line("--- Customer " . ($index + 1) . " ---");
                $this->line("ID: {$sample['id']}");
                $this->line("Name: {$sample['name']}");
                $this->line("Extracted Role: " . ($sample['extracted_role'] ?? 'NULL'));
                
                // Show custom fields structure
                if (isset($sample['structure']['custom_fields'])) {
                    $this->line("Custom Fields: " . json_encode($sample['structure']['custom_fields'], JSON_PRETTY_PRINT));
                }
                if (isset($sample['structure']['extraFields'])) {
                    $this->line("Extra Fields: " . json_encode($sample['structure']['extraFields'], JSON_PRETTY_PRINT));
                }
                $this->line("");
            }
        }
        
        // Show stats table
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Fetched', $stats['total_fetched']],
                ['Created', $stats['created']],
                ['Updated', $stats['updated']],
                ['Skipped (dry-run)', $stats['skipped']],
                ['Errors', $stats['errors']],
            ]
        );
        
        if ($stats['errors'] > 0) {
            $this->warn("⚠️ {$stats['errors']} customers had errors - check logs");
        }
        
        if ($dryRun) {
            $this->info("\n✅ Dry run completed - Review sample data above to verify role extraction");
        } else {
            $this->info('✅ Customer sync completed successfully');
        }
        
        return Command::SUCCESS;
    }
}
```

---

## Phase 5: Webhooks

### Update `routes/api.php`

```php
Route::post('/webhooks/robaws/customers', [RobawsWebhookController::class, 'handleCustomer'])
    ->middleware('throttle:60,1')
    ->name('webhooks.robaws.customers');
```

### Update `app/Http/Controllers/Api/RobawsWebhookController.php`

```php
public function handleCustomer(Request $request): JsonResponse
{
    $startTime = microtime(true);
    
    try {
        // Verify webhook signature
        $this->verifySignature($request);
        
        $payload = $request->all();
        $eventType = $payload['event'] ?? 'unknown';
        $clientId = $payload['data']['id'] ?? null;
        
        // Log webhook
        $log = RobawsWebhookLog::create([
            'event_type' => $eventType,
            'payload' => $payload,
            'status' => 'processing',
            'processing_started_at' => now(),
        ]);
        
        // Process customer
        $customer = app(RobawsCustomerSyncService::class)
            ->processCustomerFromWebhook($payload);
        
        // Update log
        $log->update([
            'status' => 'success',
            'processing_completed_at' => now(),
            'processing_duration_ms' => (int)((microtime(true) - $startTime) * 1000),
        ]);
        
        Log::info('Customer webhook processed', [
            'event_type' => $eventType,
            'client_id' => $clientId,
            'customer_name' => $customer->name,
            'role' => $customer->role,
        ]);
        
        return response()->json(['status' => 'success']);
        
    } catch (\Exception $e) {
        Log::error('Customer webhook failed', [
            'error' => $e->getMessage(),
            'payload' => $request->all(),
        ]);
        
        if (isset($log)) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'processing_completed_at' => now(),
            ]);
        }
        
        return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}
```

---

## Phase 6: Filament Resource

### `app/Filament/Resources/RobawsCustomerResource.php`

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
use Illuminate\Support\Facades\Artisan;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class RobawsCustomerResource extends Resource
{
    protected static ?string $model = RobawsCustomerCache::class;
    
    protected static ?string $navigationIcon = 'heroicon-o-users';
    
    protected static ?string $navigationLabel = 'Robaws Customers';
    
    protected static ?string $navigationGroup = 'Robaws Data';
    
    protected static ?int $navigationSort = 2;
    
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Customer Information')
                    ->schema([
                        Forms\Components\TextInput::make('robaws_client_id')
                            ->label('Robaws Client ID')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->disabled(),
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('role')
                            ->options([
                                'FORWARDER' => 'FORWARDER',
                                'POV' => 'POV',
                                'BROKER' => 'BROKER',
                                'SHIPPING LINE' => 'SHIPPING LINE',
                                'CAR DEALER' => 'CAR DEALER',
                                'LUXURY CAR DEALER' => 'LUXURY CAR DEALER',
                                'EMBASSY' => 'EMBASSY',
                                'TRANSPORT COMPANY' => 'TRANSPORT COMPANY',
                                'OEM' => 'OEM',
                                'RENTAL' => 'RENTAL',
                                'CONSTRUCTION COMPANY' => 'CONSTRUCTION COMPANY',
                                'MINING COMPANY' => 'MINING COMPANY',
                                'TOURIST' => 'TOURIST',
                                'BLACKLISTED' => 'BLACKLISTED',
                                'RORO' => 'RORO',
                                'HOLLANDICO' => 'HOLLANDICO',
                            ])
                            ->searchable()
                            ->helperText('Custom field from Robaws'),
                        Forms\Components\Select::make('client_type')
                            ->options([
                                'company' => 'Company',
                                'individual' => 'Individual',
                            ])
                            ->default('company'),
                    ])->columns(2),
                    
                Forms\Components\Section::make('Contact Information')
                    ->schema([
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone')
                            ->tel()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('mobile')
                            ->tel()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('website')
                            ->url()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('vat_number')
                            ->maxLength(255),
                    ])->columns(2),
                    
                Forms\Components\Section::make('Address')
                    ->schema([
                        Forms\Components\TextInput::make('street')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('street_number')
                            ->maxLength(50),
                        Forms\Components\TextInput::make('postal_code')
                            ->maxLength(20),
                        Forms\Components\TextInput::make('city')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('country')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('country_code')
                            ->maxLength(2)
                            ->placeholder('BE'),
                    ])->columns(3),
                    
                Forms\Components\Section::make('Preferences')
                    ->schema([
                        Forms\Components\Select::make('language')
                            ->options([
                                'nl' => 'Dutch',
                                'fr' => 'French',
                                'en' => 'English',
                                'de' => 'German',
                            ])
                            ->default('nl'),
                        Forms\Components\Select::make('currency')
                            ->options([
                                'EUR' => 'EUR (€)',
                                'USD' => 'USD ($)',
                                'GBP' => 'GBP (£)',
                            ])
                            ->default('EUR'),
                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                    ])->columns(3),
            ]);
    }
    
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('robaws_client_id')
                    ->label('Client ID')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->wrap(),
                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->color(fn ($record) => $record->getRoleBadgeColor())
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('phone')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('city')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('country')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vat_number')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('intakes_count')
                    ->counts('intakes')
                    ->label('Intakes')
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_synced_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_pushed_to_robaws_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->options([
                        'FORWARDER' => 'FORWARDER',
                        'POV' => 'POV',
                        'BROKER' => 'BROKER',
                        'SHIPPING LINE' => 'SHIPPING LINE',
                        'CAR DEALER' => 'CAR DEALER',
                        'LUXURY CAR DEALER' => 'LUXURY CAR DEALER',
                        'TOURIST' => 'TOURIST',
                        'BLACKLISTED' => 'BLACKLISTED',
                    ])
                    ->multiple(),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active')
                    ->placeholder('All customers')
                    ->trueLabel('Active customers')
                    ->falseLabel('Inactive customers'),
                Tables\Filters\Filter::make('country')
                    ->form([
                        Forms\Components\TextInput::make('country')
                            ->placeholder('Enter country name'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query->when(
                            $data['country'],
                            fn ($query, $country) => $query->where('country', 'like', "%{$country}%")
                        );
                    }),
                Tables\Filters\Filter::make('has_intakes')
                    ->label('Has Intakes')
                    ->query(fn ($query) => $query->has('intakes')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('sync')
                    ->label('Sync from Robaws')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->action(function (RobawsCustomerCache $record) {
                        try {
                            Artisan::call('robaws:sync-customers', ['--client-id' => $record->robaws_client_id]);
                            
                            Notification::make()
                                ->title('Customer synced successfully')
                                ->body("Synced {$record->name} from Robaws")
                                ->success()
                                ->send();
                                
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Sync failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('push')
                    ->label('Push to Robaws')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (RobawsCustomerCache $record) {
                        $syncService = app(\App\Services\Robaws\RobawsCustomerSyncService::class);
                        
                        if ($syncService->pushCustomerToRobaws($record)) {
                            Notification::make()
                                ->title('Customer pushed successfully')
                                ->body("Pushed {$record->name} to Robaws")
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Push failed')
                                ->body('Check logs for details')
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\BulkAction::make('markInactive')
                        ->label('Mark as Inactive')
                        ->icon('heroicon-o-x-circle')
                        ->color('warning')
                        ->action(fn ($records) => $records->each->update(['is_active' => false]))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->headerActions([
                Tables\Actions\Action::make('syncAll')
                    ->label('Sync All Customers')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Sync All Customers from Robaws?')
                    ->modalDescription('This will sync all ~4,017 customers from Robaws. This may take 5-10 minutes.')
                    ->action(function () {
                        try {
                            Artisan::queue('robaws:sync-customers', ['--full' => true]);
                            
                            Notification::make()
                                ->title('Customer sync queued')
                                ->body('Syncing all customers in the background. This will take 5-10 minutes.')
                                ->success()
                                ->duration(10000)
                                ->send();
                                
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Failed to queue sync')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('pushAll')
                    ->label('Push Changes to Robaws')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Push Local Changes to Robaws?')
                    ->modalDescription('This will push all locally modified customers back to Robaws.')
                    ->action(function () {
                        try {
                            Artisan::queue('robaws:sync-customers', ['--push' => true]);
                            
                            Notification::make()
                                ->title('Push queued')
                                ->body('Pushing customer changes to Robaws in the background.')
                                ->success()
                                ->send();
                                
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Failed to queue push')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('findDuplicates')
                    ->label('Find Duplicates')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->action(function () {
                        $duplicates = RobawsCustomerCache::select('name', DB::raw('count(*) as count'))
                            ->groupBy('name')
                            ->having('count', '>', 1)
                            ->get();
                        
                        if ($duplicates->isEmpty()) {
                            Notification::make()
                                ->title('No duplicates found')
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Duplicates found')
                                ->body("Found {$duplicates->count()} duplicate customer names")
                                ->warning()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('last_synced_at', 'desc')
            ->poll('30s'); // Auto-refresh every 30 seconds
    }
    
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRobawsCustomers::route('/'),
            'create' => Pages\CreateRobawsCustomer::route('/create'),
            'edit' => Pages\EditRobawsCustomer::route('/{record}/edit'),
        ];
    }
    
    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }
}
```

---

## Phase 7: Intake Integration

### Update `app/Models/Intake.php`

```php
use Illuminate\Database\Eloquent\Relations\BelongsTo;

public function customer(): BelongsTo
{
    return $this->belongsTo(RobawsCustomerCache::class, 'robaws_client_id', 'robaws_client_id');
}
```

### Update Intake Filament Form

In your `IntakeResource.php` form, replace customer text inputs with:

```php
Forms\Components\Select::make('robaws_client_id')
    ->label('Customer')
    ->searchable()
    ->getSearchResultsUsing(fn (string $search) => 
        RobawsCustomerCache::where('name', 'like', "%{$search}%")
            ->orWhere('email', 'like', "%{$search}%")
            ->orWhere('vat_number', 'like', "%{$search}%")
            ->limit(50)
            ->get()
            ->mapWithKeys(fn ($customer) => [
                $customer->robaws_client_id => $customer->name . 
                    ($customer->role ? " ({$customer->role})" : '') . 
                    ($customer->city ? " - {$customer->city}" : '')
            ])
    )
    ->getOptionLabelUsing(fn ($value) => 
        RobawsCustomerCache::where('robaws_client_id', $value)->first()?->full_name_with_role
    )
    ->helperText('Search by name, email, or VAT number')
    ->live()
    ->afterStateUpdated(function ($state, Forms\Set $set) {
        if ($state) {
            $customer = RobawsCustomerCache::where('robaws_client_id', $state)->first();
            if ($customer) {
                $set('customer_name', $customer->name);
                $set('contact_email', $customer->email);
                $set('contact_phone', $customer->phone);
            }
        }
    }),
```

---

## Phase 8: Scheduling

### Update `routes/console.php`

```php
use Illuminate\Support\Facades\Schedule;

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

// Daily push customer updates (Bconnect → Robaws)
Schedule::command('robaws:sync-customers --push')
    ->daily()
    ->at('22:00') // Evening push
    ->withoutOverlapping();
```

---

## Implementation Checklist

### Phase 1: Database & Model
- [ ] Create `create_robaws_customers_cache_table` migration
- [ ] Run `php artisan migrate`
- [ ] Create `RobawsCustomerCache` model

### Phase 2: Sync Service
- [ ] Create `RobawsCustomerSyncService`
- [ ] Integrate `CustomerNormalizer` for data cleanup
- [ ] Implement custom field role extraction (similar to article parent_item)
- [ ] Implement bi-directional push logic

### Phase 3: Commands
- [ ] Create `SyncRobawsCustomers` command with:
  - [x] `--full` flag
  - [x] `--push` flag
  - [x] `--dry-run` flag (for role field inspection)
  - [x] `--limit` flag (for testing)
  - [x] `--client-id` flag (for single customer sync)

### Phase 4: Dry Run Testing ⚠️ CRITICAL
- [ ] Run `php artisan robaws:sync-customers --dry-run --limit=10`
- [ ] Inspect sample customer data output
- [ ] Verify role extraction from custom fields
- [ ] Identify exact custom field ID/name for role (like parent_item GUID)
- [ ] Update `extractRole()` method if needed

### Phase 5: Webhooks
- [ ] Add customer webhook route to `routes/api.php`
- [ ] Add `handleCustomer()` method to `RobawsWebhookController`
- [ ] Register webhooks with Robaws:
  - `php artisan robaws:register-webhook --event=client.created --url=https://app.belgaco.be/api/webhooks/robaws/customers`
  - `php artisan robaws:register-webhook --event=client.updated --url=https://app.belgaco.be/api/webhooks/robaws/customers`

### Phase 6: Filament
- [ ] Create `RobawsCustomerResource` with:
  - [x] Customer CRUD
  - [x] Sync buttons (individual + bulk)
  - [x] Push buttons (individual + bulk)
  - [x] Duplicate detection
  - [x] Role filter
  - [x] Intakes count column

### Phase 7: Intake Integration
- [ ] Add `customer()` relationship to `Intake` model
- [ ] Update `IntakeResource` form with searchable customer select
- [ ] Test customer selection in intake creation

### Phase 8: Scheduling
- [ ] Add customer sync schedule to `routes/console.php`
- [ ] Add push schedule to `routes/console.php`

### Phase 9: Testing
- [ ] Test dry-run mode (inspect 10 customers)
- [ ] Test full sync (all 4,017 customers)
- [ ] Verify "Aeon Shipping LLC" has role "FORWARDER"
- [ ] Test webhook handling (`client.created`, `client.updated`)
- [ ] Test bi-directional push (edit customer in Bconnect → push to Robaws)
- [ ] Test customer selection in intake creation
- [ ] Test duplicate detection

### Phase 10: Deployment
- [ ] Commit changes
- [ ] Push to production
- [ ] Run migration: `php artisan migrate --force`
- [ ] Clear caches: `php artisan cache:clear && php artisan config:cache`
- [ ] Run dry-run first: `php artisan robaws:sync-customers --dry-run --limit=10`
- [ ] Run full sync: `php artisan robaws:sync-customers --full`
- [ ] Register webhooks on production
- [ ] Monitor logs for errors

---

## Success Criteria

✅ **Customer Sync**:
- 4,017 customers imported from Robaws
- Roles correctly extracted from custom fields (Aeon = FORWARDER)
- Bi-directional sync working (Robaws ↔ Bconnect)
- Webhooks processing customer updates

✅ **Data Quality**:
- Phone numbers normalized (+32 for Belgian)
- VAT numbers normalized (BE prefix)
- Addresses properly structured
- No duplicate customers

✅ **Integration**:
- Customer selection works in intake creation
- Intakes count displayed per customer
- Search works by name, email, VAT

✅ **Monitoring**:
- Sync logs show successful syncs
- Webhook logs show successful processing
- No errors in Laravel logs

---

## Key Differences from Article Sync

1. **Custom Field Extraction**: Role is extracted from custom fields (like parent_item)
2. **Data Normalization**: Uses `CustomerNormalizer` for phone/VAT/address cleanup
3. **Duplicate Detection**: Customers can have duplicates (same name, different IDs)
4. **Bi-Directional Push**: Customers can be edited in Bconnect and pushed back to Robaws
5. **Intake Integration**: Customers are selected in intake creation

---

**Ready to implement! Start with Phase 1-3, then run dry-run to verify role extraction.**
