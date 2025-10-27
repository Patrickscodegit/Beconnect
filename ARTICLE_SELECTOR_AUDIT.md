# ARTICLE SELECTOR AUDIT REPORT

**Date**: January 27, 2025  
**Issue**: No articles showing in Filament ArticleSelector  
**Status**: 🔍 ROOT CAUSE IDENTIFIED

---

## 🔴 PROBLEM STATEMENT

When creating/editing a quotation in the Filament admin panel:
- Articles section shows "No articles found"
- API calls return empty results or 500 errors
- Smart suggestions may or may not work
- Manual article search returns no results

**User Impact**: Cannot add articles to quotations → Cannot create quotes

---

## 🔍 ROOT CAUSE ANALYSIS

### Issue #1: Parameter Mismatch (CRITICAL)

**Location**: `app/Filament/Resources/QuotationRequestResource.php` Line 713

```php
ArticleSelector::make('articles')
    ->serviceType(fn ($get) => $get('service_type'))
    ->customerType(fn ($get) => $get('customer_role'))  // ← WRONG FIELD!
    ->carrierCode(fn ($get) => $get('preferred_carrier'))
```

**Problem**:
- Form field is named: `customer_role`
- ArticleSelector expects: `customer_type`
- API endpoint filters by: `customer_type`
- **Result**: Empty or wrong customer_type sent to API

**API Request** (from browser console):
```
GET /admin/api/quotation/articles?service_type=RORO_EXPORT&customer_type=RORO
                                                              ^^^^^^^^^^^^
                                                              This is WRONG!
                                                              Should be: GENERAL, FORWARDERS, CIB, etc.
```

**What's Happening**:
1. Form has `customer_role` = "RORO" (e.g., RORO customer type)
2. ArticleSelector passes this as `customer_type`  
3. API filters: `WHERE customer_type = 'RORO'`
4. But `customer_type` column has values: GENERAL, FORWARDERS, CIB, PRIVATE, HOLLANDICO, OLDTIMER
5. **No match** → Empty results

### Issue #2: Field Naming Confusion

**Two Different Concepts Mixed**:

1. **customer_role** (22 types):
   - FORWARDER, RORO, POV, CONSIGNEE, HOLLANDICO, BLACKLISTED, etc.
   - Used for: Profit margin calculation (legacy)
   - Database: `quotation_requests.customer_role`

2. **customer_type** (6 types):
   - FORWARDERS, GENERAL, CIB, PRIVATE, HOLLANDICO, OLDTIMER
   - Used for: Article filtering (which articles apply)
   - Database: `robaws_articles_cache.customer_type`

**These are DIFFERENT fields with DIFFERENT values!**

### Issue #3: Missing customer_type in QuotationRequest

**Current Schema**: `quotation_requests` table

```sql
customer_role VARCHAR(100)  -- Has values like: FORWARDER, RORO, POV, etc.
customer_type VARCHAR(100)  -- Exists in table (6 values: FORWARDERS, GENERAL, etc.)
```

**QuotationRequestResource Form**:
- ✅ Has `customer_role` field  
- ❌ Does NOT have `customer_type` field visible/editable
- ArticleSelector tries to use `customer_role` as `customer_type`

---

## 🎯 THE FIX

### Option A: Quick Fix (Use customer_type instead of customer_role)

**Change**: `app/Filament/Resources/QuotationRequestResource.php` Line 713

```php
// BEFORE (WRONG):
->customerType(fn ($get) => $get('customer_role'))

// AFTER (CORRECT):
->customerType(fn ($get) => $get('customer_type'))
```

**Also add customer_type field to the form**:

```php
Forms\Components\Select::make('customer_type')
    ->label('Customer Type (for Article Filtering)')
    ->options([
        'FORWARDERS' => 'Freight Forwarders',
        'GENERAL' => 'General Customers / End Clients',
        'CIB' => 'Car Investment Bree',
        'PRIVATE' => 'Private Persons & Commercial Imports',
        'HOLLANDICO' => 'Hollandico / Belgaco Intervention',
        'OLDTIMER' => 'Oldtimer via Hollandico',
    ])
    ->default('GENERAL')
    ->required()
    ->helperText('Which type of customer for article filtering?')
```

### Option B: Map customer_role to customer_type

**Add mapping logic in ArticleSelector**:

```php
public function getCustomerType(): ?string
{
    $role = $this->evaluate($this->customerType);
    
    // Map customer_role to customer_type
    $roleToTypeMap = [
        'FORWARDER' => 'FORWARDERS',
        'RORO' => 'GENERAL',
        'POV' => 'GENERAL',
        'CONSIGNEE' => 'GENERAL',
        'HOLLANDICO' => 'HOLLANDICO',
        'BLACKLISTED' => 'GENERAL',
        // ...map all 22 roles to 6 types
    ];
    
    return $roleToTypeMap[$role] ?? 'GENERAL';
}
```

### Option C: Update API to Accept Both (Most Flexible)

**Change API Controller** to accept either:
- `customer_type` (6 types for filtering)
- OR `customer_role` (22 roles, mapped internally)

```php
// app/Http/Controllers/Api/QuotationArticleController.php

if ($request->has('customer_type') && !empty($request->customer_type)) {
    $customerType = $request->customer_type;
} elseif ($request->has('customer_role') && !empty($request->customer_role)) {
    // Map role to type
    $customerType = $this->mapRoleToType($request->customer_role);
}

if ($customerType) {
    $query->where(function ($q) use ($customerType) {
        $q->where('customer_type', $customerType)
          ->orWhereNull('customer_type');
    });
}
```

---

## 📊 CURRENT STATE ANALYSIS

### What's in the Database

**quotation_requests table**:
```sql
customer_role: "RORO", "FORWARDER", "CONSIGNEE", etc. (22 values)
customer_type: "GENERAL", "FORWARDERS", etc. (6 values)
```

**robaws_articles_cache table**:
```sql
customer_type: "GENERAL", "FORWARDERS", "CIB", "PRIVATE", "HOLLANDICO", "OLDTIMER" (6 values)
service_type: "RORO_EXPORT", "FCL_IMPORT", etc.
```

### What the API Expects

**Endpoint**: `/admin/api/quotation/articles`

**Parameters**:
- `service_type`: RORO_EXPORT, FCL_IMPORT, etc. ✅ Working
- `customer_type`: GENERAL, FORWARDERS, CIB, etc. ❌ Getting wrong value
- `carrier_code`: SALLAUM, MSC, etc. ✅ Working

**Filter Logic** (Line 30-36):
```php
if ($request->has('customer_type') && $request->customer_type !== 'null' && $request->customer_type !== '') {
    $customerType = $request->customer_type;
    $query->where(function ($q) use ($customerType) {
        $q->where('customer_type', $customerType)
          ->orWhereNull('customer_type');
    });
}
```

This filter works correctly IF it receives the right value!

### What's Being Sent

**From ArticleSelector** (Line 713):
```php
->customerType(fn ($get) => $get('customer_role'))
```

**Result**: Sends "RORO" when it should send "GENERAL"

---

## ✅ RECOMMENDED SOLUTION

**Implement Option A + Option B Hybrid**:

1. **Add customer_type field** to quotation form (for explicit selection)
2. **Add mapping logic** as fallback (for backward compatibility)
3. **Keep both fields** in database

### Benefits
- ✅ Explicit customer_type selection (clear to users)
- ✅ Backward compatible with customer_role
- ✅ Articles filter correctly
- ✅ No breaking changes

---

## 🛠️ IMPLEMENTATION PLAN

### Step 1: Add customer_type to Filament Form

**File**: `app/Filament/Resources/QuotationRequestResource.php`

**Add after customer_role field**:

```php
Forms\Components\Select::make('customer_type')
    ->label('Customer Type (Article Filtering)')
    ->options([
        'FORWARDERS' => 'Freight Forwarders (wholesale pricing)',
        'GENERAL' => 'General Customers / End Clients',
        'CIB' => 'Car Investment Bree',
        'PRIVATE' => 'Private Persons & Commercial Imports',
        'HOLLANDICO' => 'Hollandico / Belgaco Intervention',
        'OLDTIMER' => 'Oldtimer via Hollandico',
    ])
    ->default('GENERAL')
    ->required()
    ->helperText('Which articles should be available for selection?')
    ->live() // Refresh articles when changed
    ->columnSpan(1),
```

### Step 2: Fix ArticleSelector Parameter

**File**: `app/Filament/Resources/QuotationRequestResource.php` Line 713

```php
// CHANGE FROM:
->customerType(fn ($get) => $get('customer_role'))

// CHANGE TO:
->customerType(fn ($get) => $get('customer_type'))
```

### Step 3: Add Default customer_type to Customer Portal

**File**: `app/Http/Controllers/CustomerQuotationController.php`

```php
$data = [
    // ...
    'customer_role' => 'CONSIGNEE', // WHO they are
    'customer_type' => 'GENERAL', // Already exists! Just verify it's set
    'pricing_tier_id' => $this->getDefaultPricingTierId(),
];
```

### Step 4: Test

1. Open quotation in Filament
2. Select service_type: RORO_EXPORT
3. Select customer_type: GENERAL
4. Select carrier (optional)
5. Articles should load

---

## 🧪 TESTING CHECKLIST

- [ ] Set service_type = RORO_EXPORT
- [ ] Set customer_type = GENERAL
- [ ] Verify articles load in "All Articles" section
- [ ] Verify smart suggestions work (if quotation has POL/POD)
- [ ] Add an article - verify it saves
- [ ] Change customer_type to FORWARDERS - verify article list updates
- [ ] Test with different service types
- [ ] Test with carrier_code filter

---

## 📋 FIELD CLARIFICATION

### customer_role (22 values) - WHO They Are
- FORWARDER, RORO, POV, CONSIGNEE, INTERMEDIATE, EMBASSY, etc.
- **Purpose**: Customer categorization, CRM, profit margin calculation (legacy)
- **Used for**: Business intelligence, customer segmentation
- **Examples**: "FORWARDER" = freight forwarding company, "CONSIGNEE" = end customer

### customer_type (6 values) - WHAT Articles They See
- FORWARDERS, GENERAL, CIB, PRIVATE, HOLLANDICO, OLDTIMER
- **Purpose**: Article filtering (which Robaws articles apply)
- **Used for**: Determining available services/articles
- **Examples**: "FORWARDERS" = wholesale articles, "GENERAL" = standard retail articles

### pricing_tier_id (NEW) - WHAT Pricing They Get
- Tier A, B, C (with editable margins)
- **Purpose**: Profit margin application
- **Used for**: Calculating selling prices
- **Examples**: Tier A = -5% discount, Tier B = +15% markup

---

## 🎯 SUMMARY

**Root Cause**: ArticleSelector is passing `customer_role` value to `customer_type` parameter, causing filter mismatch.

**Impact**: No articles load because filter looks for "RORO" in customer_type column, but that column has "GENERAL", "FORWARDERS", etc.

**Fix**: 
1. Add `customer_type` field to quotation form
2. Change ArticleSelector to use `customer_type` instead of `customer_role`
3. Set appropriate defaults

**Effort**: 15-30 minutes

**Priority**: CRITICAL - Blocks quotation creation

---

**Next Action**: Implement the fix above to restore article selection functionality.

