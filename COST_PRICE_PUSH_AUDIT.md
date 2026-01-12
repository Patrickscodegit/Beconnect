# Cost Price Push to Robaws - Audit Report

## Issue
The `cost_price` field is not being updated/pushed to Robaws, even though:
- It exists in the local `robaws_articles_cache` table
- It's being synced FROM Robaws API (`costPrice` field)
- Purchase price breakdown is being pushed successfully

## Audit Findings

### 1. Cost Price is Synced FROM Robaws (Pull)
**File:** `app/Services/Quotation/RobawsArticlesSyncService.php` (line 308)
- ✅ `cost_price` is extracted from Robaws API response: `'cost_price' => $article['costPrice'] ?? null`
- ✅ The API field name is `costPrice` (camelCase)
- ✅ This indicates `costPrice` is a **main article field** in Robaws API (not an extraField)

### 2. Cost Price is NOT in Push Configuration
**File:** `app/Services/Robaws/RobawsArticlePushService.php`

#### MAIN_ARTICLE_FIELDS (line 165-171)
Currently only contains:
```php
private const MAIN_ARTICLE_FIELDS = [
    'unit_price' => [
        'robaws_field' => 'salePrice',
        'label' => 'Unit Price',
        'group' => 'PRICING',
    ],
];
```
- ❌ `cost_price` is **NOT** in MAIN_ARTICLE_FIELDS

#### FIELD_MAPPING (extraFields)
- ❌ `cost_price` is **NOT** in FIELD_MAPPING either

### 3. Database Schema
**File:** `app/Models/RobawsArticleCache.php`
- ✅ `cost_price` is in `$fillable` array (line 78)
- ✅ `cost_price` is cast as `decimal:2` (line 122)
- ✅ Column exists in database (migration `2025_10_21_081518_add_standard_robaws_fields_to_articles_cache.php`)

### 4. Comparison with salePrice (unit_price)
The pattern for `unit_price` → `salePrice`:
- `unit_price` (local) → `salePrice` (Robaws API) - **MAIN_ARTICLE_FIELD**
- Goes in main article payload (not extraFields)
- Handled in `buildMainArticlePayload()` method

Since `costPrice` comes from the main article response (not extraFields), it should follow the same pattern:
- `cost_price` (local) → `costPrice` (Robaws API) - **Should be MAIN_ARTICLE_FIELD**

## Root Cause
**`cost_price` is not included in the push service configuration**, so it's never included in the payload sent to Robaws API.

## Solution Required
Add `cost_price` to `MAIN_ARTICLE_FIELDS` constant in `RobawsArticlePushService.php`, mapping:
- Local field: `cost_price`
- Robaws API field: `costPrice`
- Type: Main article field (numeric/decimal)

## Implementation Notes
1. Add to `MAIN_ARTICLE_FIELDS` constant (similar to `unit_price` → `salePrice`)
2. Update `buildMainArticlePayload()` method to handle `cost_price` (similar to `unit_price` handling)
3. Update `getPushableFields()` - should automatically include it
4. Update field validation logic if needed
5. Update change detection logic to compare `costPrice` values

## Related Code Sections
- `app/Services/Robaws/RobawsArticlePushService.php`:
  - `MAIN_ARTICLE_FIELDS` constant (line 165)
  - `buildMainArticlePayload()` method (line 407)
  - Change detection logic (line 733) - currently only handles `salePrice`

## Verification
- ✅ Purchase price breakdown push works (contains cost breakdown)
- ❌ Actual `costPrice` field in Robaws is not being updated
- Images show: Robaws shows `854,00` but breakdown shows `860.00` total
