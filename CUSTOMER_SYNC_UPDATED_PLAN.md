# Customer Sync Plan - UPDATED with Pricing/Margin Logic

## 🎯 Critical Business Requirement

**Customer Role → Article Pricing/Margins**

- Each customer has a **Role** (FORWARDER, POV, BROKER, etc.)
- When creating quotes, **margins are calculated based on customer role**
- This affects pricing for all articles in the quote

---

## Updated Implementation Plan

### Phase 1: Customer Sync (Foundation)

**No changes** - still sync all customer data including the `role` field.

### Phase 2: Pricing/Margin Configuration

**NEW**: Create a pricing matrix that maps customer roles to margin percentages.

#### Option A: Margin Configuration Table (Recommended)

**Create Migration**: `create_customer_role_margins_table.php`

```php
Schema::create('customer_role_margins', function (Blueprint $table) {
    $table->id();
    $table->string('role')->unique(); // FORWARDER, POV, BROKER, etc.
    $table->decimal('default_margin_percentage', 5, 2)->default(0.00); // e.g., 15.00 = 15%
    $table->decimal('min_margin_percentage', 5, 2)->default(0.00); // e.g., 5.00 = 5%
    $table->decimal('max_margin_percentage', 5, 2)->nullable(); // e.g., 25.00 = 25%
    $table->boolean('is_active')->default(true);
    $table->json('article_category_overrides')->nullable(); // Custom margins per article category
    $table->text('notes')->nullable();
    $table->timestamps();
});
```

**Model**: `CustomerRoleMargin.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerRoleMargin extends Model
{
    protected $fillable = [
        'role',
        'default_margin_percentage',
        'min_margin_percentage',
        'max_margin_percentage',
        'is_active',
        'article_category_overrides',
        'notes',
    ];
    
    protected $casts = [
        'default_margin_percentage' => 'decimal:2',
        'min_margin_percentage' => 'decimal:2',
        'max_margin_percentage' => 'decimal:2',
        'is_active' => 'boolean',
        'article_category_overrides' => 'array',
    ];
    
    /**
     * Get margin for a specific article category
     */
    public function getMarginForCategory(string $category): float
    {
        $overrides = $this->article_category_overrides ?? [];
        
        return $overrides[$category] ?? $this->default_margin_percentage;
    }
    
    /**
     * Calculate price with margin applied
     */
    public function calculatePriceWithMargin(float $costPrice, ?string $category = null): float
    {
        $margin = $category 
            ? $this->getMarginForCategory($category) 
            : $this->default_margin_percentage;
        
        return $costPrice * (1 + ($margin / 100));
    }
}
```

#### Seed Default Margin Data

**Seeder**: `CustomerRoleMarginSeeder.php`

```php
<?php

namespace Database\Seeders;

use App\Models\CustomerRoleMargin;
use Illuminate\Database\Seeder;

class CustomerRoleMarginSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'role' => 'FORWARDER',
                'default_margin_percentage' => 12.00,
                'min_margin_percentage' => 8.00,
                'max_margin_percentage' => 20.00,
                'notes' => 'Standard forwarder pricing - moderate margins',
            ],
            [
                'role' => 'POV',
                'default_margin_percentage' => 15.00,
                'min_margin_percentage' => 10.00,
                'max_margin_percentage' => 25.00,
                'notes' => 'POV customers - higher margins',
            ],
            [
                'role' => 'BROKER',
                'default_margin_percentage' => 10.00,
                'min_margin_percentage' => 5.00,
                'max_margin_percentage' => 18.00,
                'notes' => 'Broker pricing - competitive margins',
            ],
            [
                'role' => 'SHIPPING LINE',
                'default_margin_percentage' => 8.00,
                'min_margin_percentage' => 5.00,
                'max_margin_percentage' => 15.00,
                'notes' => 'Shipping line partners - lower margins, high volume',
            ],
            [
                'role' => 'CAR DEALER',
                'default_margin_percentage' => 18.00,
                'min_margin_percentage' => 12.00,
                'max_margin_percentage' => 30.00,
                'notes' => 'Car dealers - higher margins',
            ],
            [
                'role' => 'LUXURY CAR DEALER',
                'default_margin_percentage' => 20.00,
                'min_margin_percentage' => 15.00,
                'max_margin_percentage' => 35.00,
                'notes' => 'Luxury car dealers - premium pricing',
            ],
            [
                'role' => 'EMBASSY',
                'default_margin_percentage' => 12.00,
                'min_margin_percentage' => 8.00,
                'max_margin_percentage' => 20.00,
                'notes' => 'Embassy customers - standard government rates',
            ],
            [
                'role' => 'TRANSPORT COMPANY',
                'default_margin_percentage' => 10.00,
                'min_margin_percentage' => 6.00,
                'max_margin_percentage' => 18.00,
                'notes' => 'Transport companies - competitive pricing',
            ],
            [
                'role' => 'OEM',
                'default_margin_percentage' => 8.00,
                'min_margin_percentage' => 5.00,
                'max_margin_percentage' => 15.00,
                'notes' => 'OEM partners - low margins, high volume',
            ],
            [
                'role' => 'RENTAL',
                'default_margin_percentage' => 15.00,
                'min_margin_percentage' => 10.00,
                'max_margin_percentage' => 25.00,
                'notes' => 'Rental companies - standard margins',
            ],
            [
                'role' => 'CONSTRUCTION COMPANY',
                'default_margin_percentage' => 12.00,
                'min_margin_percentage' => 8.00,
                'max_margin_percentage' => 20.00,
                'notes' => 'Construction companies - standard B2B pricing',
            ],
            [
                'role' => 'MINING COMPANY',
                'default_margin_percentage' => 12.00,
                'min_margin_percentage' => 8.00,
                'max_margin_percentage' => 20.00,
                'notes' => 'Mining companies - industrial pricing',
            ],
            [
                'role' => 'TOURIST',
                'default_margin_percentage' => 25.00,
                'min_margin_percentage' => 15.00,
                'max_margin_percentage' => 40.00,
                'notes' => 'Tourist/individual customers - highest margins',
            ],
            [
                'role' => 'BLACKLISTED',
                'default_margin_percentage' => 0.00,
                'min_margin_percentage' => 0.00,
                'max_margin_percentage' => 0.00,
                'notes' => 'BLACKLISTED - DO NOT QUOTE',
                'is_active' => false,
            ],
            [
                'role' => 'RORO',
                'default_margin_percentage' => 10.00,
                'min_margin_percentage' => 6.00,
                'max_margin_percentage' => 18.00,
                'notes' => 'RORO customers - competitive pricing',
            ],
            [
                'role' => 'HOLLANDICO',
                'default_margin_percentage' => 12.00,
                'min_margin_percentage' => 8.00,
                'max_margin_percentage' => 20.00,
                'notes' => 'Hollandico - standard pricing',
            ],
        ];
        
        foreach ($roles as $roleData) {
            CustomerRoleMargin::updateOrCreate(
                ['role' => $roleData['role']],
                $roleData
            );
        }
    }
}
```

### Phase 3: Filament Resource for Margin Management

**Create Resource**: `CustomerRoleMarginResource.php`

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerRoleMarginResource\Pages;
use App\Models\CustomerRoleMargin;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CustomerRoleMarginResource extends Resource
{
    protected static ?string $model = CustomerRoleMargin::class;
    
    protected static ?string $navigationIcon = 'heroicon-o-calculator';
    
    protected static ?string $navigationLabel = 'Customer Role Margins';
    
    protected static ?string $navigationGroup = 'Pricing Configuration';
    
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Role Information')
                    ->schema([
                        Forms\Components\TextInput::make('role')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->helperText('Must match customer role from Robaws'),
                        Forms\Components\Toggle::make('is_active')
                            ->default(true)
                            ->helperText('Inactive roles cannot be used for pricing'),
                    ])->columns(2),
                    
                Forms\Components\Section::make('Margin Configuration')
                    ->schema([
                        Forms\Components\TextInput::make('default_margin_percentage')
                            ->label('Default Margin %')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->helperText('Standard margin for this role'),
                        Forms\Components\TextInput::make('min_margin_percentage')
                            ->label('Minimum Margin %')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->helperText('Lowest acceptable margin'),
                        Forms\Components\TextInput::make('max_margin_percentage')
                            ->label('Maximum Margin %')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->helperText('Highest recommended margin (optional)'),
                    ])->columns(3),
                    
                Forms\Components\Section::make('Category Overrides')
                    ->schema([
                        Forms\Components\KeyValue::make('article_category_overrides')
                            ->label('Custom Margins by Article Category')
                            ->keyLabel('Article Category')
                            ->valueLabel('Margin %')
                            ->helperText('Override default margin for specific article categories (e.g., seafreight: 10, customs: 15)'),
                    ]),
                    
                Forms\Components\Textarea::make('notes')
                    ->maxLength(65535)
                    ->columnSpanFull(),
            ]);
    }
    
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('role')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'BLACKLISTED' => 'danger',
                        'LUXURY CAR DEALER', 'TOURIST' => 'success',
                        'OEM', 'SHIPPING LINE' => 'warning',
                        default => 'primary',
                    }),
                Tables\Columns\TextColumn::make('default_margin_percentage')
                    ->label('Default Margin')
                    ->sortable()
                    ->suffix('%')
                    ->color('success'),
                Tables\Columns\TextColumn::make('min_margin_percentage')
                    ->label('Min')
                    ->suffix('%')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('max_margin_percentage')
                    ->label('Max')
                    ->suffix('%')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('notes')
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active')
                    ->placeholder('All roles')
                    ->trueLabel('Active roles')
                    ->falseLabel('Inactive roles'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('preview_pricing')
                    ->label('Preview Pricing')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->modalHeading('Pricing Preview')
                    ->modalContent(function (CustomerRoleMargin $record) {
                        $examples = [
                            ['Cost Price' => 100, 'Category' => 'Standard'],
                            ['Cost Price' => 500, 'Category' => 'Standard'],
                            ['Cost Price' => 1000, 'Category' => 'Standard'],
                        ];
                        
                        $rows = collect($examples)->map(function ($example) use ($record) {
                            $costPrice = $example['Cost Price'];
                            $salePrice = $record->calculatePriceWithMargin($costPrice);
                            $margin = $salePrice - $costPrice;
                            
                            return [
                                'Cost Price' => '€' . number_format($costPrice, 2),
                                'Margin %' => $record->default_margin_percentage . '%',
                                'Margin €' => '€' . number_format($margin, 2),
                                'Sale Price' => '€' . number_format($salePrice, 2),
                            ];
                        });
                        
                        return view('filament.pricing-preview', [
                            'role' => $record->role,
                            'rows' => $rows,
                        ]);
                    }),
            ])
            ->defaultSort('role', 'asc');
    }
    
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomerRoleMargins::route('/'),
            'create' => Pages\CreateCustomerRoleMargin::route('/create'),
            'edit' => Pages\EditCustomerRoleMargin::route('/{record}/edit'),
        ];
    }
}
```

### Phase 4: Update RobawsCustomerCache Model

**Add relationship to margins:**

```php
// In RobawsCustomerCache.php

public function roleMargin(): \Illuminate\Database\Eloquent\Relations\BelongsTo
{
    return $this->belongsTo(CustomerRoleMargin::class, 'role', 'role');
}

/**
 * Get the margin percentage for this customer
 */
public function getMarginPercentage(?string $articleCategory = null): float
{
    if (!$this->roleMargin) {
        Log::warning('No margin configuration for customer role', [
            'customer_id' => $this->robaws_client_id,
            'role' => $this->role,
        ]);
        return 0.00; // Default fallback
    }
    
    return $articleCategory 
        ? $this->roleMargin->getMarginForCategory($articleCategory)
        : $this->roleMargin->default_margin_percentage;
}

/**
 * Calculate article price for this customer
 */
public function calculateArticlePrice(float $costPrice, ?string $articleCategory = null): float
{
    if (!$this->roleMargin) {
        return $costPrice; // No margin, return cost price
    }
    
    return $this->roleMargin->calculatePriceWithMargin($costPrice, $articleCategory);
}
```

### Phase 5: Pricing Service for Quote Generation

**Create Service**: `CustomerPricingService.php`

```php
<?php

namespace App\Services\Pricing;

use App\Models\RobawsArticleCache;
use App\Models\RobawsCustomerCache;
use Illuminate\Support\Collection;

class CustomerPricingService
{
    /**
     * Calculate prices for multiple articles based on customer role
     */
    public function calculatePricesForCustomer(
        RobawsCustomerCache $customer, 
        Collection $articles
    ): array {
        $pricedArticles = [];
        
        foreach ($articles as $article) {
            $pricedArticles[] = [
                'article_id' => $article->id,
                'article_name' => $article->article_name,
                'category' => $article->category,
                'cost_price' => $article->cost_price ?? $article->unit_price,
                'margin_percentage' => $customer->getMarginPercentage($article->category),
                'sale_price' => $customer->calculateArticlePrice(
                    $article->cost_price ?? $article->unit_price,
                    $article->category
                ),
            ];
        }
        
        return $pricedArticles;
    }
    
    /**
     * Validate margin is within acceptable range for customer role
     */
    public function validateMargin(
        RobawsCustomerCache $customer,
        float $proposedMarginPercentage
    ): bool {
        if (!$customer->roleMargin) {
            return true; // No restrictions if no margin config
        }
        
        $min = $customer->roleMargin->min_margin_percentage;
        $max = $customer->roleMargin->max_margin_percentage;
        
        if ($proposedMarginPercentage < $min) {
            return false;
        }
        
        if ($max !== null && $proposedMarginPercentage > $max) {
            return false;
        }
        
        return true;
    }
}
```

### Phase 6: Update Intake/Quote Creation Flow

**When creating a quote, automatically calculate prices based on customer role:**

```php
// Example in IntakeResource or QuoteService

use App\Services\Pricing\CustomerPricingService;

public function createQuoteForIntake(Intake $intake): array
{
    $customer = RobawsCustomerCache::where('robaws_client_id', $intake->robaws_client_id)->first();
    
    if (!$customer) {
        throw new \Exception('Customer not found in cache');
    }
    
    // Get articles for this quote
    $articles = RobawsArticleCache::whereIn('id', $articleIds)->get();
    
    // Calculate prices based on customer role
    $pricingService = new CustomerPricingService();
    $pricedArticles = $pricingService->calculatePricesForCustomer($customer, $articles);
    
    return [
        'customer' => $customer,
        'customer_role' => $customer->role,
        'articles' => $pricedArticles,
        'total_cost' => collect($pricedArticles)->sum('cost_price'),
        'total_price' => collect($pricedArticles)->sum('sale_price'),
        'total_margin' => collect($pricedArticles)->sum(fn($a) => $a['sale_price'] - $a['cost_price']),
    ];
}
```

---

## Updated Database Schema

### Tables Created

1. **`robaws_customers_cache`** - Customer data from Robaws
2. **`customer_role_margins`** - Margin configuration per role

### Relationships

```
RobawsCustomerCache
  ├── role (string) ────────┐
  └── intakes (HasMany)     │
                            │
                            ▼
                  CustomerRoleMargin
                    ├── role (unique)
                    ├── default_margin_percentage
                    ├── min_margin_percentage
                    ├── max_margin_percentage
                    └── article_category_overrides (JSON)
```

---

## Updated Implementation Checklist

**Phase 1: Customer Sync** (as before)
- [ ] Create `robaws_customers_cache` table & model
- [ ] Create `RobawsCustomerSyncService`
- [ ] Create sync command and webhooks
- [ ] Create `RobawsCustomerResource` in Filament

**Phase 2: Pricing Configuration** (NEW)
- [ ] Create `customer_role_margins` table
- [ ] Create `CustomerRoleMargin` model
- [ ] Create `CustomerRoleMarginSeeder` with default margins
- [ ] Create `CustomerRoleMarginResource` in Filament
- [ ] Run seeder to populate initial margin data

**Phase 3: Pricing Logic** (NEW)
- [ ] Add `roleMargin()` relationship to `RobawsCustomerCache`
- [ ] Add `getMarginPercentage()` and `calculateArticlePrice()` methods
- [ ] Create `CustomerPricingService`
- [ ] Update quote generation to use customer role pricing

**Phase 4: Testing** (UPDATED)
- [ ] Verify Aeon Shipping LLC has role "FORWARDER"
- [ ] Test margin calculation for FORWARDER role (should be 12%)
- [ ] Test price calculation: €100 cost → €112 sale price
- [ ] Test different roles have different margins
- [ ] Test BLACKLISTED role prevents quote creation

---

## Business Rules Summary

1. **Customer Role determines pricing**: Each role has predefined margins
2. **Category overrides**: Specific article categories can have custom margins per role
3. **Margin validation**: Quotes must stay within min/max margins for the role
4. **Blacklisted customers**: Cannot receive quotes (0% margin, inactive)
5. **Default fallback**: If no margin config, use cost price (0% margin)

---

## Example Pricing Matrix

| Role | Default Margin | Min | Max | Notes |
|------|----------------|-----|-----|-------|
| **LUXURY CAR DEALER** | 20% | 15% | 35% | Highest margins |
| **TOURIST** | 25% | 15% | 40% | Individual customers |
| **CAR DEALER** | 18% | 12% | 30% | Standard dealers |
| **FORWARDER** | 12% | 8% | 20% | **Aeon's role** |
| **POV** | 15% | 10% | 25% | Mid-tier |
| **BROKER** | 10% | 5% | 18% | Competitive |
| **SHIPPING LINE** | 8% | 5% | 15% | High volume, low margin |
| **OEM** | 8% | 5% | 15% | Partner pricing |
| **BLACKLISTED** | 0% | 0% | 0% | **DO NOT QUOTE** |

---

## UI Preview

**In Filament CustomerResource:**
```
┌─────────────────────────────────────────────────────────┐
│ Robaws Customers                    [Sync All Customers]│
├─────────────────────────────────────────────────────────┤
│ Name               Role         Margin  Email            │
│ Aeon Shipping LLC  FORWARDER    12%     ajith@aeon...   │
│ Luxury Auto Dubai  LUXURY CAR    20%     info@luxury... │
│ Bad Customer Ltd   BLACKLISTED  0%      bad@customer... │
└─────────────────────────────────────────────────────────┘
```

**In CustomerRoleMarginResource:**
```
┌─────────────────────────────────────────────────────────┐
│ Customer Role Margins                        [+ Create] │
├─────────────────────────────────────────────────────────┤
│ Role            Default  Min   Max   Active              │
│ FORWARDER       12%      8%    20%   ✅                  │
│ LUXURY CAR...   20%      15%   35%   ✅                  │
│ BLACKLISTED     0%       0%    0%    ❌                  │
└─────────────────────────────────────────────────────────┘
```

---

This updated plan now includes the **complete pricing/margin logic** based on customer roles, which is critical for your business operations.

**Ready to implement with this updated plan?**

