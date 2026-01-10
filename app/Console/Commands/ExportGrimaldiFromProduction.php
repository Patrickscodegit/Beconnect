<?php

namespace App\Console\Commands;

use App\Models\CarrierArticleMapping;
use App\Models\CarrierPurchaseTariff;
use App\Models\ShippingCarrier;
use App\Services\Carrier\CarrierLookupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ExportGrimaldiFromProduction extends Command
{
    protected $signature = 'grimaldi:export-from-production
                          {--output=storage/exports : Output directory for export files}';

    protected $description = 'Export Grimaldi mappings and tariffs from production database to JSON files';

    protected CarrierLookupService $carrierLookup;

    public function __construct(CarrierLookupService $carrierLookup)
    {
        parent::__construct();
        $this->carrierLookup = $carrierLookup;
    }

    public function handle(): int
    {
        $outputDir = $this->option('output');
        
        // Ensure output directory exists
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $this->info('🔍 Finding Grimaldi carrier...');
        
        // Find Grimaldi carrier
        $carrier = $this->carrierLookup->findGrimaldi();
        
        if (!$carrier) {
            $this->error('❌ Grimaldi carrier not found!');
            return Command::FAILURE;
        }

        $this->info("✅ Found carrier: {$carrier->name} (ID: {$carrier->id})");
        $this->newLine();

        // Export mappings
        $this->info('📦 Exporting CarrierArticleMapping records...');
        $mappings = CarrierArticleMapping::where('carrier_id', $carrier->id)
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
        $this->info("✅ Exported {$mappings->count()} mappings to: {$mappingsFile}");

        // Export tariffs
        $this->info('💰 Exporting CarrierPurchaseTariff records...');
        $tariffIds = $mappings->pluck('id')->toArray();
        
        $tariffs = CarrierPurchaseTariff::whereIn('carrier_article_mapping_id', $tariffIds)
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
        $this->info("✅ Exported {$tariffs->count()} tariffs to: {$tariffsFile}");

        // Export reference data
        $this->info('📋 Exporting reference data...');
        
        // Ports (by code for mapping)
        $ports = \App\Models\Port::whereIn('id', collect($mappingsData)->pluck('port_ids')->flatten()->unique()->toArray())
            ->get(['id', 'code', 'name']);
        
        $portsFile = $outputDir . '/grimaldi_ports_reference.json';
        file_put_contents($portsFile, json_encode($ports->toArray(), JSON_PRETTY_PRINT));
        $this->info("✅ Exported {$ports->count()} ports to: {$portsFile}");

        // Category groups (by code for mapping)
        $categoryGroupIds = collect($mappingsData)->pluck('category_group_ids')->flatten()->unique()->toArray();
        $categoryGroups = \App\Models\CarrierCategoryGroup::where('carrier_id', $carrier->id)
            ->whereIn('id', $categoryGroupIds)
            ->get(['id', 'code', 'display_name']);
        
        $categoryGroupsFile = $outputDir . '/grimaldi_category_groups_reference.json';
        file_put_contents($categoryGroupsFile, json_encode($categoryGroups->toArray(), JSON_PRETTY_PRINT));
        $this->info("✅ Exported {$categoryGroups->count()} category groups to: {$categoryGroupsFile}");

        // Summary
        $this->newLine();
        $this->info('📊 Export Summary:');
        $this->table(
            ['Type', 'Count', 'File'],
            [
                ['Mappings', count($mappingsData), basename($mappingsFile)],
                ['Tariffs', count($tariffsData), basename($tariffsFile)],
                ['Ports (Reference)', $ports->count(), basename($portsFile)],
                ['Category Groups (Reference)', $categoryGroups->count(), basename($categoryGroupsFile)],
            ]
        );

        $this->newLine();
        $this->info("✅ Export completed! Files saved to: {$outputDir}");
        $this->info("📥 To download from production, use:");
        $this->line("   scp forge@bconnect.64.226.120.45.nip.io:{$outputDir}/*.json ./storage/exports/");

        return Command::SUCCESS;
    }
}
