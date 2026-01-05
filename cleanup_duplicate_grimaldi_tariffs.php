<?php

/**
 * Cleanup Script: Remove Duplicate Grimaldi Purchase Tariffs
 * 
 * This script removes duplicate tariffs for Grimaldi carrier (ID: 15)
 * where multiple tariffs exist for the same mapping and effective_from date.
 * 
 * Strategy:
 * - For each (mapping_id, effective_from) group with duplicates:
 *   - Keep the most recent tariff (highest ID, or most recent created_at)
 *   - Delete all other duplicates
 * 
 * Safety:
 * - Dry-run mode by default (set $dryRun = false to actually delete)
 * - Reports what will be deleted before deletion
 * - Only affects Grimaldi carrier (ID: 15)
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$dryRun = true; // Set to false to actually delete
$carrierId = 15; // Grimaldi

echo "=== Grimaldi Tariff Duplicate Cleanup ===\n\n";
echo "Mode: " . ($dryRun ? "DRY RUN (no deletions)" : "LIVE (will delete)") . "\n";
echo "Carrier ID: {$carrierId} (Grimaldi)\n\n";

// Find all Grimaldi mappings
$mappings = \App\Models\CarrierArticleMapping::where('carrier_id', $carrierId)->get();
echo "Found {$mappings->count()} Grimaldi mappings\n\n";

$totalDuplicates = 0;
$totalKept = 0;
$totalDeleted = 0;
$duplicateGroups = [];

foreach ($mappings as $mapping) {
    // Find duplicate tariffs (same mapping + same effective_from)
    $duplicateGroups = \App\Models\CarrierPurchaseTariff::where('carrier_article_mapping_id', $mapping->id)
        ->selectRaw('effective_from, COUNT(*) as count')
        ->groupBy('effective_from')
        ->havingRaw('COUNT(*) > 1')
        ->get();
    
    if ($duplicateGroups->isEmpty()) {
        continue; // No duplicates for this mapping
    }
    
    foreach ($duplicateGroups as $group) {
        $effectiveFrom = $group->effective_from;
        $count = $group->count;
        
        // Get all tariffs for this mapping + effective_from
        $tariffs = \App\Models\CarrierPurchaseTariff::where('carrier_article_mapping_id', $mapping->id)
            ->where('effective_from', $effectiveFrom)
            ->orderBy('id', 'desc') // Keep the highest ID (most recent)
            ->get();
        
        if ($tariffs->count() <= 1) {
            continue;
        }
        
        // Keep the first one (highest ID), delete the rest
        $keep = $tariffs->first();
        $toDelete = $tariffs->skip(1);
        
        $duplicateCount = $toDelete->count();
        $totalDuplicates += $duplicateCount;
        $totalKept += 1;
        
        echo "Mapping ID {$mapping->id} (Effective: {$effectiveFrom}):\n";
        echo "  Keeping: Tariff ID {$keep->id} (created: {$keep->created_at})\n";
        echo "  Deleting: {$duplicateCount} duplicate(s)\n";
        
        if (!$dryRun) {
            foreach ($toDelete as $tariff) {
                $tariff->delete();
                $totalDeleted++;
            }
        }
        
        echo "\n";
    }
}

echo "=== Summary ===\n";
echo "Total duplicate groups found: " . count($duplicateGroups) . "\n";
echo "Total duplicates to remove: {$totalDuplicates}\n";
echo "Total tariffs to keep: {$totalKept}\n";

if ($dryRun) {
    echo "\n⚠️  DRY RUN MODE - No deletions performed\n";
    echo "Set \$dryRun = false to actually delete duplicates\n";
} else {
    echo "Total tariffs deleted: {$totalDeleted}\n";
    echo "\n✅ Cleanup completed!\n";
}

// Verify cleanup
if (!$dryRun) {
    $remaining = \App\Models\CarrierPurchaseTariff::whereHas('carrierArticleMapping', function($q) use ($carrierId) {
        $q->where('carrier_id', $carrierId);
    })->count();
    
    echo "\nRemaining Grimaldi tariffs: {$remaining}\n";
    
    // Check for remaining duplicates
    $remainingDuplicates = \App\Models\CarrierPurchaseTariff::whereHas('carrierArticleMapping', function($q) use ($carrierId) {
        $q->where('carrier_id', $carrierId);
    })
    ->selectRaw('carrier_article_mapping_id, effective_from, COUNT(*) as count')
    ->groupBy('carrier_article_mapping_id', 'effective_from')
    ->havingRaw('COUNT(*) > 1')
    ->count();
    
    if ($remainingDuplicates > 0) {
        echo "⚠️  Warning: {$remainingDuplicates} duplicate groups still exist\n";
    } else {
        echo "✅ No duplicates remaining\n";
    }
}

