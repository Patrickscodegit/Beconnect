# Nested Commodity Items Feature Audit

## Date: 2025-12-02

## Requirement

Add support for nested commodity items with checkboxes:
- **For vehicles, machinery, and boats**: "Loaded with" checkbox
- **For trucks**: "Connected to" checkbox (in addition to "Loaded with")
- When checked, allow adding another commodity item nested inside the parent commodity item

## Current Structure Analysis

### Database Schema

**Table:** `quotation_commodity_items`

Current structure is **flat** - no parent-child relationship:
- `id` (primary key)
- `quotation_request_id` (foreign key)
- `line_number` (sequential number)
- `commodity_type` (vehicles, machinery, boat, general_cargo)
- Various fields for dimensions, weight, etc.
- **No `parent_id` or `parent_item_id` field**

### Model Structure

**File:** `app/Models/QuotationCommodityItem.php`

- No relationships to other commodity items
- Only relationship: `quotationRequest()` (BelongsTo)
- No methods for handling nested items

### UI Structure

**File:** `resources/views/livewire/commodity-items-repeater.blade.php`

- Flat list of items
- Each item rendered independently
- No nesting or indentation
- Uses `@include` to load commodity-specific forms:
  - `livewire.commodity-forms.vehicles`
  - `livewire.commodity-forms.machinery`
  - `livewire.commodity-forms.boat`
  - `livewire.commodity-forms.general_cargo`

**File:** `app/Livewire/CommodityItemsRepeater.php`

- Manages flat array of items: `public $items = []`
- `addItem()` method adds items to flat array
- `removeItem()` removes items from flat array
- No support for nested structures

### Commodity Forms

**Vehicles Form:** `resources/views/livewire/commodity-forms/vehicles.blade.php`
- Fields: category, make, type_model, condition, year, fuel_type, dimensions, weight, quantity
- **No "Loaded with" checkbox**
- **No "Connected to" checkbox**

**Machinery Form:** `resources/views/livewire/commodity-forms/machinery.blade.php`
- Similar structure to vehicles
- **No "Loaded with" checkbox**

**Boat Form:** `resources/views/livewire/commodity-forms/boat.blade.php`
- Similar structure
- **No "Loaded with" checkbox**

---

## Proposed Solution

### Option A: Self-Referential Relationship (Recommended)

**Database Changes:**
1. Add `parent_item_id` column to `quotation_commodity_items` table
   - `nullable()` - null means top-level item
   - Foreign key to `quotation_commodity_items.id`
   - Index for performance

2. Add `is_loaded_with` boolean field
   - Indicates if this item is loaded inside another item
   - Alternative: derive from `parent_item_id IS NOT NULL`

3. Add `is_connected_to` boolean field (for trucks)
   - Indicates if truck is connected to another item
   - Only relevant for trucks

**Model Changes:**
1. Add `parentItem()` relationship (BelongsTo)
2. Add `childItems()` relationship (HasMany)
3. Add scopes: `topLevel()`, `nested()`, `childrenOf($parentId)`
4. Update `$fillable` to include new fields

**UI Changes:**
1. Add checkboxes to commodity forms:
   - "Loaded with" checkbox (vehicles, machinery, boats)
   - "Connected to" checkbox (trucks only)

2. When checkbox is checked:
   - Show nested commodity item form below parent
   - Allow selecting commodity type for nested item
   - Render nested item with indentation/visual hierarchy

3. Update `CommodityItemsRepeater`:
   - Support nested item structure in `$items` array
   - Update `addItem()` to accept `parentIndex` parameter
   - Update `removeItem()` to handle cascading deletes
   - Update rendering to show nested items with indentation

**Data Structure:**
```php
$items = [
    [
        'id' => 1,
        'commodity_type' => 'vehicles',
        'category' => 'truck',
        'is_loaded_with' => true,
        'is_connected_to' => false,
        'children' => [
            [
                'id' => 2,
                'commodity_type' => 'machinery',
                'parent_item_id' => 1,
                'is_loaded_with' => true,
            ]
        ]
    ]
]
```

### Option B: Separate Table for Nested Items

**Database Changes:**
1. Create new table `quotation_commodity_item_nested`
   - `id`
   - `parent_item_id` (FK to quotation_commodity_items)
   - `child_item_id` (FK to quotation_commodity_items)
   - `relationship_type` (enum: 'loaded_with', 'connected_to')
   - `created_at`, `updated_at`

**Pros:**
- Cleaner separation
- Supports multiple relationships
- Can have multiple nested items per parent

**Cons:**
- More complex queries
- Additional join required
- More complex UI logic

### Option C: JSON Field for Nested Items

**Database Changes:**
1. Add `nested_items` JSON column to `quotation_commodity_items`
2. Store nested items as JSON array

**Pros:**
- No schema changes for relationships
- Flexible structure

**Cons:**
- Harder to query
- No referential integrity
- More complex to manage

---

## Recommended Implementation: Option A

### Database Migration

```php
Schema::table('quotation_commodity_items', function (Blueprint $table) {
    // Add parent relationship
    $table->foreignId('parent_item_id')
        ->nullable()
        ->after('quotation_request_id')
        ->constrained('quotation_commodity_items')
        ->onDelete('cascade');
    
    // Add relationship type flags
    $table->boolean('is_loaded_with')->default(false)->after('parent_item_id');
    $table->boolean('is_connected_to')->default(false)->after('is_loaded_with');
    
    // Add index for performance
    $table->index('parent_item_id');
    $table->index(['quotation_request_id', 'parent_item_id']);
});
```

### Model Updates

```php
// QuotationCommodityItem.php

protected $fillable = [
    // ... existing fields
    'parent_item_id',
    'is_loaded_with',
    'is_connected_to',
];

public function parentItem(): BelongsTo
{
    return $this->belongsTo(QuotationCommodityItem::class, 'parent_item_id');
}

public function childItems(): HasMany
{
    return $this->hasMany(QuotationCommodityItem::class, 'parent_item_id');
}

public function scopeTopLevel($query)
{
    return $query->whereNull('parent_item_id');
}

public function scopeNested($query)
{
    return $query->whereNotNull('parent_item_id');
}
```

### UI Implementation

**1. Add Checkboxes to Forms**

**Vehicles Form:**
```blade
{{-- Loaded with checkbox --}}
<div class="lg:col-span-3">
    <label class="flex items-center">
        <input type="checkbox" 
            wire:model.live="items.{{ $index }}.is_loaded_with"
            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
        <span class="ml-2 text-sm font-medium text-gray-700">
            Loaded with cargo/item
        </span>
    </label>
</div>

{{-- Connected to checkbox (only for trucks) --}}
@if($item['category'] === 'truck')
<div class="lg:col-span-3">
    <label class="flex items-center">
        <input type="checkbox" 
            wire:model.live="items.{{ $index }}.is_connected_to"
            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
        <span class="ml-2 text-sm font-medium text-gray-700">
            Connected to trailer/equipment
        </span>
    </label>
</div>
@endif

{{-- Nested item form (shown when checkbox is checked) --}}
@if($item['is_loaded_with'] ?? false)
    <div class="lg:col-span-3 mt-4 pl-6 border-l-4 border-blue-300 bg-blue-50 p-4 rounded">
        <h6 class="font-semibold text-blue-900 mb-3">
            <i class="fas fa-box mr-2"></i>Loaded Item
        </h6>
        {{-- Render nested commodity item form --}}
        @include('livewire.commodity-forms.nested-item', ['parentIndex' => $index, 'nestedIndex' => $index . '_nested'])
    </div>
@endif
```

**2. Update CommodityItemsRepeater Component**

```php
// Add method to add nested item
public function addNestedItem($parentIndex)
{
    $nestedId = uniqid('nested_');
    $this->items[$parentIndex]['children'][] = [
        'id' => $nestedId,
        'parent_item_id' => $this->items[$parentIndex]['id'] ?? null,
        'is_loaded_with' => true,
        'commodity_type' => '',
        // ... other fields
    ];
}

// Update saveItemToDatabase to handle nested items
public function saveItemToDatabase($index)
{
    // Save parent item
    $item = $this->items[$index];
    // ... existing save logic
    
    // Save nested items if any
    if (!empty($item['children'] ?? [])) {
        foreach ($item['children'] as $nestedIndex => $nestedItem) {
            $nestedItem['parent_item_id'] = $savedItem->id;
            $nestedItem['quotation_request_id'] = $this->quotationId;
            QuotationCommodityItem::create($nestedItem);
        }
    }
}
```

**3. Update View Rendering**

```blade
@foreach($items as $index => $item)
    {{-- Render parent item --}}
    <div class="commodity-item">
        {{-- Parent item form --}}
        @include('livewire.commodity-forms.' . $item['commodity_type'], ['index' => $index])
        
        {{-- Render nested items with indentation --}}
        @if(!empty($item['children'] ?? []))
            <div class="ml-8 mt-4 border-l-2 border-gray-300 pl-4">
                @foreach($item['children'] as $nestedIndex => $nestedItem)
                    <div class="nested-item">
                        {{-- Nested item form --}}
                        @include('livewire.commodity-forms.' . $nestedItem['commodity_type'], 
                            ['index' => $nestedIndex, 'isNested' => true])
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endforeach
```

---

## Business Logic Considerations

### 1. Quantity Calculation

**Question:** Should nested items count toward parent's quantity?
- Option A: Nested items are separate, don't affect parent quantity
- Option B: Nested items are part of parent, affect total quantity

**Recommendation:** Option A - nested items are separate entities

### 2. Article Matching

**Question:** Should nested items generate separate articles?
- Option A: Yes, nested items can have their own articles
- Option B: No, nested items are part of parent's article

**Recommendation:** Option A - nested items can have separate articles

### 3. Pricing

**Question:** How should nested items affect pricing?
- Option A: Separate pricing for nested items
- Option B: Nested items included in parent pricing

**Recommendation:** Option A - separate pricing, but can be linked

### 4. Dimensions/Weight

**Question:** Should nested items affect parent's dimensions/weight?
- Option A: No, separate calculations
- Option B: Yes, add to parent's total

**Recommendation:** Option A - separate calculations, but can show combined totals

---

## Implementation Phases

### Phase 1: Database & Model
1. Create migration for `parent_item_id`, `is_loaded_with`, `is_connected_to`
2. Update `QuotationCommodityItem` model with relationships
3. Add scopes and helper methods
4. Test relationships

### Phase 2: UI - Checkboxes
1. Add "Loaded with" checkbox to vehicles, machinery, boats forms
2. Add "Connected to" checkbox to trucks (vehicles with category=truck)
3. Add conditional rendering for nested item form
4. Test checkbox functionality

### Phase 3: UI - Nested Item Forms
1. Create nested item form component
2. Update `CommodityItemsRepeater` to handle nested items
3. Update view rendering with indentation
4. Test nested item creation

### Phase 4: Data Persistence
1. Update `saveItemToDatabase()` to save nested items
2. Update `loadItemsFromDatabase()` to load nested items
3. Handle cascading deletes
4. Test save/load cycle

### Phase 5: Article Integration
1. Ensure nested items can have articles
2. Update article matching logic if needed
3. Test article assignment for nested items

---

## Testing Scenarios

### Test Case 1: Basic Nested Item
- Add truck commodity item
- Check "Loaded with" checkbox
- Add machinery item as nested
- Verify nested item is saved with `parent_item_id`
- Verify nested item appears indented in UI

### Test Case 2: Truck with Connection
- Add truck commodity item
- Check "Connected to" checkbox
- Add trailer/equipment as nested
- Verify both "Loaded with" and "Connected to" can be checked

### Test Case 3: Multiple Nested Items
- Add truck
- Check "Loaded with"
- Add machinery item
- Add another nested item (general cargo)
- Verify both nested items are saved

### Test Case 4: Remove Parent Item
- Add truck with nested item
- Remove truck
- Verify nested item is also removed (cascade delete)

### Test Case 5: Articles for Nested Items
- Add truck with nested machinery
- Add articles for both
- Verify articles are correctly assigned
- Verify pricing calculations

---

## Questions to Clarify

1. **Can nested items have their own nested items?** (Multi-level nesting)
   - Recommendation: Support up to 2 levels (parent → child)

2. **Can a nested item be "loaded with" something?**
   - Recommendation: Yes, but limit to 2 levels total

3. **Should "Connected to" be separate from "Loaded with"?**
   - Recommendation: Yes, they are different relationships

4. **How should nested items appear in reports/exports?**
   - Recommendation: Show with indentation, clearly marked as nested

5. **Should nested items affect CBM/LM calculations?**
   - Recommendation: Separate calculations, but show combined totals optionally

---

## Files to Modify

1. **Database:**
   - Create migration: `add_nested_commodity_items_support.php`

2. **Models:**
   - `app/Models/QuotationCommodityItem.php`

3. **Livewire Components:**
   - `app/Livewire/CommodityItemsRepeater.php`

4. **Views:**
   - `resources/views/livewire/commodity-forms/vehicles.blade.php`
   - `resources/views/livewire/commodity-forms/machinery.blade.php`
   - `resources/views/livewire/commodity-forms/boat.blade.php`
   - `resources/views/livewire/commodity-items-repeater.blade.php`
   - Create: `resources/views/livewire/commodity-forms/nested-item.blade.php` (optional)

---

## Risk Assessment

**Low Risk:**
- Database migration is additive (nullable fields)
- Backward compatible (existing items remain top-level)

**Medium Risk:**
- UI complexity increases
- Need to handle edge cases (removing parent, etc.)

**High Risk:**
- Article matching logic might need updates
- Pricing calculations might be affected

**Mitigation:**
- Start with simple implementation (single level nesting)
- Add comprehensive tests
- Gradual rollout with feature flag

