<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Syncing Grimaldi Data to Production (FIXED) ===\n\n";

// Port code to article code pattern mapping
$portCodeToArticlePattern = [
    'DKR' => 'DAK',  // Dakar uses DAK in article codes
    'NKC' => 'NOU',  // Nouakchott uses NOU in article codes
    'PNR' => 'POI',  // Pointe-Noire uses POI in article codes
    'ROB' => 'MON',  // Monrovia uses MON in article codes
    'TKR' => 'TAK',  // Takoradi uses TAK in article codes
    'LFW' => 'LOM',  // Lome uses LOM in article codes
    'BJL' => 'BAN',  // Banjul uses BAN in article codes
    'LAD' => 'LUA',  // Luanda uses LUA in article codes
    'JED' => 'JED',  // Jeddah uses JED but may have GRANR prefix
];

// Category suffixes
$categorySuffixes = [
    'CAR' => 'CAR',
    'SMALL_VAN' => 'SV',
    'BIG_VAN' => 'BV',
    'LM_CARGO_TRUCKS' => 'HH',
    'LM_CARGO_TRAILERS' => 'HH',
];

// Step 1: Sync Category Groups
echo "Step 1: Category Groups...\n";
\App\Models\CarrierCategoryGroup::updateOrCreate(["carrier_id" => 15, "code" => "CARS"], ["display_name" => "Cars", "sort_order" => 1, "is_active" => true]);
\App\Models\CarrierCategoryGroup::updateOrCreate(["carrier_id" => 15, "code" => "SMALL_VANS"], ["display_name" => "Small Vans", "sort_order" => 2, "is_active" => true]);
\App\Models\CarrierCategoryGroup::updateOrCreate(["carrier_id" => 15, "code" => "BIG_VANS"], ["display_name" => "Big Vans", "sort_order" => 3, "is_active" => true]);
\App\Models\CarrierCategoryGroup::updateOrCreate(["carrier_id" => 15, "code" => "LM_CARGO_TRUCKS"], ["display_name" => "LM Cargo (Trucks)", "sort_order" => 4, "is_active" => true]);
\App\Models\CarrierCategoryGroup::updateOrCreate(["carrier_id" => 15, "code" => "LM_CARGO_TRAILERS"], ["display_name" => "LM Cargo (Trailers)", "sort_order" => 5, "is_active" => true]);

// Step 2: Get Category Group ID Mapping
$localCodes = ["CARS", "SMALL_VANS", "BIG_VANS", "LM_CARGO_TRUCKS", "LM_CARGO_TRAILERS"];
$prodGroups = \App\Models\CarrierCategoryGroup::where("carrier_id", 15)->orderBy("sort_order")->get();
$idMap = [];
foreach ($localCodes as $index => $code) {
    $prod = $prodGroups->where("code", $code)->first();
    if ($prod) {
        $idMap[$index + 1] = $prod->id; // Map local ID (1-based) to production ID
    }
}

// Step 3: Port ID Mapping (local ID -> production ID)
$portIdMap = [26 => 62, 21 => 87, 1 => 1, 46 => 110, 11 => 80, 31 => 95, 73 => 128, 15 => 84, 4 => 75, 76 => 132, 57 => 121, 60 => 124, 20 => 86, 40 => 104, 47 => 111, 64 => 63, 65 => 64, 19 => 85, 61 => 125, 36 => 100, 67 => 66, 66 => 65, 68 => 67, 43 => 107, 35 => 99, 18 => 18, 42 => 106, 69 => 68, 8 => 8, 25 => 91, 38 => 102, 13 => 82, 54 => 118, 2 => 73, 51 => 115, 49 => 113, 41 => 105, 30 => 94, 44 => 108, 74 => 130, 62 => 126, 24 => 90, 9 => 78, 70 => 69, 14 => 83, 16 => 16, 77 => 133, 27 => 92, 17 => 17, 50 => 114, 10 => 79, 52 => 116, 58 => 122, 23 => 89, 45 => 109, 39 => 103, 71 => 70, 28 => 24, 7 => 77, 29 => 93, 56 => 120, 75 => 131, 3 => 74, 81 => 136, 48 => 112, 37 => 101, 34 => 98, 32 => 96, 6 => 76, 55 => 119, 33 => 97, 79 => 134, 78 => 129, 80 => 135, 22 => 88, 59 => 123, 12 => 81, 72 => 71, 53 => 117, 63 => 127, 5 => 5];

// Step 4: Load local mappings
$localMappings = json_decode(file_get_contents(__DIR__ . '/local_freight_mappings.json'), true);

// Step 5: Sync Freight Mappings
echo "Step 2: Freight Mappings...\n";
$updated = 0;
$created = 0;
$skipped = 0;
$notFound = [];

foreach ($localMappings as $mapping) {
    $articleCode = $mapping['article_code'];
    $name = $mapping['name'];
    $sortOrder = $mapping['sort_order'];
    $isActive = $mapping['is_active'];
    
    // Extract port code from article code (e.g., GANRDKRBV -> DKR)
    $portCode = substr($articleCode, 4, 3);
    
    // Map port code to article code pattern if needed
    $articlePattern = $portCodeToArticlePattern[$portCode] ?? $portCode;
    
    // Build expected article code with pattern
    $suffix = substr($articleCode, 7); // Get suffix (BV, CAR, SV, HH)
    $expectedCode = 'GANR' . $articlePattern . $suffix;
    
    // Try exact match first
    $article = \App\Models\RobawsArticleCache::where('article_code', $expectedCode)->first();
    
    // If not found, try original code
    if (!$article) {
        $article = \App\Models\RobawsArticleCache::where('article_code', $articleCode)->first();
    }
    
    // For Jeddah, also try GRANR prefix (GRANRJED* instead of GANRJED*)
    if (!$article && $portCode === 'JED') {
        $granrCode = 'GRANR' . $articlePattern . $suffix;
        $article = \App\Models\RobawsArticleCache::where('article_code', $granrCode)->first();
    }
    
    // For Luanda, also try LUA pattern (GANRLUA* instead of GANRLAD*)
    if (!$article && $portCode === 'LAD') {
        $luaCode = 'GANRLUA' . $suffix;
        $article = \App\Models\RobawsArticleCache::where('article_code', $luaCode)->first();
    }
    
    // If still not found, try by pod_code and name pattern
    if (!$article && isset($portIdMap[$mapping['port_ids'][0] ?? null])) {
        $prodPortId = $portIdMap[$mapping['port_ids'][0]];
        $prodPort = \App\Models\Port::find($prodPortId);
        if ($prodPort) {
            $article = \App\Models\RobawsArticleCache::where('pod_code', $prodPort->code)
                ->where(function($q) use ($suffix) {
                    $q->whereRaw('LOWER(shipping_line) LIKE ?', ['%grimaldi%'])
                      ->orWhereNull('shipping_line')
                      ->orWhere('article_code', 'LIKE', 'GANR%')
                      ->orWhere('article_code', 'LIKE', 'GRANR%');
                })
                ->where(function($q) use ($suffix) {
                    if ($suffix === 'BV') {
                        $q->whereRaw('LOWER(article_name) LIKE ?', ['%big van%']);
                    } elseif ($suffix === 'CAR') {
                        $q->whereRaw('LOWER(article_name) LIKE ?', ['%car%'])->whereRaw('LOWER(article_name) NOT LIKE ?', ['%small van%']);
                    } elseif ($suffix === 'SV') {
                        $q->whereRaw('LOWER(article_name) LIKE ?', ['%small van%']);
                    } elseif ($suffix === 'HH') {
                        $q->whereRaw('LOWER(article_name) LIKE ?', ['%lm%']);
                    }
                })
                ->first();
        }
    }
    
    if ($article) {
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
        
        // Map category_group_ids
        $prodCatIds = null;
        if ($mapping['category_group_ids']) {
            $localCatIds = $mapping['category_group_ids'];
            $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
            $prodCatIds = array_filter($prodCatIds);
            $prodCatIds = array_values($prodCatIds);
            if (empty($prodCatIds)) {
                $prodCatIds = null;
            }
        }
        
        $mappingRecord = \App\Models\CarrierArticleMapping::updateOrCreate(
            ["carrier_id" => 15, "article_id" => $article->id],
            [
                "name" => $name,
                "port_ids" => $prodPortIds,
                "category_group_ids" => $prodCatIds,
                "sort_order" => $sortOrder,
                "is_active" => $isActive
            ]
        );
        
        if ($mappingRecord->wasRecentlyCreated) {
            $created++;
        } else {
            $updated++;
        }
    } else {
        $skipped++;
        $notFound[] = $articleCode . " (tried: $expectedCode)";
    }
}

echo "\n=== Summary ===\n";
echo "Created: $created\n";
echo "Updated: $updated\n";
echo "Skipped: $skipped\n";
if (count($notFound) > 0) {
    echo "\nNot found articles:\n";
    foreach (array_slice($notFound, 0, 20) as $code) {
        echo "  - $code\n";
    }
}
echo "\n✅ Sync completed!\n";

