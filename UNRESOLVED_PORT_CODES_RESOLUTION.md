# Unresolved Port Codes - Resolution Summary

## Date: 2026-01-10

## Initial Unresolved Codes
- VLS, ETS, HULL, NMT, CTN (found during backfill command)

## Resolution Actions Taken

### ✅ VLS → FLU (Flushing/Vlissingen)
**Status:** RESOLVED
- **Action:** Created port alias `VLS → FLU`
- **Port:** Flushing (FLU), Netherlands
- **UN/LOCODE:** NLVLI (updated on Flushing port)
- **Articles affected:** 11 articles with POL=VLS
- **Result:** All 11 articles now resolve correctly

### ✅ HULL → GBHUL (Hull, UK)
**Status:** RESOLVED
- **Action:** Created port `GBHUL` (Hull, United Kingdom) and alias `HULL → GBHUL`
- **Port Details:**
  - Code: GBHUL
  - Name: Hull
  - Country: United Kingdom
  - Region: Europe
  - UN/LOCODE: GBHUL
  - Port Category: SEA_PORT
- **Articles affected:** 1 article with POD=HULL
- **Result:** Article now resolves correctly

### ❌ NMT (Not a Port)
**Status:** CORRECTLY LEFT UNRESOLVED
- **Reason:** NMT is a carrier/shipping line, NOT a port code
- **Articles affected:** 6 articles with POD=NMT
- **Action:** No action needed - correctly flagged as invalid
- **Recommendation:** These articles may need data cleanup to correct the POD code, but this is a separate data quality issue

### ❌ ETS (Not a Port)
**Status:** CORRECTLY LEFT UNRESOLVED
- **Reason:** ETS = "EU Emission Trading System" - this is a surcharge/regulatory code, NOT a port code
- **Articles affected:** 4 articles with POL=ETS
- **Sample articles:**
  - `1748 - ETS - EU Emission Trading System (ETS) 4%`
  - `NWAFETS - NMT WAF - ETS - EU Emission Trading System (ETS)`
  - `SWAFETS - Sallaum - ETS - EU Emission Trading System (ETS)`
- **Actual POL:** Some articles have `pol: Antwerp (ANR), Belgium` in their POD field
- **Recommendation:** **DATA CLEANUP NEEDED** - These articles should have their `pol_code` set to `ANR` (Antwerp) instead of `ETS`. This is a data quality issue that should be addressed separately.

### ❌ CTN (Not a Port)
**Status:** CORRECTLY LEFT UNRESOLVED
- **Reason:** CTN = "Cargo Tracking Note" - this is a document type, NOT a port code
- **Articles affected:** 9 articles with POL=CTN
- **Sample articles:**
  - `Surcharges - Waiver (CTN) Conakry - Guinee` (POD: CKY)
  - `Surcharges - Waiver (CTN) Cotonou - Benin` (POD should be COO)
  - `Surcharges - Waiver (CTN) Freetown - Sierra Leone` (POD: FNA)
- **Pattern:** All are surcharge articles with actual POD codes in the `pod_code` field (CKY, COO, FNA)
- **Recommendation:** **DATA CLEANUP NEEDED** - These articles should have their `pol_code` set based on the actual POL port (likely Antwerp/ANR for West Africa routes), not `CTN`. This is a data quality issue that should be addressed separately.

## Backfill Results (After Resolution)

### Before Resolution
- POL resolved: 884
- POD resolved: 90
- Flagged for manual review: 31
- Unresolved codes: 5 (VLS, ETS, HULL, NMT, CTN)

### After Resolution
- POL resolved: 895 (+11)
- POD resolved: 91 (+1)
- Flagged for manual review: 19 (-12)
- Unresolved codes: 3 (ETS, NMT, CTN) ✓

## Port Aliases Created

```php
// VLS → FLU (Flushing/Vlissingen)
PortAlias::create([
    'port_id' => 21, // Flushing (FLU)
    'alias' => 'VLS',
    'alias_normalized' => 'vls',
    'alias_type' => 'code_variant',
    'is_active' => true,
]);

// HULL → GBHUL (Hull, UK)
PortAlias::create([
    'port_id' => 71, // Hull (GBHUL)
    'alias' => 'HULL',
    'alias_normalized' => 'hull',
    'alias_type' => 'code_variant',
    'is_active' => true,
]);
```

## New Port Created

```php
// Hull, United Kingdom
Port::create([
    'name' => 'Hull',
    'code' => 'GBHUL',
    'country' => 'United Kingdom',
    'region' => 'Europe',
    'port_category' => 'SEA_PORT',
    'country_code' => 'GB',
    'unlocode' => 'GBHUL',
    'is_active' => true,
]);
```

## Data Cleanup Recommendations

### Priority 1: ETS Articles (4 articles)
These articles have `pol_code = 'ETS'` but should have `pol_code = 'ANR'` (Antwerp):
- Articles are ETS surcharge-related, not port-specific
- Actual POL appears to be Antwerp based on article context
- **Action:** Create data cleanup script to:
  1. Find articles with `pol_code = 'ETS'`
  2. Check if `pol` field contains "Antwerp" or similar
  3. Update `pol_code` to `'ANR'` if appropriate
  4. Re-run backfill to resolve ports

### Priority 2: CTN Articles (9 articles)
These articles have `pol_code = 'CTN'` but are surcharge articles with actual POD codes:
- All are "Waiver (CTN)" surcharge articles
- Actual POD codes are correct (CKY, COO, FNA)
- POL should likely be Antwerp (ANR) for West Africa routes
- **Action:** Create data cleanup script to:
  1. Find articles with `pol_code = 'CTN'` and category like "surcharge"
  2. For West Africa routes (POD: CKY, COO, FNA, etc.), set `pol_code = 'ANR'`
  3. Re-run backfill to resolve ports

### Priority 3: NMT Articles (6 articles)
These articles have `pod_code = 'NMT'` which is a carrier, not a port:
- NMT is a shipping line, not a port code
- **Action:** Manual review needed to determine correct POD codes
- These may need to be corrected by business users based on actual routes

## Testing

All aliases verified working with `PortResolutionService::resolveOne()`:
- ✅ `resolveOne('VLS', 'SEA')` → FLU (Flushing)
- ✅ `resolveOne('HULL', 'SEA')` → GBHUL (Hull)
- ✅ `resolveOne('ETS', 'SEA')` → null (correct, not a port)
- ✅ `resolveOne('CTN', 'SEA')` → null (correct, not a port)
- ✅ `resolveOne('NMT', 'SEA')` → null (correct, not a port)

## Next Steps

1. ✅ **DONE:** Run backfill command without `--dry-run` to populate foreign keys for all resolved ports
2. ⏳ **TODO:** Create data cleanup scripts for ETS and CTN articles (separate task)
3. ⏳ **TODO:** Manual review of NMT articles to determine correct POD codes (separate task)
4. ✅ **DONE:** Verify all port aliases are working correctly
