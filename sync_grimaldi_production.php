<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Syncing Grimaldi Data ===\n\n";

// Step 1: Sync Category Groups
echo "Step 1: Category Groups...\n";\App\Models\CarrierCategoryGroup::updateOrCreate(["carrier_id" => 15, "code" => "CARS"], ["sort_order" => 1, "is_active" => true]);
\App\Models\CarrierCategoryGroup::updateOrCreate(["carrier_id" => 15, "code" => "SMALL_VANS"], ["sort_order" => 2, "is_active" => true]);
\App\Models\CarrierCategoryGroup::updateOrCreate(["carrier_id" => 15, "code" => "BIG_VANS"], ["sort_order" => 3, "is_active" => true]);
\App\Models\CarrierCategoryGroup::updateOrCreate(["carrier_id" => 15, "code" => "LM_CARGO_TRUCKS"], ["sort_order" => 4, "is_active" => true]);
\App\Models\CarrierCategoryGroup::updateOrCreate(["carrier_id" => 15, "code" => "LM_CARGO_TRAILERS"], ["sort_order" => 5, "is_active" => true]);

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

// Step 3: Sync Freight Mappings
echo "Step 2: Freight Mappings...\n";
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRABICAR")->first();
if ($article) {
    $localCatIds = [1];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Car Abidjan (GANRABICAR)", "port_ids" => [26], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 1, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRABISV")->first();
if ($article) {
    $localCatIds = [2];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Small Van Abidjan (GANRABISV)", "port_ids" => [26], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 2, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRABIBV")->first();
if ($article) {
    $localCatIds = [3];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Big Van Abidjan (GANRABIBV)", "port_ids" => [26], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 3, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRABIHH")->first();
if ($article) {
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "LM Cargo Abidjan (GANRABIHH)", "port_ids" => [26], "category_group_ids" => null, "sort_order" => 4, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRBANBV")->first();
if ($article) {
    $localCatIds = [3];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Big Van Banjul (GANRBANBV)", "port_ids" => [73], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 5, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRBANCAR")->first();
if ($article) {
    $localCatIds = [1];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Car Banjul (GANRBANCAR)", "port_ids" => [73], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 6, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRBANHH")->first();
if ($article) {
    $localCatIds = [4,9];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "LM Cargo Banjul (GANRBANHH)", "port_ids" => [73], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 7, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRBANSV")->first();
if ($article) {
    $localCatIds = [2];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Small Van Banjul (GANRBANSV)", "port_ids" => [73], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 8, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRBATBV")->first();
if ($article) {
    $localCatIds = [3];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Big Van Bata (GANRBATBV)", "port_ids" => [76], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 9, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRBATCAR")->first();
if ($article) {
    $localCatIds = [1];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Car Bata (GANRBATCAR)", "port_ids" => [76], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 10, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRBATHH")->first();
if ($article) {
    $localCatIds = [4,9];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "LM Cargo Bata (GANRBATHH)", "port_ids" => [76], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 11, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRBATSV")->first();
if ($article) {
    $localCatIds = [2];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Small Van Bata (GANRBATSV)", "port_ids" => [76], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 12, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRCASBV")->first();
if ($article) {
    $localCatIds = [3];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Big Van Casablanca (GANRCASBV)", "port_ids" => [20], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 13, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRCASCAR")->first();
if ($article) {
    $localCatIds = [1];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Car Casablanca (GANRCASCAR)", "port_ids" => [20], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 14, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRCASHH")->first();
if ($article) {
    $localCatIds = [4,9];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "LM Cargo Casablanca (GANRCASHH)", "port_ids" => [20], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 15, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRCASSV")->first();
if ($article) {
    $localCatIds = [2];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Small Van Casablanca (GANRCASSV)", "port_ids" => [20], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 16, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRCONHH")->first();
if ($article) {
    $localCatIds = [4,9];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "LM Cargo Conakry (GANRCONHH)", "port_ids" => [64], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 17, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRCONBV")->first();
if ($article) {
    $localCatIds = [3];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Big Van Conakry (GANRCONBV)", "port_ids" => [64], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 18, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRCONCAR")->first();
if ($article) {
    $localCatIds = [1];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Car Conakry (GANRCONCAR)", "port_ids" => [64], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 19, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRCONSV")->first();
if ($article) {
    $localCatIds = [2];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Small Van Conakry (GANRCONSV)", "port_ids" => [64], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 20, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRCOTHH")->first();
if ($article) {
    $localCatIds = [4,9];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "LM Cargo Cotonou (GANRCOTHH)", "port_ids" => [65], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 21, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRCOTBV")->first();
if ($article) {
    $localCatIds = [3];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Big Van Cotonou (GANRCOTBV)", "port_ids" => [65], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 22, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRCOTCAR")->first();
if ($article) {
    $localCatIds = [1];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Car Cotonou (GANRCOTCAR)", "port_ids" => [65], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 23, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRCOTSV")->first();
if ($article) {
    $localCatIds = [2];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Small Van Cotonou (GANRCOTSV)", "port_ids" => [65], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 24, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRDAKHH")->first();
if ($article) {
    $localCatIds = [4,9];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "LM Cargo Dakar (GANRDAKHH)", "port_ids" => [66], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 25, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRDAKBV")->first();
if ($article) {
    $localCatIds = [3];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Big Van Dakar (GANRDAKBV)", "port_ids" => [66], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 26, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRDAKCAR")->first();
if ($article) {
    $localCatIds = [1];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Car Dakar (GANRDAKCAR)", "port_ids" => [66], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 27, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRDAKSV")->first();
if ($article) {
    $localCatIds = [2];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Small Van Dakar (GANRDAKSV)", "port_ids" => [66], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 28, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRDOUBV")->first();
if ($article) {
    $localCatIds = [3];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Big Van Douala (GANRDOUBV)", "port_ids" => [68], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 29, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRDOUCAR")->first();
if ($article) {
    $localCatIds = [1];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Car Douala (GANRDOUCAR)", "port_ids" => [68], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 30, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRDOUHH")->first();
if ($article) {
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "LM Cargo Douala (GANRDOUHH)", "port_ids" => [68], "category_group_ids" => null, "sort_order" => 31, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRDOUSV")->first();
if ($article) {
    $localCatIds = [2];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Small Van Douala (GANRDOUSV)", "port_ids" => [68], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 32, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRFREHH")->first();
if ($article) {
    $localCatIds = [4,9];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "LM Cargo Freetown (GANRFREHH)", "port_ids" => [25], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 33, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRFREBV")->first();
if ($article) {
    $localCatIds = [3];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Big Van Freetown (GANRFREBV)", "port_ids" => [25], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 34, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRFRECAR")->first();
if ($article) {
    $localCatIds = [1];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Car Freetown (GANRFRECAR)", "port_ids" => [25], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 35, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRFRESV")->first();
if ($article) {
    $localCatIds = [2];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Small Van Freetown (GANRFRESV)", "port_ids" => [25], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 36, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRLAGHH")->first();
if ($article) {
    $localCatIds = [4,9];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "LM Cargo Lagos (GANRLAGHH)", "port_ids" => [16], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 37, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRLAGBV")->first();
if ($article) {
    $localCatIds = [3];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Big Van Lagos (GANRLAGBV)", "port_ids" => [16], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 38, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRLAGCAR")->first();
if ($article) {
    $localCatIds = [1];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Car Lagos (GANRLAGCAR)", "port_ids" => [16], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 39, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRLAGSV")->first();
if ($article) {
    $localCatIds = [2];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Small Van Lagos (GANRLAGSV)", "port_ids" => [16], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 40, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRLIBBV")->first();
if ($article) {
    $localCatIds = [3];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Big Van Libreville (GANRLIBBV)", "port_ids" => [24], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 41, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRLIBCAR")->first();
if ($article) {
    $localCatIds = [1];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Car Libreville (GANRLIBCAR)", "port_ids" => [24], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 42, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRLIBHH")->first();
if ($article) {
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "LM Cargo Libreville (GANRLIBHH)", "port_ids" => [24], "category_group_ids" => null, "sort_order" => 43, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRLIBSV")->first();
if ($article) {
    $localCatIds = [2];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Small Van Libreville (GANRLIBSV)", "port_ids" => [24], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 44, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRLOMCAR")->first();
if ($article) {
    $localCatIds = [1];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Car Lome (GANRLOMCAR)", "port_ids" => [70], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 45, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRLOMHH")->first();
if ($article) {
    $localCatIds = [4,9];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "LM Cargo Lome (GANRLOMHH)", "port_ids" => [70], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 46, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRLOMBV")->first();
if ($article) {
    $localCatIds = [3];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Big Van Lome (GANRLOMBV)", "port_ids" => [70], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 47, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRLOMSV")->first();
if ($article) {
    $localCatIds = [2];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Small Van Lome (GANRLOMSV)", "port_ids" => [70], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 48, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRLUABV")->first();
if ($article) {
    $localCatIds = [3];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Big Van Luanda (GANRLUABV)", "port_ids" => [74], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 49, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRLUACAR")->first();
if ($article) {
    $localCatIds = [1];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Car Luanda (GANRLUACAR)", "port_ids" => [74], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 50, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRLUAHH")->first();
if ($article) {
    $localCatIds = [4,9];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "LM Cargo Luanda (GANRLUAHH)", "port_ids" => [74], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 51, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRLUASV")->first();
if ($article) {
    $localCatIds = [2];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Small Van Luanda (GANRLUASV)", "port_ids" => [74], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 52, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRMALBV")->first();
if ($article) {
    $localCatIds = [3];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Big Van Malabo (GANRMALBV)", "port_ids" => [77], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 53, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRMALCAR")->first();
if ($article) {
    $localCatIds = [1];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Car Malabo (GANRMALCAR)", "port_ids" => [77], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 54, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRMALHH")->first();
if ($article) {
    $localCatIds = [4,9];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "LM Cargo Malabo (GANRMALHH)", "port_ids" => [77], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 55, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRMALSV")->first();
if ($article) {
    $localCatIds = [2];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Small Van Malabo (GANRMALSV)", "port_ids" => [77], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 56, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRMONBV")->first();
if ($article) {
    $localCatIds = [3];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Big Van Monrovia (GANRMONBV)", "port_ids" => [75], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 57, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRMONCAR")->first();
if ($article) {
    $localCatIds = [1];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Car Monrovia (GANRMONCAR)", "port_ids" => [75], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 58, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRMONHH")->first();
if ($article) {
    $localCatIds = [4,9];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "LM Cargo Monrovia (GANRMONHH)", "port_ids" => [75], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 59, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRMONSV")->first();
if ($article) {
    $localCatIds = [2];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Small Van Monrovia (GANRMONSV)", "port_ids" => [75], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 60, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRNOUBV")->first();
if ($article) {
    $localCatIds = [3];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Big Van Nouakchott (GANRNOUBV)", "port_ids" => [23], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 61, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRNOUCAR")->first();
if ($article) {
    $localCatIds = [1];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Car Nouakchott (GANRNOUCAR)", "port_ids" => [23], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 62, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRNOUHH")->first();
if ($article) {
    $localCatIds = [4,9];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "LM Cargo Nouakchott (GANRNOUHH)", "port_ids" => [23], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 63, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRNOUSV")->first();
if ($article) {
    $localCatIds = [2];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Small Van Nouakchott (GANRNOUSV)", "port_ids" => [23], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 64, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRPOIBV")->first();
if ($article) {
    $localCatIds = [3];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Big Van Pointe-Noire (GANRPOIBV)", "port_ids" => [28], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 65, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRPOICAR")->first();
if ($article) {
    $localCatIds = [1];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Car Pointe-Noire (GANRPOICAR)", "port_ids" => [28], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 66, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRPOIHH")->first();
if ($article) {
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "LM Cargo Pointe-Noire (GANRPOIHH)", "port_ids" => [28], "category_group_ids" => null, "sort_order" => 67, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRPOISV")->first();
if ($article) {
    $localCatIds = [2];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Small Van Pointe-Noire (GANRPOISV)", "port_ids" => [28], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 68, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRTAKBV")->first();
if ($article) {
    $localCatIds = [3];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Big Van Takoradi (GANRTAKBV)", "port_ids" => [80], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 69, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRTAKCAR")->first();
if ($article) {
    $localCatIds = [1];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Car Takoradi (GANRTAKCAR)", "port_ids" => [80], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 70, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRTAKHH")->first();
if ($article) {
    $localCatIds = [4,9];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "LM Cargo Takoradi (GANRTAKHH)", "port_ids" => [80], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 71, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRTAKSV")->first();
if ($article) {
    $localCatIds = [2];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Small Van Takoradi (GANRTAKSV)", "port_ids" => [80], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 72, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRTEMBV")->first();
if ($article) {
    $localCatIds = [3];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Big Van Tema (GANRTEMBV)", "port_ids" => [79], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 73, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRTEMCAR")->first();
if ($article) {
    $localCatIds = [1];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Car Tema (GANRTEMCAR)", "port_ids" => [79], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 74, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRTEMHH")->first();
if ($article) {
    $localCatIds = [4,9];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "LM Cargo Tema (GANRTEMHH)", "port_ids" => [79], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 75, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRTEMSV")->first();
if ($article) {
    $localCatIds = [2];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Small Van Tema (GANRTEMSV)", "port_ids" => [79], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 76, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRTFNCAR")->first();
if ($article) {
    $localCatIds = [1];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Car Tenerife (GANRTFNCAR)", "port_ids" => [78], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 77, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRTFNSV")->first();
if ($article) {
    $localCatIds = [2];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Small Van Tenerife (GANRTFNSV)", "port_ids" => [78], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 78, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRTFNBV")->first();
if ($article) {
    $localCatIds = [3];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "Big Van Tenerife (GANRTFNBV)", "port_ids" => [78], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 79, "is_active" => true]);
}
$article = \App\Models\RobawsArticleCache::where("article_code", "GANRTFNHH")->first();
if ($article) {
    $localCatIds = [4,9];
    $prodCatIds = array_map(function($localId) use ($idMap) { return $idMap[$localId] ?? null; }, $localCatIds);
    $prodCatIds = array_filter($prodCatIds);
    $prodCatIds = array_values($prodCatIds);
    \App\Models\CarrierArticleMapping::updateOrCreate(["carrier_id" => 15, "article_id" => $article->id], ["name" => "LM Cargo Tenerife (GANRTFNHH)", "port_ids" => [78], "category_group_ids" => empty($prodCatIds) ? null : $prodCatIds, "sort_order" => 80, "is_active" => true]);
}

echo "\n✅ Sync completed!\n";
