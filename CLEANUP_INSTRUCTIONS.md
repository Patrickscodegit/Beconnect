# Grimaldi Tariff Cleanup Instructions

## Problem Summary

Local environment has **756 Grimaldi purchase tariffs** vs **127 in production** due to duplicate tariffs created by multiple seeder runs.

**Root Cause:** Missing unique constraint on `(carrier_article_mapping_id, effective_from)` allows duplicates.

## Cleanup Plan

### Step 1: Review Investigation Report

Read `INVESTIGATION_REPORT_GRIMALDI_TARIFFS.md` for full details.

### Step 2: Run Cleanup Script (Dry Run First)

```bash
# Test in dry-run mode (no deletions)
php cleanup_duplicate_grimaldi_tariffs.php
```

**Expected Output:**
- Will identify 644 duplicate tariffs to remove
- Will keep 80 tariffs (one per mapping for 2026-01-01)
- Will reduce total from 756 to 112 tariffs

### Step 3: Run Cleanup Script (Live Mode)

Edit `cleanup_duplicate_grimaldi_tariffs.php` and set:
```php
$dryRun = false; // Change from true to false
```

Then run:
```bash
php cleanup_duplicate_grimaldi_tariffs.php
```

**Expected Result:**
- 644 duplicate tariffs deleted
- 112 tariffs remaining (80 active + 32 inactive)
- Matches production structure

### Step 4: Add Unique Constraint

Run the migration to prevent future duplicates:

```bash
php artisan migrate
```

This will add a unique constraint on `(carrier_article_mapping_id, effective_from)`.

### Step 5: Verify Cleanup

```bash
php artisan tinker --execute="
\$total = \App\Models\CarrierPurchaseTariff::whereHas('carrierArticleMapping', function(\$q) { \$q->where('carrier_id', 15); })->count();
echo 'Total Grimaldi tariffs: ' . \$total . PHP_EOL;

\$duplicates = \App\Models\CarrierPurchaseTariff::whereHas('carrierArticleMapping', function(\$q) { \$q->where('carrier_id', 15); })
    ->selectRaw('carrier_article_mapping_id, effective_from, COUNT(*) as count')
    ->groupBy('carrier_article_mapping_id', 'effective_from')
    ->havingRaw('COUNT(*) > 1')
    ->count();
echo 'Duplicate groups: ' . \$duplicates . PHP_EOL;
"
```

**Expected:**
- Total: ~112 tariffs
- Duplicate groups: 0

## Files Created

1. **INVESTIGATION_REPORT_GRIMALDI_TARIFFS.md** - Full investigation findings
2. **cleanup_duplicate_grimaldi_tariffs.php** - Cleanup script
3. **database/migrations/2026_01_04_120000_add_unique_constraint_to_carrier_purchase_tariffs.php** - Migration to prevent future duplicates

## After Cleanup

- Local will have ~112 tariffs (matches production structure)
- No duplicates
- Unique constraint prevents future duplicates
- Seeder can be run safely without creating duplicates

