<?php

/**
 * Sync Grimaldi Category Groups and Freight Mappings to Production
 * 
 * This script:
 * 1. Updates/creates category groups in production to match local
 * 2. Updates freight mappings in production to match local (by article_code)
 * 
 * Usage: Run this script locally, it will SSH into production and sync
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Grimaldi Sync to Production ===\n\n";

// Load local data
$localCategoryGroups = json_decode(file_get_contents(__DIR__ . '/local_category_groups.json'), true);
$localMappings = json_decode(file_get_contents(__DIR__ . '/local_freight_mappings.json'), true);

echo "Loaded from local:\n";
echo "  - Category Groups: " . count($localCategoryGroups) . "\n";
echo "  - Freight Mappings: " . count($localMappings) . "\n\n";

// Build commands to run on production
$commands = [];

// Step 1: Sync Category Groups
echo "Step 1: Syncing Category Groups...\n";
$categoryGroupCommands = [];
foreach ($localCategoryGroups as $index => $group) {
    $categoryGroupCommands[] = sprintf(
        '\\App\\Models\\CarrierCategoryGroup::updateOrCreate(["carrier_id" => 15, "code" => "%s"], ["sort_order" => %d, "is_active" => %s]);',
        $group['code'],
        $group['sort_order'],
        $group['is_active'] ? 'true' : 'false'
    );
}

// Step 2: Get category group ID mapping (production IDs may differ)
$getIdMappingCommand = '
$localCodes = ["CARS", "SMALL_VANS", "BIG_VANS", "LM_CARGO_TRUCKS", "LM_CARGO_TRAILERS"];
$prodGroups = \App\Models\CarrierCategoryGroup::where("carrier_id", 15)->get();
$idMap = [];
foreach ($localCodes as $code) {
    $prod = $prodGroups->firstWhere("code", $code);
    if ($prod) {
        $localIndex = array_search($code, $localCodes);
        $idMap[$localIndex + 1] = $prod->id; // Local uses 1-based, map to production ID
    }
}
echo json_encode($idMap);
';

// Step 3: Sync Freight Mappings
echo "Step 2: Syncing Freight Mappings...\n";
$mappingCommands = [];
foreach ($localMappings as $mapping) {
    $articleCode = $mapping['article_code'];
    $portIds = json_encode($mapping['port_ids']);
    
    // Map category_group_ids from local IDs to production IDs
    $categoryGroupIds = null;
    if ($mapping['category_group_ids']) {
        // We'll need to map these after getting the ID mapping
        $categoryGroupIds = $mapping['category_group_ids'];
    }
    
    $mappingCommands[] = [
        'article_code' => $articleCode,
        'name' => $mapping['name'],
        'port_ids' => $mapping['port_ids'],
        'category_group_ids' => $categoryGroupIds,
        'sort_order' => $mapping['sort_order'],
        'is_active' => $mapping['is_active'],
    ];
}

// Create a comprehensive sync script
$syncScript = <<<'PHP'
<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Syncing Grimaldi Data ===\n\n";

// Step 1: Sync Category Groups
echo "Step 1: Category Groups...\n";
PHP;

foreach ($localCategoryGroups as $group) {
    $syncScript .= sprintf(
        '\\App\\Models\\CarrierCategoryGroup::updateOrCreate(["carrier_id" => 15, "code" => "%s"], ["sort_order" => %d, "is_active" => %s]);' . "\n",
        $group['code'],
        $group['sort_order'],
        $group['is_active'] ? 'true' : 'false'
    );
}

$syncScript .= "\n// Step 2: Get Category Group ID Mapping\n";
$syncScript .= '$localCodes = ["CARS", "SMALL_VANS", "BIG_VANS", "LM_CARGO_TRUCKS", "LM_CARGO_TRAILERS"];' . "\n";
$syncScript .= '$prodGroups = \App\Models\CarrierCategoryGroup::where("carrier_id", 15)->orderBy("sort_order")->get();' . "\n";
$syncScript .= '$idMap = [];' . "\n";
$syncScript .= 'foreach ($localCodes as $index => $code) {' . "\n";
$syncScript .= '    $prod = $prodGroups->where("code", $code)->first();' . "\n";
$syncScript .= '    if ($prod) {' . "\n";
$syncScript .= '        $idMap[$index + 1] = $prod->id; // Map local ID (1-based) to production ID' . "\n";
$syncScript .= '    }' . "\n";
$syncScript .= '}' . "\n\n";

$syncScript .= "// Step 3: Sync Freight Mappings\n";
$syncScript .= 'echo "Step 2: Freight Mappings...\n";' . "\n";

foreach ($localMappings as $mapping) {
    $articleCode = $mapping['article_code'];
    $name = addslashes($mapping['name']);
    $portIds = json_encode($mapping['port_ids']);
    $sortOrder = $mapping['sort_order'];
    $isActive = $mapping['is_active'] ? 'true' : 'false';
    
    // Handle category_group_ids mapping
    if ($mapping['category_group_ids']) {
        $categoryGroupIdsJson = json_encode($mapping['category_group_ids']);
        $syncScript .= sprintf(
            '$article = \App\Models\RobawsArticleCache::where("article_code", "%s")->first();' . "\n",
            $articleCode
        );
        $syncScript .= 'if ($article) {' . "\n";
        $syncScript .= sprintf(
            '    $localCatIds = %s;' . "\n",
            $categoryGroupIdsJson
        );
        $syncScript .= '    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);' . "\n";
        $syncScript .= '    $prodCatIds = array_filter($prodCatIds);' . "\n";
        $syncScript .= '    $prodCatIds = array_values($prodCatIds);' . "\n";
        $syncScript .= sprintf(
            '    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "%s", "port_ids" => %s, "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => %d, "is_active" => %s]);' . "\n",
            $name,
            $portIds,
            $sortOrder,
            $isActive
        );
        $syncScript .= '}' . "\n";
    } else {
        $syncScript .= sprintf(
            '$article = \App\Models\RobawsArticleCache::where("article_code", "%s")->first();' . "\n",
            $articleCode
        );
        $syncScript .= 'if ($article) {' . "\n";
        $syncScript .= sprintf(
            '    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "%s", "port_ids" => %s, "category_group_ids" => null, "sort_order" => %d, "is_active" => %s]);' . "\n",
            $name,
            $portIds,
            $sortOrder,
            $isActive
        );
        $syncScript .= '}' . "\n";
    }
}

$syncScript .= "\necho \"\\n✅ Sync completed!\\n\";\n";

file_put_contents('sync_grimaldi_production.php', $syncScript);
echo "Created sync script: sync_grimaldi_production.php\n";
echo "Upload and run this on production server.\n";

