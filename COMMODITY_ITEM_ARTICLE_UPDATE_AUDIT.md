# Commodity Item and Article Update Audit

## Date: 2025-12-01

## Issues Identified

### Issue 1: Same Article Cannot Be Added Multiple Times

**Current Behavior:**
- When a user adds a second car as a new commodity item, the same article (e.g., article 1301) is suggested again
- However, when the user tries to add it, `SmartArticleSelector::selectArticle()` checks if the article is already added (line 114-117)
- If the article exists, it returns early and prevents adding it again
- This prevents users from adding the same article for multiple commodity items

**Expected Behavior:**
- When a new commodity item is added (e.g., second car), the same article should be addable again
- Each commodity item should be able to have its own article line item
- This allows proper tracking and pricing per commodity item

**Root Cause:**
- `SmartArticleSelector::selectArticle()` has a check: `if ($alreadyAdded) { return; }`
- This prevents duplicate articles even when they should be allowed for different commodity items

**Files Affected:**
- `app/Livewire/SmartArticleSelector.php` (line 114-117)

---

### Issue 2: Quantity Changes Not Updating Article Calculations

**Current Behavior:**
- When a user increases quantity within a commodity item (e.g., from 1 to 2), the change is detected and logged
- However, the article's calculation breakdown shows the old quantity
- For LM articles: Only LM articles are recalculated when commodity item quantity changes (see `QuotationCommodityItem::saved` event, line 183-184)
- For non-LM articles: The quantity shown is `$article->pivot->quantity` which is stored in `QuotationRequestArticle` and is NOT automatically updated when commodity item quantity changes

**Expected Behavior:**
- When commodity item quantity changes from 1 to 2, the linked article should:
  1. Show "Qty: 2" in the calculation breakdown
  2. Update the pricing accordingly (2 × unit_price = new subtotal)
  3. Recalculate quotation totals

**Root Cause:**
- `QuotationCommodityItem::saved` event only recalculates LM articles (line 183-184)
- Non-LM articles use `QuotationRequestArticle::quantity` which is not synced with commodity item quantity
- The view shows `$article->pivot->quantity` which comes from `QuotationRequestArticle`, not from commodity items

**Files Affected:**
- `app/Models/QuotationCommodityItem.php` (line 171-230) - Only recalculates LM articles
- `app/Models/QuotationRequestArticle.php` - Quantity field is not updated from commodity items
- `resources/views/livewire/customer/quotation-creator.blade.php` (line 493-497) - Shows pivot quantity

---

## Log Analysis

From local logs (`storage/logs/laravel.log`):

```
[2025-12-01 20:37:39] local.INFO: CommodityItemsRepeater::createItemInDatabase() created item
  {"item_id":48,"quotation_id":68,"commodity_type":"vehicles","temp_id":"temp_692dfc8f7cb80"}

[2025-12-01 20:37:39] local.INFO: Smart Match Score Calculation
  {"quotation_id":68,"article_id":1301,...}
  # Article 1301 is suggested again for the second commodity item

[2025-12-01 20:37:54] local.INFO: CommodityItemsRepeater: quantity changed
  {"index":0,"old_quantity":"2","new_quantity":"2","item_id":47,"quotation_id":68}
  # Note: old_quantity shows "2" but should show previous value (likely "1")

[2025-12-01 20:37:54] local.INFO: QuotationCommodityItem saved - recalculating LM articles
  {"commodity_item_id":47,"quotation_request_id":68,"lm_articles_count":0,...}

[2025-12-01 20:37:54] local.WARNING: No LM articles found for recalculation
  {"quotation_id":68,"all_articles":{"85":"lumpsum","86":"shipm."}}
  # Articles 85 and 86 are not LM type, so they're not recalculated
```

**Key Observations:**
1. Article 1301 is suggested again when second commodity item is added (correct)
2. Quantity change is detected (correct)
3. Only LM articles are recalculated (incorrect - should recalculate all articles)
4. Articles 85 and 86 are "lumpsum" and "shipm." unit types, not LM, so they're not recalculated

---

## Proposed Solutions

### Solution 1: Allow Same Article to Be Added Multiple Times

**Option A: Remove Duplicate Check (Recommended)**
- Remove the `alreadyAdded` check in `SmartArticleSelector::selectArticle()`
- Allow the same article to be added multiple times
- Each addition creates a new `QuotationRequestArticle` record
- This allows proper tracking per commodity item

**Option B: Update Existing Article Instead**
- Keep the duplicate check but update the existing article's quantity
- This would merge articles, which might not be desired if commodity items have different dimensions

**Recommendation: Option A** - More flexible and allows better tracking

---

### Solution 2: Recalculate All Articles When Commodity Item Quantity Changes

**Changes Required:**

1. **Update `QuotationCommodityItem::saved` event:**
   - Currently only recalculates LM articles (line 183-184)
   - Should recalculate ALL articles, not just LM articles
   - For non-LM articles, update `QuotationRequestArticle::quantity` to match commodity item quantity

2. **Update `QuantityCalculationService`:**
   - For non-LM articles, the quantity should be the sum of all commodity item quantities
   - Or, if articles are linked to specific commodity items, use that item's quantity

3. **Update View:**
   - Ensure the view shows the correct quantity from `QuotationRequestArticle`
   - For LM articles, show the calculated LM quantity
   - For non-LM articles, show the commodity item quantity

**Implementation Details:**

For non-LM articles, we need to determine:
- Should quantity be the sum of all commodity item quantities?
- Or should each commodity item have its own article line?

Based on Issue 1 solution (allowing duplicate articles), each commodity item should have its own article line, so:
- For non-LM articles: `quantity = commodity_item.quantity`
- For LM articles: `quantity = calculated LM from all commodity items` (current behavior is correct)

---

## Testing Checklist

### Test Case 1: Add Same Article for Multiple Commodity Items
- [ ] Add first car as commodity item
- [ ] Add article 1301
- [ ] Add second car as commodity item
- [ ] Verify article 1301 is suggested again
- [ ] Add article 1301 again
- [ ] Verify both article lines appear in "Selected Services"
- [ ] Verify each has correct pricing

### Test Case 2: Update Commodity Item Quantity
- [ ] Add commodity item with quantity = 1
- [ ] Add article (non-LM type, e.g., "lumpsum")
- [ ] Verify article shows "Qty: 1"
- [ ] Change commodity item quantity to 2
- [ ] Verify article shows "Qty: 2"
- [ ] Verify pricing updates: 2 × unit_price = new subtotal
- [ ] Verify quotation total updates

### Test Case 3: Update LM Article Quantity
- [ ] Add commodity item with quantity = 1, dimensions set
- [ ] Add LM article
- [ ] Verify LM calculation shows correct quantity
- [ ] Change commodity item quantity to 2
- [ ] Verify LM calculation updates: 2 × (LM per item) = new total LM
- [ ] Verify pricing updates accordingly

---

## Implementation Plan

### Phase 1: Allow Duplicate Articles
1. Remove duplicate check in `SmartArticleSelector::selectArticle()`
2. Test adding same article multiple times
3. Verify each appears as separate line item

### Phase 2: Recalculate All Articles on Quantity Change
1. Update `QuotationCommodityItem::saved` event to recalculate ALL articles
2. For non-LM articles, update `QuotationRequestArticle::quantity` based on commodity item quantity
3. Ensure `QuotationRequestArticle::saving` event recalculates subtotal
4. Test quantity changes for both LM and non-LM articles

### Phase 3: View Updates
1. Verify view correctly displays quantity for all article types
2. Ensure calculation breakdown shows correct values
3. Test real-time updates when quantity changes

---

## Questions to Clarify

1. **Article-Commodity Item Relationship:**
   - Should each commodity item have its own article line, or should articles be shared?
   - If shared, how should quantity be calculated (sum of all items or max)?

2. **Non-LM Article Quantity:**
   - For non-LM articles, should quantity = sum of all commodity item quantities?
   - Or should quantity = quantity of the specific commodity item it's linked to?

3. **Article Removal:**
   - If an article is added for commodity item 1, and commodity item 1 is removed, should the article be removed too?
   - Or should articles be independent of commodity items?

---

## Files to Modify

1. `app/Livewire/SmartArticleSelector.php`
   - Remove or modify duplicate check (line 114-117)

2. `app/Models/QuotationCommodityItem.php`
   - Update `saved` event to recalculate ALL articles, not just LM (line 183-184)
   - Add logic to update non-LM article quantities

3. `app/Models/QuotationRequestArticle.php`
   - Ensure `saving` event properly recalculates subtotal for all unit types

4. `resources/views/livewire/customer/quotation-creator.blade.php`
   - Verify quantity display is correct for all article types

---

## Risk Assessment

**Low Risk:**
- Removing duplicate check might allow accidental duplicate articles
- Mitigation: Add UI indicator showing how many times an article is added

**Medium Risk:**
- Recalculating all articles on quantity change might cause performance issues with many articles
- Mitigation: Use database transactions and batch updates

**High Risk:**
- Changing quantity calculation logic might break existing quotations
- Mitigation: Test thoroughly with existing data, add migration if needed
