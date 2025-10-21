# Customer Sync Implementation (Phase 1 Only)

## 🎯 Focus: Customer Architecture Only

**Defer pricing system** until we understand composite items from Robaws.

**Current Priority**: Build solid customer sync foundation that we can extend later.

---

## Phase 1: Customer Sync Foundation

### Database Schema (Simplified)

**Migration 1**: `create_robaws_customers_cache_table`

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
    $table->boolean('is_active')->default(true);
    $table->json('metadata')->nullable(); // Additional Robaws fields
    $table->timestamp('last_synced_at')->nullable();
    $table->timestamp('last_pushed_to_robaws_at')->nullable(); // Track push
    $table->timestamps();
    
    $table->index(['role', 'is_active']);
    $table->index(['last_synced_at']);
});
```

### Model: `RobawsCustomerCache.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RobawsCustomerCache extends Model
{
    protected $fillable = [
        'robaws_client_id',
        'name',
        'role',
        'email',
        'phone',
        'address',
        'city',
        'country',
        'postal_code',
        'vat_number',
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
}
```

### Service: `RobawsCustomerSyncService.php`

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
     * Sync all customers from Robaws
     */
    public function syncAllCustomers(bool $fullSync = false): array
    {
        $stats = [
            'total_fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
        ];
        
        try {
            $page = 1;
            $perPage = 100; // Adjust based on API limits
            
            do {
                Log::info('Fetching customers page', ['page' => $page, 'per_page' => $perPage]);
                
                $response = $this->apiClient->searchClients([
                    'page' => $page,
                    'per_page' => $perPage,
                    'include' => 'all', // Get all fields
                ]);
                
                $customers = $response['data'] ?? [];
                $stats['total_fetched'] += count($customers);
                
                foreach ($customers as $customerData) {
                    try {
                        $customer = $this->processCustomer($customerData, $fullSync);
                        
                        if ($customer->wasRecentlyCreated) {
                            $stats['created']++;
                        } else {
                            $stats['updated']++;
                        }
                        
                    } catch (\Exception $e) {
                        Log::error('Failed to process customer', [
                            'customer_id' => $customerData['id'] ?? 'unknown',
                            'error' => $e->getMessage(),
                        ]);
                        $stats['errors']++;
                    }
                }
                
                $page++;
                
            } while (count($customers) === $perPage);
            
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
        
        // Extract role from customer data
        $role = $this->extractRole($customerData);
        
        // Normalize customer data
        $normalizedData = [
            'robaws_client_id' => $clientId,
            'name' => $customerData['name'] ?? 'Unknown',
            'role' => $role,
            'email' => $customerData['email'] ?? null,
            'phone' => $customerData['phone'] ?? null,
            'address' => $customerData['address'] ?? null,
            'city' => $customerData['city'] ?? null,
            'country' => $customerData['country'] ?? null,
            'postal_code' => $customerData['postal_code'] ?? null,
            'vat_number' => $customerData['vat_number'] ?? null,
            'is_active' => $customerData['is_active'] ?? true,
            'metadata' => $customerData, // Store full data
            'last_synced_at' => now(),
        ];
        
        return RobawsCustomerCache::updateOrCreate(
            ['robaws_client_id' => $clientId],
            $normalizedData
        );
    }
    
    /**
     * Extract role from customer data
     */
    protected function extractRole(array $customerData): string
    {
        // Try different possible field names for role
        $roleFields = ['role', 'customer_type', 'type', 'category'];
        
        foreach ($roleFields as $field) {
            if (isset($customerData[$field]) && !empty($customerData[$field])) {
                return strtoupper(trim($customerData[$field]));
            }
        }
        
        // Check custom fields
        if (isset($customerData['custom_fields'])) {
            foreach ($customerData['custom_fields'] as $field) {
                if (isset($field['name']) && stripos($field['name'], 'role') !== false) {
                    return strtoupper(trim($field['value'] ?? ''));
                }
            }
        }
        
        // Default fallback
        return 'UNKNOWN';
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
            $customerData = $this->apiClient->getClient($clientId);
            return $this->processCustomer($customerData, false);
        } catch (\Exception $e) {
            Log::error('Failed to sync single customer', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
```

### Command: `SyncRobawsCustomers.php`

```php
<?php

namespace App\Console\Commands;

use App\Services\Robaws\RobawsCustomerSyncService;
use Illuminate\Console\Command;

class SyncRobawsCustomers extends Command
{
    protected $signature = 'robaws:sync-customers 
                            {--full : Perform full sync instead of incremental}
                            {--client-id= : Sync specific customer by ID}';
    
    protected $description = 'Sync customers from Robaws API';
    
    public function handle(RobawsCustomerSyncService $syncService): int
    {
        $this->info('Starting customer sync...');
        
        try {
            if ($clientId = $this->option('client-id')) {
                $this->info("Syncing customer: {$clientId}");
                $customer = $syncService->syncSingleCustomer($clientId);
                $this->info("✅ Synced: {$customer->name} ({$customer->role})");
                
            } else {
                $fullSync = $this->option('full');
                $this->info($fullSync ? 'Performing full sync...' : 'Performing incremental sync...');
                
                $stats = $syncService->syncAllCustomers($fullSync);
                
                $this->table(
                    ['Metric', 'Count'],
                    [
                        ['Total Fetched', $stats['total_fetched']],
                        ['Created', $stats['created']],
                        ['Updated', $stats['updated']],
                        ['Errors', $stats['errors']],
                    ]
                );
                
                if ($stats['errors'] > 0) {
                    $this->warn("⚠️ {$stats['errors']} customers had errors - check logs");
                }
            }
            
            $this->info('✅ Customer sync completed successfully');
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("❌ Customer sync failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
```

### Webhook Handling

**Route** (`routes/api.php`):
```php
Route::post('/webhooks/robaws/customers', [RobawsWebhookController::class, 'handleCustomer'])
    ->middleware('throttle:60,1')
    ->name('webhooks.robaws.customers');
```

**Controller Method** (`app/Http/Controllers/Api/RobawsWebhookController.php`):
```php
public function handleCustomer(Request $request): JsonResponse
{
    try {
        // Verify webhook signature
        $this->verifySignature($request);
        
        $payload = $request->all();
        $eventType = $payload['event'] ?? 'unknown';
        
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
            'processing_duration_ms' => $log->processing_started_at->diffInMilliseconds(now()),
        ]);
        
        return response()->json(['status' => 'success']);
        
    } catch (\Exception $e) {
        Log::error('Customer webhook failed', [
            'error' => $e->getMessage(),
            'payload' => $request->all(),
        ]);
        
        return response()->json(['status' => 'error'], 500);
    }
}
```

### Filament Resource: `RobawsCustomerResource.php`

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

class RobawsCustomerResource extends Resource
{
    protected static ?string $model = RobawsCustomerCache::class;
    
    protected static ?string $navigationIcon = 'heroicon-o-users';
    
    protected static ?string $navigationLabel = 'Robaws Customers';
    
    protected static ?string $navigationGroup = 'Robaws Data';
    
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Customer Information')
                    ->schema([
                        Forms\Components\TextInput::make('robaws_client_id')
                            ->label('Robaws Client ID')
                            ->required()
                            ->unique(ignoreRecord: true),
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
                            ->required()
                            ->searchable(),
                    ])->columns(2),
                    
                Forms\Components\Section::make('Contact Information')
                    ->schema([
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone')
                            ->tel()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('address')
                            ->maxLength(65535),
                        Forms\Components\TextInput::make('city')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('country')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('postal_code')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('vat_number')
                            ->maxLength(255),
                    ])->columns(2),
                    
                Forms\Components\Section::make('Status')
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                    ]),
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
                    ->copyable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'FORWARDER' => 'primary',
                        'POV' => 'success',
                        'BROKER' => 'warning',
                        'SHIPPING LINE' => 'info',
                        'CAR DEALER' => 'secondary',
                        'LUXURY CAR DEALER' => 'success',
                        'TOURIST' => 'danger',
                        'BLACKLISTED' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('phone')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('city')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('country')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_synced_at')
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
                    ]),
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
            ])
            ->headerActions([
                Tables\Actions\Action::make('syncAll')
                    ->label('Sync All Customers')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Sync All Customers from Robaws?')
                    ->modalDescription('This will sync all 4,017 customers from Robaws. This may take several minutes.')
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
            ])
            ->defaultSort('last_synced_at', 'desc');
    }
    
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRobawsCustomers::route('/'),
            'create' => Pages\CreateRobawsCustomer::route('/create'),
            'edit' => Pages\EditRobawsCustomer::route('/{record}/edit'),
        ];
    }
}
```

### Scheduling (`routes/console.php`)

```php
// Daily customer sync (incremental)
Schedule::command('robaws:sync-customers')
    ->daily()
    ->at('03:00')
    ->withoutOverlapping();

// Weekly full customer sync (safety net)
Schedule::command('robaws:sync-customers --full')
    ->weekly()
    ->sundays()
    ->at('04:00');
```

---

## 🎯 Implementation Checklist (Customer Sync Only)

### Database
- [ ] Create `robaws_customers_cache` migration
- [ ] Run migration

### Models
- [ ] Create `RobawsCustomerCache` model

### Services
- [ ] Create `RobawsCustomerSyncService`

### Commands
- [ ] Create `SyncRobawsCustomers` command

### Webhooks
- [ ] Add customer webhook route to `routes/api.php`
- [ ] Add `handleCustomer()` method to `RobawsWebhookController`
- [ ] Register webhooks with Robaws (`client.created`, `client.updated`)

### Filament
- [ ] Create `RobawsCustomerResource` with sync buttons

### Scheduling
- [ ] Add customer sync schedule to `routes/console.php`

### Testing
- [ ] Test customer sync (import 4,017 customers)
- [ ] Verify role extraction (especially "Aeon Shipping LLC" = FORWARDER)
- [ ] Test webhook handling for customer updates
- [ ] Test customer selection in intake creation

---

## 🚀 Next Steps After Customer Sync

1. **Wait for Robaws response** on composite items API access
2. **Understand composite item structure** and pricing
3. **Design pricing system** based on composite item requirements
4. **Implement carrier pricing** and article auto-generation
5. **Build role-based adjustments** and quote generation

---

**This approach is much safer!** We build a solid customer foundation first, then add pricing complexity once we understand composite items.

**Ready to implement the customer sync foundation?**
