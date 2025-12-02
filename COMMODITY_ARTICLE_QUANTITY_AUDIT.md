# Commodity Item to Article Quantity Mapping Audit

## Date: 2025-12-02

## Problem Statement

User has:
- 2 cars as separate commodity items
- 1 small van as a commodity item
- Total: 3 commodity items

**Current Behavior:**
All articles show "Qty: 3" (sum of all commodity items):
- Car Seafreight: Qty: 3 × €892.00 = €2,676.00
- Admin Fee: Qty: 3 × €75.00 = €247.50
- Small Van Seafreight: Qty: 3 × €1,021.00 = €3,063.00

**Expected Behavior:**
Articles should show quantity based on matching commodity types:
- Car Seafreight: Qty: 2 × €892.00 = €1,784.00 (only counts cars)
- Small Van Seafreight: Qty: 1 × €1,021.00 = €1,021.00 (only counts small vans)
- Admin Fee: Qty: 3 × €75.00 = €225.00 (counts all items, or per parent article)

---

## Root Cause Analysis

### Current Implementation

In `QuotationCommodityItem::saved` event (line 184):
```php
$totalCommodityQuantity = $quotation->commodityItems->sum('quantity') ?? 0;
```

Then for non-LM articles (line 207-210):
```php
if ($unitType !== 'LM') {
    // Update quantity to total commodity item quantity
    $article->quantity = (int) $totalCommodityQuantity;
}
```

**Problem:** This sums ALL commodity items, regardless of whether they match the article's commodity type.

### Article-Commodity Type Relationship

From code analysis:
1. **Articles have `commodity_type` field** (RobawsArticleCache)
   - Example: "Car", "Small Van", "Truck", etc.
2. **Commodity items have `commodity_type` field** (QuotationCommodityItem)
   - Example: "vehicles" (with `category` like "car", "small_van")
3. **Mapping happens in SmartArticleSelectionService:**
   - `normalizeCommodityTypes()` maps internal types to Robaws types
   - `getVehicleCategoryMappings()` maps vehicle categories:
     - 'car' → ['CAR']
     - 'small_van' → ['SMALL VAN']

### Current Article-Item Relationship

**No direct link exists** between `QuotationRequestArticle` and `QuotationCommodityItem`. Articles are linked to quotations, not to specific commodity items.

---

## Solution Options

### Option A: Match Articles to Commodity Types (Recommended)

**Approach:**
- For each article, find matching commodity items based on commodity type
- Calculate quantity as sum of matching commodity items only
- Handle surcharges/child articles separately

**Implementation:**
1. Get article's `commodity_type` from `RobawsArticleCache`
2. Map internal commodity item types to Robaws article types
3. Sum quantities of matching commodity items
4. Update article quantity accordingly

**Pros:**
- Accurate quantity per article type
- Matches user expectation
- Handles multiple commodity types correctly

**Cons:**
- Requires commodity type mapping logic
- Need to handle edge cases (null commodity_type, etc.)

### Option B: Link Articles to Specific Commodity Items

**Approach:**
- Add `commodity_item_id` or `commodity_item_ids` to `QuotationRequestArticle`
- When article is added, link it to the commodity item(s) it applies to
- Calculate quantity from linked items only

**Pros:**
- Explicit relationship
- Very accurate
- Can handle complex scenarios

**Cons:**
- Requires database migration
- More complex to implement
- Breaking change for existing data

### Option C: One Article Per Commodity Item

**Approach:**
- When adding article, create one `QuotationRequestArticle` per matching commodity item
- Each article has quantity = 1 (or the item's quantity)
- Display shows multiple lines for same article

**Pros:**
- Very clear relationship
- Easy to understand

**Cons:**
- Many duplicate article lines
- Complex to manage
- Not what user expects (they want one line per article type)

---

## Recommended Solution: Option A

### Implementation Details

**Step 1: Create Commodity Type Mapper**

Create a service or method that maps:
- Internal commodity item types → Robaws article commodity types
- Handle vehicle categories (car, small_van, etc.)
- Handle other commodity types (machinery, boat, general_cargo)

**Step 2: Update Quantity Calculation**

In `QuotationCommodityItem::saved` event:
1. For each article, get its `commodity_type` from `articleCache`
2. Find matching commodity items using the mapper
3. Sum quantities of matching items
4. Update article quantity

**Step 3: Handle Special Cases**

- **Surcharges/Child Articles:** 
  - If article is a child of a parent article, use parent's quantity
  - Or sum all commodity items (current behavior might be correct)
- **Articles without commodity_type:**
  - Default to sum of all items (fallback behavior)
- **LM Articles:**
  - Keep existing logic (calculated from dimensions)

### Code Structure

```php
// In QuotationCommodityItem::saved event
foreach ($allArticles as $article) {
    $unitType = strtoupper(trim($article->unit_type ?? ''));
    
    if ($unitType === 'LM') {
        // Keep existing LM calculation logic
        $article->save();
        continue;
    }
    
    // For non-LM articles, calculate quantity based on matching commodity items
    $articleCommodityType = $article->articleCache->commodity_type ?? null;
    
    if ($articleCommodityType) {
        // Find matching commodity items
        $matchingItems = $this->findMatchingCommodityItems(
            $quotation->commodityItems,
            $articleCommodityType
        );
        
        // Sum quantities of matching items
        $article->quantity = (int) $matchingItems->sum('quantity');
    } else {
        // Fallback: sum all items (for articles without commodity_type)
        $article->quantity = (int) $totalCommodityQuantity;
    }
    
    $article->save();
}
```

### Commodity Type Mapping

Use existing logic from `SmartArticleSelectionService::normalizeCommodityTypes()`:

```php
protected function findMatchingCommodityItems($commodityItems, $articleCommodityType): Collection
{
    return $commodityItems->filter(function ($item) use ($articleCommodityType) {
        // Map internal commodity type to Robaws types
        $mappedTypes = $this->normalizeCommodityTypes($item);
        
        // Check if article's commodity type matches any mapped type
        return in_array(strtoupper($articleCommodityType), 
                      array_map('strtoupper', $mappedTypes));
    });
}
```

---

## Testing Scenarios

### Test Case 1: Multiple Commodity Types
- **Setup:** 2 cars, 1 small van
- **Expected:**
  - Car article: Qty: 2
  - Small Van article: Qty: 1
  - Admin fee: Qty: 3 (or per parent)

### Test Case 2: Same Commodity Type, Different Quantities
- **Setup:** Car 1 (qty: 2), Car 2 (qty: 3)
- **Expected:** Car article: Qty: 5

### Test Case 3: LM Articles
- **Setup:** 2 cars with dimensions
- **Expected:** LM article quantity calculated from dimensions (unchanged)

### Test Case 4: Articles Without Commodity Type
- **Setup:** Article with null commodity_type
- **Expected:** Falls back to sum of all items

### Test Case 5: Child Articles (Surcharges)
- **Setup:** Admin fee as child of Car article
- **Expected:** Admin fee quantity = parent article quantity (or all items)

---

## Migration Considerations

**No database migration needed** - this is a logic change only.

**Backward Compatibility:**
- Existing quotations will recalculate on next commodity item change
- No data loss
- Behavior improves automatically

---

## Files to Modify

1. **`app/Models/QuotationCommodityItem.php`**
   - Update `saved` event to match articles to commodity types
   - Add helper method `findMatchingCommodityItems()`
   - Reuse `normalizeCommodityTypes()` logic from SmartArticleSelectionService

2. **`app/Services/SmartArticleSelectionService.php`**
   - Extract `normalizeCommodityTypes()` to a shared service or trait
   - Or make it public/static so it can be reused

3. **Optional: Create `app/Services/Quotation/CommodityTypeMapper.php`**
   - Centralize commodity type mapping logic
   - Reusable across services

---

## Risk Assessment

**Low Risk:**
- Logic change only, no database changes
- Existing data remains valid
- Can be tested incrementally

**Medium Risk:**
- Commodity type mapping might have edge cases
- Need to handle null/empty commodity types gracefully

**Mitigation:**
- Add comprehensive logging
- Fallback to current behavior (sum all) if mapping fails
- Test with various commodity type combinations

---

## Implementation Plan

### Phase 1: Extract Commodity Type Mapping
1. Extract `normalizeCommodityTypes()` to shared location
2. Create helper method `findMatchingCommodityItems()`
3. Test mapping logic in isolation

### Phase 2: Update Quantity Calculation
1. Update `QuotationCommodityItem::saved` event
2. Implement matching logic for non-LM articles
3. Keep LM articles unchanged
4. Add logging for debugging

### Phase 3: Handle Special Cases
1. Handle surcharges/child articles
2. Handle articles without commodity_type
3. Add fallback behavior

### Phase 4: Testing
1. Test with multiple commodity types
2. Test quantity changes
3. Test edge cases
4. Verify pricing calculations

