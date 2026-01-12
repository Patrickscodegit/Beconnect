# Production Purchase Price Breakdown Fix

## Problem

In production, articles show "Total Purchase Cost" but the breakdown (Base Freight + Surcharges) is missing in the UI.

## Root Cause

- Articles were synced before the `purchase_price_breakdown` feature was added
- The breakdown is built from Grimaldi purchase rates (CarrierPurchaseTariff)
- The breakdown needs to be backfilled for existing articles

## Solution

Use the enhanced `purchase-prices:sync-to-articles` command to sync purchase prices and build breakdowns from existing tariffs.

## Pre-Flight Checks (SSH into Production)

### 1. SSH into Production

```bash
ssh forge@bconnect.64.226.120.45.nip.io
cd app.belgaco.be
```

### 2. Verify Migration Status

Check if the `purchase_price_breakdown` column exists:

```bash
php artisan tinker --execute="echo \Illuminate\Support\Facades\Schema::hasColumn('robaws_articles_cache', 'purchase_price_breakdown') ? 'YES - Column exists' : 'NO - Column missing';"
```

If the column doesn't exist, check migration status:

```bash
php artisan migrate:status | grep purchase_price_breakdown
```

If the migration hasn't run, run migrations:

```bash
php artisan migrate
```

### 3. Check Current Data State

Count articles with cost_price but missing breakdown:

```bash
php artisan tinker --execute="
\$withCost = \App\Models\RobawsArticleCache::whereNotNull('cost_price')->where('cost_price', '>', 0)->count();
\$withBreakdown = \App\Models\RobawsArticleCache::whereNotNull('purchase_price_breakdown')->whereRaw(\"purchase_price_breakdown != 'null'\")->whereRaw(\"purchase_price_breakdown != '[]'\")->count();
echo 'Articles with cost_price: ' . \$withCost . PHP_EOL;
echo 'Articles with breakdown: ' . \$withBreakdown . PHP_EOL;
echo 'Articles missing breakdown: ' . (\$withCost - \$withBreakdown) . PHP_EOL;
"
```

### 4. Verify Articles Have Tariffs

Sample check to ensure articles have associated tariffs:

```bash
php artisan tinker --execute="
\$article = \App\Models\RobawsArticleCache::whereNotNull('cost_price')->where('cost_price', '>', 0)->first();
if (\$article) {
    \$tariffCount = \App\Models\CarrierPurchaseTariff::whereHas('carrierArticleMapping', function(\$q) use (\$article) {
        \$q->where('article_id', \$article->id);
    })->count();
    echo 'Sample article ID: ' . \$article->id . PHP_EOL;
    echo 'Associated tariffs: ' . \$tariffCount . PHP_EOL;
} else {
    echo 'No articles with cost_price found' . PHP_EOL;
}
"
```

## Execution Steps

### Step 1: Dry-Run (Recommended First)

Run the command with `--dry-run` to see what would happen:

```bash
php artisan purchase-prices:sync-to-articles --dry-run --missing-only
```

This will show:
- How many articles would be processed
- How many breakdowns would be added
- No actual changes will be made

### Step 2: Sync All Articles (If Needed)

If you want to sync all articles (not just missing ones):

```bash
php artisan purchase-prices:sync-to-articles --dry-run
```

### Step 3: Execute the Sync

Once you've reviewed the dry-run output, run the actual sync:

```bash
php artisan purchase-prices:sync-to-articles --missing-only
```

This will:
- Only sync articles missing `purchase_price_breakdown`
- Build breakdowns from existing Grimaldi purchase rates
- Recalculate `cost_price` to match breakdown total (expected behavior)

### Step 4: Verify Results

Check that breakdowns were added:

```bash
php artisan tinker --execute="
\$withBreakdown = \App\Models\RobawsArticleCache::whereNotNull('purchase_price_breakdown')->whereRaw(\"purchase_price_breakdown != 'null'\")->whereRaw(\"purchase_price_breakdown != '[]'\")->count();
echo 'Articles with breakdown: ' . \$withBreakdown . PHP_EOL;
"
```

## Command Options

- `--dry-run`: Show what would be synced without making changes
- `--missing-only`: Only sync articles missing `purchase_price_breakdown`
- `--force`: Force sync even if already synced (default behavior already does this)

## Expected Behavior

- `cost_price` will be recalculated from tariffs to match the breakdown total
- This is expected behavior since `cost_price` should always correspond with the breakdown
- All data comes from existing tariffs in the database (no data loss)

## Safety Notes

- The command is idempotent (safe to run multiple times)
- All data comes from existing tariffs (reconstructable)
- Migration only adds a column (reversible)
- Can verify with dry-run first

## Troubleshooting

If errors occur:
1. Check Laravel logs: `tail -f storage/logs/laravel.log`
2. Verify tariffs exist for articles: Use the tinker command above
3. Check database connection
4. Verify migrations have run
