# Customer Sync Plan - FINAL (Unit-Based Pricing)

## 🎯 Correct Business Requirement

**Customer Role → Unit Type → Price**

**Your Pricing Model**:
- Each customer has a **Role** (FORWARDER, POV, BROKER, etc.)
- Each role has a **price list** per unit type (LM, CBM, W/M, Ton, 20ft DV, etc.)
- Articles are priced by **quantity × unit price**
- Unit prices vary by customer role

**Example Quote**:
```
Customer: Aeon Shipping LLC (Role: FORWARDER)

Article 1: Seafreight RORO
  Unit: 20ft DV
  Quantity: 2
  FORWARDER price: €1,500/20ft DV
  Subtotal: 2 × €1,500 = €3,000

Article 2: Customs Clearance
  Unit: Lumpsum
  Quantity: 1
  FORWARDER price: €250/lumpsum
  Subtotal: 1 × €250 = €250

Article 3: Terminal Handling
  Unit: CBM
  Quantity: 15
  FORWARDER price: €8/CBM
  FORWARDER subtotal: 15 × €8 = €120

Total: €3,370
```

---

## Unit Types (From Screenshots)

**Page 1 (29 total units)**:
- % (Percentage)
- 20FT DV (20-foot Dry Van)
- 20FT OT (20-foot Open Top)
- 40FT DV (40-foot Dry Van)
- 40FT FR (40-foot Flat Rack)
- 40FT HC (40-foot High Cube)
- 40FT OT (40-foot Open Top)
- CBM (Cubic Meter)
- Chassis nr (Chassis number)
- Cont. (Container)
- Day
- Doc (Document)
- FRT (Freight)
- Hour
- LM (Linear Meter)

**Page 2 (Items 21-29)**:
- Shipm. (Shipment)
- SQM (Square Meter)
- stacked unit
- Teu (Twenty-foot Equivalent Unit)
- Ton
- Truck
- Unit
- Vehicle
- w/m (Weight/Measurement)

---

## Database Schema (Revised)

### Table 1: `robaws_customers_cache` (unchanged)

Stores customer data from Robaws, including `role` field.

### Table 2: `unit_types`

Master list of all available unit types.

```php
Schema::create('unit_types', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique(); // 20FT_DV, CBM, LM, W/M, etc.
    $table->string('name'); // 20-foot Dry Van, Cubic Meter, etc.
    $table->string('category')->nullable(); // container, volume, weight, lumpsum, etc.
    $table->boolean('is_active')->default(true);
    $table->integer('sort_order')->default(0);
    $table->timestamps();
});
```

### Table 3: `customer_role_unit_prices`

Stores unit prices per customer role per unit type.

```php
Schema::create('customer_role_unit_prices', function (Blueprint $table) {
    $table->id();
    $table->string('role'); // FORWARDER, POV, BROKER, etc.
    $table->foreignId('unit_type_id')->constrained('unit_types')->onDelete('cascade');
    $table->decimal('unit_price', 10, 2); // Price per unit
    $table->string('currency', 3)->default('EUR');
    $table->boolean('is_active')->default(true);
    $table->text('notes')->nullable();
    $table->timestamps();
    
    // Ensure one price per role per unit type
    $table->unique(['role', 'unit_type_id']);
    $table->index(['role', 'is_active']);
});
```

---

## Models

### Model: `UnitType`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnitType extends Model
{
    protected $fillable = [
        'code',
        'name',
        'category',
        'is_active',
        'sort_order',
    ];
    
    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
    
    public function customerRolePrices()
    {
        return $this->hasMany(CustomerRoleUnitPrice::class);
    }
    
    /**
     * Get price for specific customer role
     */
    public function getPriceForRole(string $role): ?float
    {
        $priceRecord = $this->customerRolePrices()
            ->where('role', $role)
            ->where('is_active', true)
            ->first();
        
        return $priceRecord?->unit_price;
    }
}
```

### Model: `CustomerRoleUnitPrice`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerRoleUnitPrice extends Model
{
    protected $fillable = [
        'role',
        'unit_type_id',
        'unit_price',
        'currency',
        'is_active',
        'notes',
    ];
    
    protected $casts = [
        'unit_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];
    
    public function unitType()
    {
        return $this->belongsTo(UnitType::class);
    }
    
    /**
     * Calculate total price for quantity
     */
    public function calculateTotal(float $quantity): float
    {
        return $quantity * $this->unit_price;
    }
}
```

### Update `RobawsCustomerCache` Model

```php
// In app/Models/RobawsCustomerCache.php

/**
 * Get all unit prices for this customer's role
 */
public function getUnitPrices()
{
    return CustomerRoleUnitPrice::where('role', $this->role)
        ->where('is_active', true)
        ->with('unitType')
        ->get();
}

/**
 * Get price for specific unit type
 */
public function getPriceForUnit(string $unitTypeCode): ?float
{
    $unitType = UnitType::where('code', $unitTypeCode)->first();
    
    if (!$unitType) {
        return null;
    }
    
    $priceRecord = CustomerRoleUnitPrice::where('role', $this->role)
        ->where('unit_type_id', $unitType->id)
        ->where('is_active', true)
        ->first();
    
    return $priceRecord?->unit_price;
}

/**
 * Calculate price for article
 */
public function calculateArticlePrice(string $unitTypeCode, float $quantity): array
{
    $unitPrice = $this->getPriceForUnit($unitTypeCode);
    
    if ($unitPrice === null) {
        throw new \Exception("No price configured for unit type '{$unitTypeCode}' and role '{$this->role}'");
    }
    
    return [
        'unit_type' => $unitTypeCode,
        'unit_price' => $unitPrice,
        'quantity' => $quantity,
        'total_price' => $unitPrice * $quantity,
    ];
}
```

---

## Seeders

### Seeder: `UnitTypeSeeder`

```php
<?php

namespace Database\Seeders;

use App\Models\UnitType;
use Illuminate\Database\Seeder;

class UnitTypeSeeder extends Seeder
{
    public function run(): void
    {
        $unitTypes = [
            // Containers
            ['code' => '20FT_DV', 'name' => '20ft Dry Van', 'category' => 'container', 'sort_order' => 1],
            ['code' => '20FT_OT', 'name' => '20ft Open Top', 'category' => 'container', 'sort_order' => 2],
            ['code' => '40FT_DV', 'name' => '40ft Dry Van', 'category' => 'container', 'sort_order' => 3],
            ['code' => '40FT_FR', 'name' => '40ft Flat Rack', 'category' => 'container', 'sort_order' => 4],
            ['code' => '40FT_HC', 'name' => '40ft High Cube', 'category' => 'container', 'sort_order' => 5],
            ['code' => '40FT_OT', 'name' => '40ft Open Top', 'category' => 'container', 'sort_order' => 6],
            ['code' => 'TEU', 'name' => 'Twenty-foot Equivalent Unit', 'category' => 'container', 'sort_order' => 7],
            
            // Volume-based
            ['code' => 'CBM', 'name' => 'Cubic Meter', 'category' => 'volume', 'sort_order' => 10],
            ['code' => 'LM', 'name' => 'Linear Meter', 'category' => 'length', 'sort_order' => 11],
            ['code' => 'SQM', 'name' => 'Square Meter', 'category' => 'area', 'sort_order' => 12],
            
            // Weight-based
            ['code' => 'TON', 'name' => 'Ton', 'category' => 'weight', 'sort_order' => 20],
            ['code' => 'W/M', 'name' => 'Weight/Measurement', 'category' => 'weight', 'sort_order' => 21],
            
            // Count-based
            ['code' => 'UNIT', 'name' => 'Unit', 'category' => 'count', 'sort_order' => 30],
            ['code' => 'VEHICLE', 'name' => 'Vehicle', 'category' => 'count', 'sort_order' => 31],
            ['code' => 'TRUCK', 'name' => 'Truck', 'category' => 'count', 'sort_order' => 32],
            ['code' => 'STACKED_UNIT', 'name' => 'Stacked Unit', 'category' => 'count', 'sort_order' => 33],
            
            // Lumpsum/Flat
            ['code' => 'LUMPSUM', 'name' => 'Lumpsum', 'category' => 'lumpsum', 'sort_order' => 40],
            ['code' => 'SHIPMENT', 'name' => 'Shipment', 'category' => 'lumpsum', 'sort_order' => 41],
            ['code' => 'DOC', 'name' => 'Document', 'category' => 'lumpsum', 'sort_order' => 42],
            ['code' => 'FRT', 'name' => 'Freight', 'category' => 'lumpsum', 'sort_order' => 43],
            
            // Time-based
            ['code' => 'DAY', 'name' => 'Day', 'category' => 'time', 'sort_order' => 50],
            ['code' => 'HOUR', 'name' => 'Hour', 'category' => 'time', 'sort_order' => 51],
            
            // Other
            ['code' => 'PERCENT', 'name' => 'Percentage', 'category' => 'other', 'sort_order' => 60],
            ['code' => 'CHASSIS_NR', 'name' => 'Chassis Number', 'category' => 'other', 'sort_order' => 61],
            ['code' => 'CONT', 'name' => 'Container', 'category' => 'other', 'sort_order' => 62],
        ];
        
        foreach ($unitTypes as $unitType) {
            UnitType::updateOrCreate(
                ['code' => $unitType['code']],
                $unitType
            );
        }
    }
}
```

### Seeder: `CustomerRoleUnitPriceSeeder`

```php
<?php

namespace Database\Seeders;

use App\Models\CustomerRoleUnitPrice;
use App\Models\UnitType;
use Illuminate\Database\Seeder;

class CustomerRoleUnitPriceSeeder extends Seeder
{
    public function run(): void
    {
        // Define price matrix: role => [unit_code => price]
        $priceMatrix = [
            'FORWARDER' => [
                '20FT_DV' => 1500.00,
                '40FT_DV' => 2000.00,
                '40FT_HC' => 2200.00,
                'CBM' => 8.00,
                'LM' => 12.00,
                'TON' => 45.00,
                'W/M' => 40.00,
                'UNIT' => 25.00,
                'LUMPSUM' => 250.00,
                'VEHICLE' => 300.00,
                'DAY' => 150.00,
                'HOUR' => 25.00,
            ],
            'POV' => [
                '20FT_DV' => 1800.00,
                '40FT_DV' => 2400.00,
                '40FT_HC' => 2600.00,
                'CBM' => 10.00,
                'LM' => 15.00,
                'TON' => 55.00,
                'W/M' => 50.00,
                'UNIT' => 30.00,
                'LUMPSUM' => 300.00,
                'VEHICLE' => 350.00,
                'DAY' => 180.00,
                'HOUR' => 30.00,
            ],
            'BROKER' => [
                '20FT_DV' => 1400.00,
                '40FT_DV' => 1900.00,
                '40FT_HC' => 2100.00,
                'CBM' => 7.50,
                'LM' => 11.00,
                'TON' => 42.00,
                'W/M' => 38.00,
                'UNIT' => 22.00,
                'LUMPSUM' => 230.00,
                'VEHICLE' => 280.00,
                'DAY' => 140.00,
                'HOUR' => 23.00,
            ],
            'SHIPPING LINE' => [
                '20FT_DV' => 1200.00,
                '40FT_DV' => 1700.00,
                '40FT_HC' => 1900.00,
                'CBM' => 6.00,
                'LM' => 9.00,
                'TON' => 35.00,
                'W/M' => 32.00,
                'UNIT' => 18.00,
                'LUMPSUM' => 200.00,
                'VEHICLE' => 250.00,
                'DAY' => 120.00,
                'HOUR' => 20.00,
            ],
            'CAR DEALER' => [
                '20FT_DV' => 2000.00,
                '40FT_DV' => 2600.00,
                '40FT_HC' => 2800.00,
                'CBM' => 12.00,
                'LM' => 18.00,
                'TON' => 60.00,
                'W/M' => 55.00,
                'UNIT' => 35.00,
                'LUMPSUM' => 350.00,
                'VEHICLE' => 400.00,
                'DAY' => 200.00,
                'HOUR' => 35.00,
            ],
            'LUXURY CAR DEALER' => [
                '20FT_DV' => 2500.00,
                '40FT_DV' => 3200.00,
                '40FT_HC' => 3500.00,
                'CBM' => 15.00,
                'LM' => 22.00,
                'TON' => 75.00,
                'W/M' => 70.00,
                'UNIT' => 45.00,
                'LUMPSUM' => 450.00,
                'VEHICLE' => 500.00,
                'DAY' => 250.00,
                'HOUR' => 45.00,
            ],
            'TOURIST' => [
                '20FT_DV' => 3000.00,
                '40FT_DV' => 4000.00,
                '40FT_HC' => 4500.00,
                'CBM' => 20.00,
                'LM' => 30.00,
                'TON' => 100.00,
                'W/M' => 90.00,
                'UNIT' => 60.00,
                'LUMPSUM' => 600.00,
                'VEHICLE' => 700.00,
                'DAY' => 300.00,
                'HOUR' => 50.00,
            ],
        ];
        
        foreach ($priceMatrix as $role => $unitPrices) {
            foreach ($unitPrices as $unitCode => $price) {
                $unitType = UnitType::where('code', $unitCode)->first();
                
                if ($unitType) {
                    CustomerRoleUnitPrice::updateOrCreate(
                        [
                            'role' => $role,
                            'unit_type_id' => $unitType->id,
                        ],
                        [
                            'unit_price' => $price,
                            'currency' => 'EUR',
                            'is_active' => true,
                        ]
                    );
                }
            }
        }
    }
}
```

---

## Pricing Service (Revised)

```php
<?php

namespace App\Services\Pricing;

use App\Models\RobawsArticleCache;
use App\Models\RobawsCustomerCache;
use App\Models\UnitType;
use Illuminate\Support\Collection;

class CustomerPricingService
{
    /**
     * Calculate prices for quote
     */
    public function calculateQuote(
        RobawsCustomerCache $customer,
        array $quoteItems // [{article_id, quantity, unit_type}, ...]
    ): array {
        $pricedItems = [];
        $totalPrice = 0;
        
        foreach ($quoteItems as $item) {
            $article = RobawsArticleCache::find($item['article_id']);
            $unitTypeCode = $item['unit_type'];
            $quantity = $item['quantity'];
            
            $unitPrice = $customer->getPriceForUnit($unitTypeCode);
            
            if ($unitPrice === null) {
                throw new \Exception(
                    "No price configured for '{$unitTypeCode}' and role '{$customer->role}'"
                );
            }
            
            $itemTotal = $unitPrice * $quantity;
            
            $pricedItems[] = [
                'article_id' => $article->id,
                'article_name' => $article->article_name,
                'article_number' => $article->article_number,
                'category' => $article->category,
                'unit_type' => $unitTypeCode,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $itemTotal,
            ];
            
            $totalPrice += $itemTotal;
        }
        
        return [
            'customer' => [
                'id' => $customer->robaws_client_id,
                'name' => $customer->name,
                'role' => $customer->role,
            ],
            'items' => $pricedItems,
            'summary' => [
                'total_price' => $totalPrice,
                'item_count' => count($pricedItems),
                'currency' => 'EUR',
            ],
        ];
    }
}
```

---

## Filament Resources

### Resource: `UnitTypeResource`

Manage unit types (CBM, LM, 20ft DV, etc.)

### Resource: `CustomerRoleUnitPriceResource`

**Price Matrix UI** - Configure prices per role per unit type.

**Table View**:
```
┌──────────────────────────────────────────────────────────────┐
│ Customer Role Unit Prices         Filter by: [Role] [Unit]  │
├──────────────────────────────────────────────────────────────┤
│ Role        Unit Type   Unit Price   Currency   Active       │
│ FORWARDER   20ft DV     €1,500.00    EUR        ✅           │
│ FORWARDER   40ft DV     €2,000.00    EUR        ✅           │
│ FORWARDER   CBM         €8.00        EUR        ✅           │
│ POV         20ft DV     €1,800.00    EUR        ✅           │
│ BROKER      LM          €11.00       EUR        ✅           │
└──────────────────────────────────────────────────────────────┘
```

**Matrix View** (Optional advanced feature):
```
┌────────────────────────────────────────────────────────────┐
│           20ft DV   40ft DV   CBM    LM     TON   LUMPSUM  │
├────────────────────────────────────────────────────────────┤
│ FORWARDER  €1,500   €2,000    €8    €12    €45   €250     │
│ POV        €1,800   €2,400    €10   €15    €55   €300     │
│ BROKER     €1,400   €1,900    €7.50 €11    €42   €230     │
│ SHIPPING   €1,200   €1,700    €6    €9     €35   €200     │
│ TOURIST    €3,000   €4,000    €20   €30    €100  €600     │
└────────────────────────────────────────────────────────────┘
```

---

## Example Quote Flow

**1. Create Intake**:
- Customer: Aeon Shipping LLC (Role: FORWARDER)

**2. Select Articles**:
- Seafreight RORO (Unit: 20ft DV, Qty: 2)
- Customs Clearance (Unit: Lumpsum, Qty: 1)
- Terminal Handling (Unit: CBM, Qty: 15)

**3. System Calculates**:
```
FORWARDER Prices:
- 20ft DV: €1,500 × 2 = €3,000
- Lumpsum: €250 × 1 = €250
- CBM: €8 × 15 = €120

Total: €3,370
```

**4. Generate Quote** → Export to Robaws

---

## Updated Implementation Checklist

**Phase 1: Customer Sync** (unchanged)
- [ ] Create `robaws_customers_cache` table & model
- [ ] Create `RobawsCustomerSyncService`
- [ ] Create sync command and webhooks
- [ ] Create `RobawsCustomerResource` in Filament

**Phase 2: Unit-Based Pricing**
- [ ] Create `unit_types` table
- [ ] Create `customer_role_unit_prices` table
- [ ] Create `UnitType` model
- [ ] Create `CustomerRoleUnitPrice` model
- [ ] Create `UnitTypeSeeder` (29 unit types)
- [ ] Create `CustomerRoleUnitPriceSeeder` (price matrix)
- [ ] Run seeders

**Phase 3: Filament Resources**
- [ ] Create `UnitTypeResource`
- [ ] Create `CustomerRoleUnitPriceResource`
- [ ] Add price matrix view (optional)

**Phase 4: Pricing Logic**
- [ ] Add `getPriceForUnit()` to `RobawsCustomerCache`
- [ ] Create `CustomerPricingService`
- [ ] Update quote generation to use unit-based pricing

**Phase 5: Testing**
- [ ] Test FORWARDER pricing: 20ft DV = €1,500
- [ ] Test different roles have different unit prices
- [ ] Test multi-unit quote calculation
- [ ] Test missing price configuration error handling

---

## Price Matrix Summary (Example)

| Role | 20ft DV | 40ft DV | CBM | LM | TON | LUMPSUM |
|------|---------|---------|-----|----|----|---------|
| **TOURIST** | €3,000 | €4,000 | €20 | €30 | €100 | €600 |
| **LUXURY CAR** | €2,500 | €3,200 | €15 | €22 | €75 | €450 |
| **CAR DEALER** | €2,000 | €2,600 | €12 | €18 | €60 | €350 |
| **POV** | €1,800 | €2,400 | €10 | €15 | €55 | €300 |
| **FORWARDER** | €1,500 | €2,000 | €8 | €12 | €45 | €250 |
| **BROKER** | €1,400 | €1,900 | €7.50 | €11 | €42 | €230 |
| **SHIPPING LINE** | €1,200 | €1,700 | €6 | €9 | €35 | €200 |

---

**This is the CORRECT pricing model. Ready to implement?**

