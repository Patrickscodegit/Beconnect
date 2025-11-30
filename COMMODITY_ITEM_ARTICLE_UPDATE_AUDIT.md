# Audit: Articles Not Updating When Commodity Items Change

## Problem Statement

When users change commodity items in the customer portal, the suggested articles are not updating automatically. The SmartArticleSelector component should reload when commodity items are added, removed, or their commodity_type is changed.

## Current Flow Analysis

### 1. Commodity Item Change Flow

**File**: `app/Livewire/CommodityItemsRepeater.php`

**When commodity_type is changed:**
- Line 317-363: `updated()` method is triggered
- Line 350-355: If commodity_type is set and item has temp ID, creates database record
- Line 360: If item has database ID, calls `saveItemToDatabase()`
- Line 489-495: `saveItemToDatabase()` dispatches `commodity-item-saved` event **ONLY if commodity_type is not empty**

**Event Dispatch:**
```php
// Line 422-425: When creating new item
$this->dispatch('commodity-item-saved', [
    'quotation_id' => $this->quotationId
]);

// Line 489-495: When updating existing item
if (!empty($item['commodity_type'])) {
    $this->dispatch('commodity-item-saved', [
        'quotation_id' => $this->quotationId
    ]);
}
```

### 2. Parent Component Event Handling

**File**: `app/Livewire/Customer/QuotationCreator.php`

**Listener Registration:**
- Line 60: `'commodity-item-saved' => 'handleCommodityItemSaved'`

**Handler Method (Line 75-91):**
```php
public function handleCommodityItemSaved($data)
{
    // Refresh quotation to load latest commodityItems
    if ($this->quotation) {
        $this->quotation = $this->quotation->fresh(['commodityItems', 'selectedSchedule.carrier']);
    }
    
    // Update showArticles flag (commodity item might now have commodity_type set)
    $this->updateShowArticles();
    
    // Log for debugging
    Log::info('QuotationCreator::handleCommodityItemSaved() called', [...]);
}
```

**Issue Identified:**
- `handleCommodityItemSaved()` does **NOT** dispatch `quotationUpdated` event
- It only calls `updateShowArticles()` which may dispatch `quotationUpdated` if `showArticles` becomes true
- But if `showArticles` is already true, no event is dispatched

### 3. SmartArticleSelector Event Listening

**File**: `app/Livewire/SmartArticleSelector.php`

**Listener Registration:**
- Line 25-29: Listens for `'quotationUpdated' => 'loadSuggestions'`

**Load Suggestions Method (Line 46-82):**
```php
public function loadSuggestions()
{
    $this->loading = true;
    
    // Always refresh quotation to ensure latest data (including commodity_type and commodityItems)
    $this->quotation = $this->quotation->fresh(['selectedSchedule.carrier', 'commodityItems']);
    
    try {
        $service = app(SmartArticleSelectionService::class);
        $suggestions = $service->getTopSuggestions(
            $this->quotation, 
            $this->maxArticles, 
            $this->minMatchPercentage
        );
        
        $this->suggestedArticles = $suggestions;
        // ... rest of method
    }
}
```

**Issue Identified:**
- `loadSuggestions()` refreshes quotation with `commodityItems` relationship
- But it's only called when `quotationUpdated` event is received
- If `quotationUpdated` is not dispatched, suggestions never reload

### 4. updateShowArticles() Method

**File**: `app/Livewire/Customer/QuotationCreator.php`

**Method (Line 286-325):**
```php
protected function updateShowArticles()
{
    $polFilled = !empty(trim($this->pol));
    $podFilled = !empty(trim($this->pod));
    $scheduleSelected = $this->selected_schedule_id !== null && $this->selected_schedule_id > 0;
    
    // Check if commodity is selected
    $commoditySelected = false;
    
    // Quick Quote mode: check simple commodity_type field
    if (!empty($this->commodity_type)) {
        $commoditySelected = true;
    }
    
    // Detailed Quote mode: check commodityItems
    if (!$commoditySelected && $this->quotationMode === 'detailed') {
        if ($this->quotation) {
            $quotation = $this->quotation->fresh(['commodityItems']);
            if ($quotation->commodityItems && $quotation->commodityItems->count() > 0) {
                foreach ($quotation->commodityItems as $item) {
                    if (!empty($item->commodity_type)) {
                        $commoditySelected = true;
                        break;
                    }
                }
            }
        }
    }
    
    $this->showArticles = $polFilled && $podFilled && $scheduleSelected && $commoditySelected;
    
    // Emit event to SmartArticleSelector to reload
    if ($this->showArticles) {
        $this->dispatch('quotationUpdated');
    }
}
```

**Issue Identified:**
- `updateShowArticles()` only dispatches `quotationUpdated` if `showArticles` becomes `true`
- If `showArticles` is already `true` (POL/POD/Schedule/Commodity already selected), changing commodity_type does NOT trigger reload
- The condition `if ($this->showArticles)` is true, but the dispatch happens inside, so if `showArticles` was already true, it still dispatches... **Wait, this should work**

**Re-evaluation:**
- Actually, `updateShowArticles()` DOES dispatch `quotationUpdated` when `showArticles` is true
- But the issue might be that `handleCommodityItemSaved()` calls `updateShowArticles()` which checks if commodity is selected
- If commodity was already selected, `showArticles` might already be true, so the dispatch should happen

### 5. SmartArticleSelectionService Caching

**File**: `app/Services/SmartArticleSelectionService.php`

**Cache Key (Line 23):**
```php
$cacheKey = "article_suggestions_{$quotation->id}_{$quotation->updated_at->timestamp}";
```

**Issue Identified:**
- Cache key includes `updated_at` timestamp
- When commodity items are saved, the quotation's `updated_at` might not change if only the relationship is updated
- This could cause stale cache to be used

**Cache Usage:**
- Line 25: `Cache::remember($cacheKey, 3600, ...)` - 1 hour cache
- If `updated_at` doesn't change, same cache key is used
- Stale suggestions are returned

## Root Causes Identified

### Primary Issue: Missing Event Dispatch

**Location**: `app/Livewire/Customer/QuotationCreator.php::handleCommodityItemSaved()`

**Problem**: 
- When commodity items are saved, `handleCommodityItemSaved()` is called
- It calls `updateShowArticles()` which should dispatch `quotationUpdated`
- BUT: `updateShowArticles()` only dispatches if `showArticles` is true
- If `showArticles` is already true, it should dispatch... but there might be a timing issue

**Actual Issue**: 
- `updateShowArticles()` checks if commodity is selected by querying database
- But the database might not have the latest commodity items yet (race condition)
- Or the quotation needs to be refreshed before checking

### Secondary Issue: Cache Key Not Updating

**Location**: `app/Services/SmartArticleSelectionService.php`

**Problem**:
- Cache key uses `$quotation->updated_at->timestamp`
- When commodity items are saved, the quotation's `updated_at` might not change
- Only the `quotation_commodity_items` table is updated, not the `quotation_requests` table
- Same cache key = stale cache

### Tertiary Issue: Quotation Not Refreshed Before Check

**Location**: `app/Livewire/Customer/QuotationCreator.php::updateShowArticles()`

**Problem**:
- `updateShowArticles()` calls `$this->quotation->fresh(['commodityItems'])` inside the method
- But it assigns to a local variable `$quotation`, not `$this->quotation`
- Then it checks `$this->quotation->commodityItems` which might be stale
- Actually wait, line 304: `$quotation = $this->quotation->fresh(['commodityItems']);` - this is correct
- But then line 305: `if ($quotation->commodityItems && ...)` - uses fresh quotation, so this should be fine

## Detailed Code Flow

### Scenario: User Changes Commodity Type from "Cars" to "Trucks"

1. **User selects "Trucks" in dropdown**
   - `CommodityItemsRepeater::updated('items.0.commodity_type')` is called
   - Line 350: Detects commodity_type change
   - Line 360: Calls `saveItemToDatabase(0, item)`
   - Line 491: Checks if `!empty($item['commodity_type'])` - **TRUE**
   - Line 492-494: Dispatches `commodity-item-saved` event

2. **QuotationCreator receives event**
   - Line 75: `handleCommodityItemSaved($data)` is called
   - Line 79: Refreshes quotation: `$this->quotation->fresh(['commodityItems', 'selectedSchedule.carrier'])`
   - Line 83: Calls `updateShowArticles()`

3. **updateShowArticles() executes**
   - Line 304: Gets fresh quotation: `$quotation = $this->quotation->fresh(['commodityItems'])`
   - Line 305-313: Checks if commodity is selected - **TRUE** (Trucks is selected)
   - Line 319: Sets `$this->showArticles = true` (if already true, stays true)
   - Line 322-324: **IF** `$this->showArticles` is true, dispatches `quotationUpdated`
   - **This should work!**

4. **SmartArticleSelector receives event**
   - Line 26: Listens for `quotationUpdated`
   - Line 46: `loadSuggestions()` is called
   - Line 53: Refreshes quotation: `$this->quotation->fresh(['selectedSchedule.carrier', 'commodityItems'])`
   - Line 57: Calls `SmartArticleSelectionService::getTopSuggestions()`

5. **SmartArticleSelectionService calculates suggestions**
   - Line 23: Creates cache key: `article_suggestions_{id}_{updated_at_timestamp}`
   - **PROBLEM**: If quotation's `updated_at` didn't change, same cache key is used
   - Line 25: Returns cached results (stale suggestions for "Cars" instead of "Trucks")

## Issues Summary

### Issue #1: Cache Key Not Invalidated
**Severity**: HIGH
**Location**: `app/Services/SmartArticleSelectionService.php:23`
**Problem**: Cache key uses `updated_at` timestamp, but saving commodity items doesn't update quotation's `updated_at`
**Impact**: Stale article suggestions are returned even after commodity type changes

### Issue #2: Event Dispatch Timing
**Severity**: MEDIUM  
**Location**: `app/Livewire/Customer/QuotationCreator.php:75-91`
**Problem**: `handleCommodityItemSaved()` might not always trigger `quotationUpdated` if `showArticles` check fails
**Impact**: SmartArticleSelector doesn't reload when commodity changes

### Issue #3: Quotation Updated_at Not Touched
**Severity**: HIGH
**Location**: `app/Livewire/CommodityItemsRepeater.php:479`
**Problem**: When saving commodity items, quotation's `updated_at` is not updated
**Impact**: Cache key doesn't change, stale cache is used

## Recommended Fixes

### Fix #1: Update Quotation's updated_at When Commodity Items Change
**File**: `app/Livewire/CommodityItemsRepeater.php`
**Location**: `saveItemToDatabase()` method (line 438)
**Action**: After updating commodity item, touch the parent quotation:
```php
// After line 481 (update item)
\App\Models\QuotationRequest::where('id', $this->quotationId)->touch();
```

### Fix #2: Always Dispatch quotationUpdated in handleCommodityItemSaved
**File**: `app/Livewire/Customer/QuotationCreator.php`
**Location**: `handleCommodityItemSaved()` method (line 75)
**Action**: Always dispatch `quotationUpdated` if articles are showing:
```php
public function handleCommodityItemSaved($data)
{
    if ($this->quotation) {
        $this->quotation = $this->quotation->fresh(['commodityItems', 'selectedSchedule.carrier']);
    }
    
    $this->updateShowArticles();
    
    // Always dispatch if articles are showing (commodity changed, need to reload)
    if ($this->showArticles) {
        $this->dispatch('quotationUpdated');
    }
}
```

### Fix #3: Clear Cache When Commodity Changes
**File**: `app/Services/SmartArticleSelectionService.php`
**Location**: Add `clearCache()` method call in `SmartArticleSelector::loadSuggestions()`
**Action**: Clear cache before loading new suggestions:
```php
public function loadSuggestions()
{
    $this->loading = true;
    $this->quotation = $this->quotation->fresh(['selectedSchedule.carrier', 'commodityItems']);
    
    // Clear cache to ensure fresh suggestions
    $service = app(SmartArticleSelectionService::class);
    $service->clearCache($this->quotation);
    
    // ... rest of method
}
```

### Fix #4: Include Commodity Items in Cache Key
**File**: `app/Services/SmartArticleSelectionService.php`
**Location**: `suggestParentArticles()` method (line 20)
**Action**: Include commodity items hash in cache key:
```php
$commodityHash = $quotation->commodityItems
    ->map(fn($item) => $item->commodity_type . $item->id)
    ->sort()
    ->implode('|');
$cacheKey = "article_suggestions_{$quotation->id}_{$quotation->updated_at->timestamp}_{$commodityHash}";
```

## Testing Checklist

After fixes are applied, test:

1. ✅ Add new commodity item with commodity_type → Articles should reload
2. ✅ Change commodity_type of existing item → Articles should reload  
3. ✅ Remove commodity item → Articles should reload
4. ✅ Change from "Cars" to "Trucks" → Articles should show truck-specific articles
5. ✅ Change from "Trucks" back to "Cars" → Articles should show car-specific articles
6. ✅ Add multiple commodity items → Articles should reflect all commodity types
7. ✅ Remove all commodity items → Articles should hide (if commodity required)

## Files to Modify

1. `app/Livewire/CommodityItemsRepeater.php` - Touch quotation when items saved
2. `app/Livewire/Customer/QuotationCreator.php` - Always dispatch event in handler
3. `app/Livewire/SmartArticleSelector.php` - Clear cache before loading
4. `app/Services/SmartArticleSelectionService.php` - Include commodity hash in cache key

## Priority

**HIGH** - This is a critical UX issue. Users expect articles to update when they change commodity types, but currently they don't, leading to confusion and incorrect article selection.


