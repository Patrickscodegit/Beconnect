<?php
/**
 * Standalone script to export Grimaldi mappings from production
 * Run this on production server: php export_grimaldi_production.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Exporting Grimaldi Data from Production ===\n\n";

$outputDir = __DIR__ . '/storage/exports';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// Find Grimaldi carrier
$carrier = \App\Models\ShippingCarrier::where('code', 'GRIMALDI')
    ->orWhereRaw('LOWER(name) LIKE ?', ['%grimaldi%'])
    ->first();

if (!$carrier) {
    echo "❌ Grimaldi carrier not found!\n";
    exit(1);
}

echo "✅ Found carrier: {$carrier->name} (ID: {$carrier->id})\n\n";

// Export mappings
echo "📦 Exporting CarrierArticleMapping records...\n";
$mappings = \App\Models\CarrierArticleMapping::where('carrier_id', $carrier->id)
    ->with(['article:id,robaws_article_id,article_code,article_name,pod_code,pod'])
    ->get();

$mappingsData = $mappings->map(function ($mapping) {
    return [
        'id' => $mapping->id,
        'carrier_id' => $mapping->carrier_id,
        'article_id' => $mapping->article_id,
        'article_code' => $mapping->article?->article_code,
        'article_name' => $mapping->article?->article_name,
        'name' => $mapping->name,
        'port_ids' => $mapping->port_ids,
        'port_group_ids' => $mapping->port_group_ids,
        'vehicle_categories' => $mapping->vehicle_categories,
        'category_group_ids' => $mapping->category_group_ids,
        'vessel_names' => $mapping->vessel_names,
        'vessel_classes' => $mapping->vessel_classes,
        'is_active' => $mapping->is_active,
        'sort_order' => $mapping->sort_order,
    ];
})->toArray();

$mappingsFile = $outputDir . '/grimaldi_mappings.json';
file_put_contents($mappingsFile, json_encode($mappingsData, JSON_PRETTY_PRINT));
echo "✅ Exported " . count($mappingsData) . " mappings to: {$mappingsFile}\n";

// Export tariffs
echo "💰 Exporting CarrierPurchaseTariff records...\n";
$tariffIds = $mappings->pluck('id')->toArray();

$tariffs = \App\Models\CarrierPurchaseTariff::whereIn('carrier_article_mapping_id', $tariffIds)
    ->get();

$tariffsData = $tariffs->map(function ($tariff) {
    return [
        'id' => $tariff->id,
        'carrier_article_mapping_id' => $tariff->carrier_article_mapping_id,
        'effective_from' => $tariff->effective_from?->format('Y-m-d'),
        'effective_to' => $tariff->effective_to?->format('Y-m-d'),
        'update_date' => $tariff->update_date?->format('Y-m-d'),
        'validity_date' => $tariff->validity_date?->format('Y-m-d'),
        'is_active' => $tariff->is_active,
        'sort_order' => $tariff->sort_order,
        'currency' => $tariff->currency,
        'base_freight_amount' => $tariff->base_freight_amount,
        'base_freight_unit' => $tariff->base_freight_unit,
        'baf_amount' => $tariff->baf_amount,
        'baf_unit' => $tariff->baf_unit,
        'ets_amount' => $tariff->ets_amount,
        'ets_unit' => $tariff->ets_unit,
        'port_additional_amount' => $tariff->port_additional_amount,
        'port_additional_unit' => $tariff->port_additional_unit,
        'admin_fxe_amount' => $tariff->admin_fxe_amount,
        'admin_fxe_unit' => $tariff->admin_fxe_unit,
        'thc_amount' => $tariff->thc_amount,
        'thc_unit' => $tariff->thc_unit,
        'measurement_costs_amount' => $tariff->measurement_costs_amount,
        'measurement_costs_unit' => $tariff->measurement_costs_unit,
        'congestion_surcharge_amount' => $tariff->congestion_surcharge_amount,
        'congestion_surcharge_unit' => $tariff->congestion_surcharge_unit,
        'iccm_amount' => $tariff->iccm_amount,
        'iccm_unit' => $tariff->iccm_unit,
        'freight_tax_amount' => $tariff->freight_tax_amount,
        'freight_tax_unit' => $tariff->freight_tax_unit,
        'source' => $tariff->source,
        'notes' => $tariff->notes,
    ];
})->toArray();

$tariffsFile = $outputDir . '/grimaldi_tariffs.json';
file_put_contents($tariffsFile, json_encode($tariffsData, JSON_PRETTY_PRINT));
echo "✅ Exported " . count($tariffsData) . " tariffs to: {$tariffsFile}\n";

// Export reference data
echo "📋 Exporting reference data...\n";

// Ports
$portIds = collect($mappingsData)->pluck('port_ids')->flatten()->unique()->toArray();
$ports = \App\Models\Port::whereIn('id', $portIds)
    ->get(['id', 'code', 'name']);

$portsFile = $outputDir . '/grimaldi_ports_reference.json';
file_put_contents($portsFile, json_encode($ports->toArray(), JSON_PRETTY_PRINT));
echo "✅ Exported {$ports->count()} ports to: {$portsFile}\n";

// Category groups - export ALL for Grimaldi (not just referenced ones)
$categoryGroups = \App\Models\CarrierCategoryGroup::where('carrier_id', $carrier->id)
    ->with(['members:id,carrier_category_group_id,vehicle_category,is_active'])
    ->get();

$categoryGroupsData = $categoryGroups->map(function ($cg) {
    return [
        'id' => $cg->id,
        'code' => $cg->code,
        'display_name' => $cg->display_name,
        'aliases' => $cg->aliases,
        'priority' => $cg->priority,
        'sort_order' => $cg->sort_order,
        'effective_from' => $cg->effective_from?->format('Y-m-d'),
        'effective_to' => $cg->effective_to?->format('Y-m-d'),
        'is_active' => $cg->is_active,
        'members' => $cg->members->map(function ($member) {
            return [
                'vehicle_category' => $member->vehicle_category,
                'is_active' => $member->is_active,
            ];
        })->toArray(),
    ];
})->toArray();

$categoryGroupsFile = $outputDir . '/grimaldi_category_groups.json';
file_put_contents($categoryGroupsFile, json_encode($categoryGroupsData, JSON_PRETTY_PRINT));
echo "✅ Exported " . count($categoryGroupsData) . " category groups to: {$categoryGroupsFile}\n";

// Port groups - export ALL for Grimaldi
echo "🌐 Exporting CarrierPortGroup records...\n";
$portGroups = \App\Models\CarrierPortGroup::where('carrier_id', $carrier->id)
    ->with(['members:id,carrier_port_group_id,port_id,is_active'])
    ->get();

$portGroupsData = $portGroups->map(function ($pg) {
    return [
        'id' => $pg->id,
        'code' => $pg->code,
        'display_name' => $pg->display_name,
        'aliases' => $pg->aliases,
        'priority' => $pg->priority,
        'sort_order' => $pg->sort_order,
        'effective_from' => $pg->effective_from?->format('Y-m-d'),
        'effective_to' => $pg->effective_to?->format('Y-m-d'),
        'is_active' => $pg->is_active,
        'members' => $pg->members->map(function ($member) {
            return [
                'port_id' => $member->port_id,
                'is_active' => $member->is_active,
            ];
        })->toArray(),
    ];
})->toArray();

$portGroupsFile = $outputDir . '/grimaldi_port_groups.json';
file_put_contents($portGroupsFile, json_encode($portGroupsData, JSON_PRETTY_PRINT));
echo "✅ Exported " . count($portGroupsData) . " port groups to: {$portGroupsFile}\n";

// Export port groups reference (for ID mapping)
$portGroupsRefData = $portGroups->map(function ($pg) {
    return [
        'id' => $pg->id,
        'code' => $pg->code,
    ];
})->toArray();
$portGroupsRefFile = $outputDir . '/grimaldi_port_groups_reference.json';
file_put_contents($portGroupsRefFile, json_encode($portGroupsRefData, JSON_PRETTY_PRINT));

// Acceptance rules
echo "✅ Exporting CarrierAcceptanceRule records...\n";
$acceptanceRules = \App\Models\CarrierAcceptanceRule::where('carrier_id', $carrier->id)->get();
$acceptanceRulesData = $acceptanceRules->map(function ($rule) {
    return $rule->toArray();
})->toArray();

$acceptanceRulesFile = $outputDir . '/grimaldi_acceptance_rules.json';
file_put_contents($acceptanceRulesFile, json_encode($acceptanceRulesData, JSON_PRETTY_PRINT));
echo "✅ Exported " . count($acceptanceRulesData) . " acceptance rules to: {$acceptanceRulesFile}\n";

// Transform rules
echo "🔄 Exporting CarrierTransformRule records...\n";
$transformRules = \App\Models\CarrierTransformRule::where('carrier_id', $carrier->id)->get();
$transformRulesData = $transformRules->map(function ($rule) {
    return $rule->toArray();
})->toArray();

$transformRulesFile = $outputDir . '/grimaldi_transform_rules.json';
file_put_contents($transformRulesFile, json_encode($transformRulesData, JSON_PRETTY_PRINT));
echo "✅ Exported " . count($transformRulesData) . " transform rules to: {$transformRulesFile}\n";

// Surcharge rules
echo "💰 Exporting CarrierSurchargeRule records...\n";
$surchargeRules = \App\Models\CarrierSurchargeRule::where('carrier_id', $carrier->id)->get();
$surchargeRulesData = $surchargeRules->map(function ($rule) {
    return $rule->toArray();
})->toArray();

$surchargeRulesFile = $outputDir . '/grimaldi_surcharge_rules.json';
file_put_contents($surchargeRulesFile, json_encode($surchargeRulesData, JSON_PRETTY_PRINT));
echo "✅ Exported " . count($surchargeRulesData) . " surcharge rules to: {$surchargeRulesFile}\n";

// Surcharge article maps
echo "📋 Exporting CarrierSurchargeArticleMap records...\n";
$surchargeArticleMaps = \App\Models\CarrierSurchargeArticleMap::where('carrier_id', $carrier->id)
    ->with(['article:id,article_code'])
    ->get();
$surchargeArticleMapsData = $surchargeArticleMaps->map(function ($map) {
    $data = $map->toArray();
    $data['article_code'] = $map->article?->article_code;
    return $data;
})->toArray();

$surchargeArticleMapsFile = $outputDir . '/grimaldi_surcharge_article_maps.json';
file_put_contents($surchargeArticleMapsFile, json_encode($surchargeArticleMapsData, JSON_PRETTY_PRINT));
echo "✅ Exported " . count($surchargeArticleMapsData) . " surcharge article maps to: {$surchargeArticleMapsFile}\n";

// Clauses
echo "📜 Exporting CarrierClause records...\n";
$clauses = \App\Models\CarrierClause::where('carrier_id', $carrier->id)->get();
$clausesData = $clauses->map(function ($clause) {
    return $clause->toArray();
})->toArray();

$clausesFile = $outputDir . '/grimaldi_clauses.json';
file_put_contents($clausesFile, json_encode($clausesData, JSON_PRETTY_PRINT));
echo "✅ Exported " . count($clausesData) . " clauses to: {$clausesFile}\n";

// Summary
echo "\n📊 Export Summary:\n";
echo "   Mappings: " . count($mappingsData) . "\n";
echo "   Tariffs: " . count($tariffsData) . "\n";
echo "   Ports: {$ports->count()}\n";
echo "   Category Groups: " . count($categoryGroupsData) . "\n";
echo "   Port Groups: " . count($portGroupsData) . "\n";
echo "   Acceptance Rules: " . count($acceptanceRulesData) . "\n";
echo "   Transform Rules: " . count($transformRulesData) . "\n";
echo "   Surcharge Rules: " . count($surchargeRulesData) . "\n";
echo "   Surcharge Article Maps: " . count($surchargeArticleMapsData) . "\n";
echo "   Clauses: " . count($clausesData) . "\n";
echo "\n✅ Export completed! Files saved to: {$outputDir}\n";
