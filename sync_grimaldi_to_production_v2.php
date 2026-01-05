<?php

/**
 * Sync Grimaldi Category Groups and Freight Mappings to Production
 * 
 * This script properly handles:
 * 1. Category groups with display_name (required in production)
 * 2. Port ID mapping (local IDs -> production IDs via port codes)
 * 3. Category group ID mapping (local IDs -> production IDs via codes)
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Creating Production Sync Script ===\n\n";

// Load data
$localCategoryGroups = json_decode(file_get_contents(__DIR__ . '/local_category_groups.json'), true);
$localMappings = json_decode(file_get_contents(__DIR__ . '/local_freight_mappings.json'), true);
$localPortIds = json_decode(file_get_contents(__DIR__ . '/local_port_ids.json'), true);
$prodPortIds = json_decode(file_get_contents(__DIR__ . '/production_port_ids.json'), true);

// Create port ID mapping (local ID -> production ID via code)
$portIdMap = [];
foreach ($localPortIds as $code => $localId) {
    if (isset($prodPortIds[$code])) {
        $portIdMap[$localId] = $prodPortIds[$code];
    }
}

// Generate display names for category groups
$displayNames = [
    'CARS' => 'Cars',
    'SMALL_VANS' => 'Small Vans',
    'BIG_VANS' => 'Big Vans',
    'LM_CARGO_TRUCKS' => 'LM Cargo (Trucks)',
    'LM_CARGO_TRAILERS' => 'LM Cargo (Trailers)',
];

// Build sync script
$script = <<<'PHP'
<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Syncing Grimaldi Data to Production ===\n\n";

// Step 1: Sync Category Groups
echo "Step 1: Category Groups...\n";
PHP;

foreach ($localCategoryGroups as $group) {
    $code = $group['code'];
    $displayName = $displayNames[$code] ?? $code;
    $script .= sprintf(
        '\\App\\Models\\CarrierCategoryGroup::updateOrCreate(["carrier_id" => 15, "code" => "%s"], ["display_name" => "%s", "sort_order" => %d, "is_active" => %s]);' . "\n",
        $code,
        $displayName,
        $group['sort_order'],
        $group['is_active'] ? 'true' : 'false'
    );
}

$script .= "\n// Step 2: Get Category Group ID Mapping\n";
$script .= '$localCodes = ["CARS", "SMALL_VANS", "BIG_VANS", "LM_CARGO_TRUCKS", "LM_CARGO_TRAILERS"];' . "\n";
$script .= '$prodGroups = \App\Models\CarrierCategoryGroup::where("carrier_id", 15)->orderBy("sort_order")->get();' . "\n";
$script .= '$idMap = [];' . "\n";
$script .= 'foreach ($localCodes as $index => $code) {' . "\n";
$script .= '    $prod = $prodGroups->where("code", $code)->first();' . "\n";
$script .= '    if ($prod) {' . "\n";
$script .= '        $idMap[$index + 1] = $prod->id; // Map local ID (1-based) to production ID' . "\n";
$script .= '    }' . "\n";
$script .= '}' . "\n\n";

// Port ID mapping - need to format as PHP array
$portIdMapPhp = '[' . implode(', ', array_map(function($k, $v) { return "$k => $v"; }, array_keys($portIdMap), $portIdMap)) . ']';
$script .= "// Step 3: Port ID Mapping (local ID -> production ID)\n";
$script .= sprintf('$portIdMap = %s;' . "\n\n", $portIdMapPhp);

$script .= "// Step 4: Sync Freight Mappings\n";
$script .= 'echo "Step 2: Freight Mappings...\n";' . "\n";
$script .= '$updated = 0;' . "\n";
$script .= '$created = 0;' . "\n";
$script .= '$skipped = 0;' . "\n\n";

foreach ($localMappings as $mapping) {
    $articleCode = $mapping['article_code'];
    $name = addslashes($mapping['name']);
    $sortOrder = $mapping['sort_order'];
    $isActive = $mapping['is_active'] ? 'true' : 'false';
    
    // Map port IDs from local to production
    $localPortIds = $mapping['port_ids'];
    $prodPortIds = [];
    if ($localPortIds) {
        foreach ($localPortIds as $localPortId) {
            if (isset($portIdMap[$localPortId])) {
                $prodPortIds[] = $portIdMap[$localPortId];
            }
        }
    }
    $prodPortIdsJson = json_encode($prodPortIds);
    
    // Handle category_group_ids mapping
    if ($mapping['category_group_ids']) {
        $categoryGroupIdsJson = json_encode($mapping['category_group_ids']);
        $script .= sprintf('$article = \App\Models\RobawsArticleCache::where("article_code", "%s")->first();' . "\n", $articleCode);
        $script .= 'if ($article) {' . "\n";
        $script .= sprintf('    $localCatIds = %s;' . "\n", $categoryGroupIdsJson);
        $script .= '    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);' . "\n";
        $script .= '    $prodCatIds = array_filter($prodCatIds);' . "\n";
        $script .= '    $prodCatIds = array_values($prodCatIds);' . "\n";
        $script .= sprintf(
            '    $mapping = \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "%s", "port_ids" => %s, "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => %d, "is_active" => %s]);' . "\n",
            $name,
            $prodPortIdsJson,
            $sortOrder,
            $isActive
        );
        $script .= '    if ($mapping->wasRecentlyCreated) { $created++; } else { $updated++; }' . "\n";
        $script .= '} else { $skipped++; }' . "\n";
    } else {
        $script .= sprintf('$article = \App\Models\RobawsArticleCache::where("article_code", "%s")->first();' . "\n", $articleCode);
        $script .= 'if ($article) {' . "\n";
        $script .= sprintf(
            '    $mapping = \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "%s", "port_ids" => %s, "category_group_ids" => null, "sort_order" => %d, "is_active" => %s]);' . "\n",
            $name,
            $prodPortIdsJson,
            $sortOrder,
            $isActive
        );
        $script .= '    if ($mapping->wasRecentlyCreated) { $created++; } else { $updated++; }' . "\n";
        $script .= '} else { $skipped++; }' . "\n";
    }
}

$script .= "\necho \"\\n=== Summary ===\\n\";\n";
$script .= 'echo "Created: $created\\n";' . "\n";
$script .= 'echo "Updated: $updated\\n";' . "\n";
$script .= 'echo "Skipped: $skipped\\n";' . "\n";
$script .= 'echo "\\n✅ Sync completed!\\n";' . "\n";

file_put_contents('sync_grimaldi_production_v2.php', $script);
echo "Created sync script: sync_grimaldi_production_v2.php\n";
echo "Ready to upload and run on production.\n";

