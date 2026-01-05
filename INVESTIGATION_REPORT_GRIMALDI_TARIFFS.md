# Investigation Report: Grimaldi Tariff Discrepancy

## Executive Summary

**Problem:** Local environment has 756 Grimaldi purchase tariffs vs 127 in production (6x difference).

**Root Cause:** Local has duplicate tariffs due to multiple seeder runs. The `carrier_purchase_tariffs` table lacks a unique constraint on `(carrier_article_mapping_id, effective_from)`, allowing the seeder to create duplicates instead of updating existing records.

**Recommendation:** Production is correct. Local should be cleaned to match production structure (one tariff per mapping per effective date).

---

## Detailed Findings

### Phase 1: Local Environment Analysis

**Tariff Distribution:**
- Total Tariffs: **756**
- Active Tariffs: **724** (all with `effective_from = 2026-01-01`)
- Inactive Tariffs: **32** (all with `effective_from = 2025-12-31`)

**Tariffs Per Mapping:**
- Minimum: 2 tariffs per mapping
- Maximum: 15 tariffs per mapping
- Average: **9.45 tariffs per mapping**
- Mappings with 1 tariff: **0**
- Mappings with 10+ tariffs: **56** (70% of all mappings)

**Duplicate Analysis:**
- **Duplicate groups found: 80** (ALL mappings have duplicates!)
- Each mapping has 10-12 duplicate tariffs with the same `effective_from` date (2026-01-01)
- All duplicates have identical data (same base_freight_amount, same surcharges)

**Sample: Abidjan CAR (Mapping ID 297)**
- Total Tariffs: **13**
- 1 inactive tariff: `effective_from = 2025-12-31`
- **12 active duplicate tariffs**: All with `effective_from = 2026-01-01`, all with `base_freight_amount = 560.00`

**Data Anomalies:**
- ✅ No null `effective_from` dates
- ❌ **3,372 overlapping active date ranges** (due to duplicates)
- ❌ All 80 mappings have duplicate tariffs

### Phase 2: Production Environment Analysis

**Tariff Distribution:**
- Total Tariffs: **127**
- Active Tariffs: **82** (with `effective_from = 2026-01-01`)
- Inactive Tariffs: **45** (with `effective_from = 2025-12-29` or `2025-12-30`)

**Tariffs Per Mapping:**
- Minimum: 1 tariff per mapping
- Maximum: 3 tariffs per mapping
- Average: **1.55 tariffs per mapping**
- Mappings with 1 tariff: **47** (57% of all mappings)
- Mappings with 10+ tariffs: **0**

**Duplicate Analysis:**
- **Duplicate groups found: 0** (NO duplicates!)
- Each mapping has 1-3 tariffs (mostly 1)

**Sample: Abidjan CAR (Mapping ID 32)**
- Total Tariffs: **3**
- 2 inactive tariffs: `effective_from = 2025-12-29` and `2025-12-30`
- **1 active tariff**: `effective_from = 2026-01-01`, `base_freight_amount = 560.00`

**Data Quality:**
- ✅ No duplicates
- ✅ Clean structure (one active tariff per mapping)
- ✅ Historical tariffs properly dated and inactive

### Phase 3: Seeder Behavior Analysis

**Seeder Logic:**
- Uses `CarrierPurchaseTariff::updateOrCreate()` with:
  - Unique key: `['carrier_article_mapping_id', 'effective_from']`
  - Updates all tariff fields if record exists

**Problem:**
- The `carrier_purchase_tariffs` table has **NO unique constraint** on `(carrier_article_mapping_id, effective_from)`
- Migration only creates indexes, not unique constraints
- `updateOrCreate()` relies on finding existing records, but if the query doesn't find them (due to timing, transactions, or other issues), it creates new records

**Why Duplicates Were Created:**
- Seeder was run multiple times locally
- Each run created new tariffs instead of updating existing ones
- No database-level constraint prevents duplicates

### Phase 4: Root Cause Identification

**Root Cause:**
1. **Missing Unique Constraint**: The `carrier_purchase_tariffs` table lacks a unique constraint on `(carrier_article_mapping_id, effective_from)`
2. **Multiple Seeder Runs**: The seeder was run multiple times locally, creating duplicates each time
3. **updateOrCreate() Limitation**: Without a unique constraint, `updateOrCreate()` can create duplicates if the initial query doesn't find existing records (race conditions, transaction isolation, etc.)

**Classification of Extra Tariffs:**
- **Type**: Duplicates (not historical data)
- **Pattern**: All duplicates have same `effective_from` (2026-01-01) and identical data
- **Count**: ~644 duplicate tariffs (756 total - 112 expected = 644 duplicates)
- **Impact**: 3,372 overlapping date ranges, performance issues, data confusion

---

## Recommendations

### Immediate Action: Clean Up Local Environment

**Option 1: Remove All Duplicates (Recommended)**
- Keep only the most recent tariff per mapping per effective date
- Remove all other duplicates
- This will reduce local from 756 to ~112 tariffs (80 mappings × 1.4 avg tariffs)

**Option 2: Add Unique Constraint + Clean Up**
- Add unique constraint to prevent future duplicates
- Clean up existing duplicates
- Re-run seeder to ensure consistency

### Long-Term Solution

1. **Add Unique Constraint:**
   ```php
   // Migration: add_unique_constraint_to_carrier_purchase_tariffs
   $table->unique(['carrier_article_mapping_id', 'effective_from'], 
                  'unique_mapping_effective_from');
   ```

2. **Improve Seeder:**
   - Add check before creating tariffs
   - Log when duplicates are found
   - Add cleanup step at start of seeder

3. **Prevention:**
   - Document that seeder should only be run once per effective date
   - Add validation in seeder to check for existing tariffs
   - Consider adding a `--force` flag to allow re-running

---

## Cleanup Script

See `cleanup_duplicate_grimaldi_tariffs.php` for the cleanup script that:
1. Identifies duplicate tariffs (same mapping + same effective_from)
2. Keeps the most recent tariff (by `id` or `created_at`)
3. Deletes all other duplicates
4. Reports cleanup statistics

---

## Conclusion

**Production is correct.** Local has duplicate tariffs due to multiple seeder runs without a unique constraint. The cleanup will:
- Reduce local tariffs from 756 to ~112
- Match production structure (1-3 tariffs per mapping)
- Eliminate 3,372 overlapping date ranges
- Improve query performance
- Ensure data consistency

**Next Steps:**
1. Review and approve cleanup script
2. Run cleanup on local environment
3. Add unique constraint to prevent future duplicates
4. Verify both environments are aligned

