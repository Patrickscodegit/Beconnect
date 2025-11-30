# Quantity Update Audit - Complete Flow Analysis

## Current State (Quotation ID: 63)

### Database State
- **Commodity Item ID: 38**
  - Quantity: **1** (should be 2)
  - Length: 600.00 cm
  - Width: 250.00 cm
  - LM: 6.0000

- **Article: Sallaum(ANR 332/740) Lagos Nigeria, LM Seafreight**
  - Unit Type: LM
  - Quantity: 1
  - Selling Price: €625.00
  - Subtotal: €3,750.00 (should be €7,500.00 if quantity = 2)

### Expected Calculation
- LM per item: (600/100 × 250/100) / 2.5 = (6 × 2.5) / 2.5 = 6 LM
- With quantity = 2: 6 LM × 2 = 12 LM
- Subtotal: 12 LM × €625.00 = €7,500.00

## Issues Found

### Issue 1: Quantity Field Not Using `wire:model.live`
**Location:** `resources/views/livewire/commodity-forms/vehicles.blade.php:213`
```blade
wire:model="items.{{ $index }}.quantity"
```

**Problem:** 
- Uses `wire:model` instead of `wire:model.live`
- `wire:model` only updates on blur (when field loses focus)
- `wire:model.live` updates on every keystroke/change
- This means `updated()` method may not be called immediately when quantity changes

**Impact:** User changes quantity to 2, but Livewire doesn't detect the change until field loses focus

### Issue 2: Logs Show `lm_articles_count":0`
**Location:** `app/Models/QuotationCommodityItem.php:185`

**Problem:**
- Logs consistently show `lm_articles_count":0` even though:
  - Tinker query shows 1 LM article exists
  - Query `whereRaw('UPPER(unit_type) = ?', ['LM'])` works in tinker

**Possible Causes:**
1. Query is running before articles are created
2. Relationship not loaded correctly
3. Transaction/race condition issue

### Issue 3: Quantity Not Being Saved
**Evidence:**
- Database shows quantity = 1
- User reports quantity = 2 in form
- No logs showing "quantity changed" when user updates

**Flow Check:**
1. ✅ `updated()` method has quantity detection (line 370-385)
2. ✅ `saveItemToDatabase()` includes quantity in data array (line 550)
3. ❌ Quantity field doesn't use `wire:model.live` - may not trigger `updated()`

### Issue 4: No Logs for Quantity Changes
**Expected:** When quantity changes, should see:
```
CommodityItemsRepeater: quantity changed
```

**Actual:** No such logs found in recent log entries

**Conclusion:** `updated()` method is NOT being called when quantity changes because field uses `wire:model` instead of `wire:model.live`

## Flow Analysis

### Current Flow (Broken)
1. User changes quantity from 1 to 2
2. ❌ `wire:model` doesn't trigger `updated()` until blur
3. ❌ If user doesn't blur field, change never detected
4. ❌ Quantity never saved to database
5. ❌ Articles never recalculated

### Expected Flow (Fixed)
1. User changes quantity from 1 to 2
2. ✅ `wire:model.live` triggers `updated()` immediately
3. ✅ `updated()` detects quantity change (line 370)
4. ✅ `saveItemToDatabase()` called (line 436)
5. ✅ Quantity saved to database
6. ✅ `QuotationCommodityItem::saved` event fires
7. ✅ LM articles found and recalculated
8. ✅ View shows updated price

## Root Cause

**Primary Issue:** Quantity field uses `wire:model` instead of `wire:model.live`

**Secondary Issue:** Even if quantity is saved, logs show `lm_articles_count":0` suggesting query issue

## Files to Fix

1. **resources/views/livewire/commodity-forms/vehicles.blade.php**
   - Change `wire:model="items.{{ $index }}.quantity"` to `wire:model.live="items.{{ $index }}.quantity"`

2. **resources/views/livewire/commodity-forms/general_cargo.blade.php**
   - Same change for consistency

3. **resources/views/livewire/commodity-forms/boat.blade.php**
   - Same change for consistency

4. **resources/views/livewire/commodity-forms/machinery.blade.php**
   - Same change for consistency

5. **app/Models/QuotationCommodityItem.php**
   - Investigate why `lm_articles_count` is 0 in logs
   - Add more debugging to understand query issue

## Testing Plan

After fixes:
1. Change quantity from 1 to 2
2. Verify log shows "quantity changed"
3. Verify database quantity = 2
4. Verify log shows `lm_articles_count > 0`
5. Verify article subtotal = €7,500.00
6. Verify view shows correct calculation breakdown

