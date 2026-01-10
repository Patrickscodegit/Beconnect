<?php

namespace App\Console\Commands;

use App\Models\CarrierArticleMapping;
use App\Models\CarrierPurchaseTariff;
use App\Models\Port;
use App\Models\ShippingCarrier;
use App\Services\Carrier\CarrierLookupService;
use Illuminate\Console\Command;

class UpdateFreightTaxForGrimaldiTariffs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'grimaldi:update-freight-tax {--dry-run : Show what would be updated without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update Freight Tax fields for Grimaldi purchase tariffs (Tema, Takoradi, Cotonou) without overwriting other tariff data';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Find Grimaldi carrier using CarrierLookupService
        $carrier = app(CarrierLookupService::class)->findGrimaldi();
        
        if (!$carrier) {
            $this->error('❌ Grimaldi carrier not found.');
            return 1;
        }

        $this->info("✓ Found carrier: {$carrier->name} (ID: {$carrier->id})");
        $this->newLine();

        // Target ports
        $targetPortCodes = ['TEM', 'TKR', 'COO'];
        $targetPorts = Port::whereIn('code', $targetPortCodes)->get()->keyBy('code');
        
        if ($targetPorts->count() !== count($targetPortCodes)) {
            $missing = array_diff($targetPortCodes, $targetPorts->keys()->toArray());
            $this->warn("⚠ Warning: Some ports not found: " . implode(', ', $missing));
        }

        // Find all Grimaldi mappings for target ports
        $mappings = CarrierArticleMapping::where('carrier_id', $carrier->id)
            ->with([
                'article' => function ($query) {
                    $query->select('id', 'pod_code', 'article_code', 'article_name');
                },
                'purchaseTariffs' => function ($query) {
                    $query->active()
                        ->orderBy('effective_from', 'desc')
                        ->orderBy('sort_order', 'asc');
                }
            ])
            ->get();

        $stats = [
            'tema_updated' => 0,
            'takoradi_updated' => 0,
            'cotonou_updated' => 0,
            'skipped' => 0,
        ];

        foreach ($mappings as $mapping) {
            // Determine port from mapping
            $portCode = $this->getPortCodeFromMapping($mapping, $targetPorts);
            
            if (!$portCode || !in_array($portCode, $targetPortCodes)) {
                continue; // Skip mappings not for target ports
            }

            $tariff = $mapping->activePurchaseTariff();
            if (!$tariff) {
                continue; // Skip mappings without active tariffs
            }

            // Determine category from article code/name
            $category = $this->determineCategory($mapping);
            
            // Calculate freight tax based on port and category
            $freightTaxData = $this->calculateFreightTax($portCode, $category, $tariff);
            
            if ($freightTaxData === null) {
                $stats['skipped']++;
                continue; // Skip if we can't determine freight tax
            }

            // Check if update is needed
            $needsUpdate = false;
            if ($portCode === 'COO') {
                // Cotonou: always set freight_tax (it was missing)
                $needsUpdate = ($tariff->freight_tax_amount != $freightTaxData['amount']);
            } else {
                // Tema/Takoradi: update if freight_tax is missing or port_additional needs adjustment
                $needsUpdate = ($tariff->freight_tax_amount != $freightTaxData['amount'])
                    || ($tariff->port_additional_amount != $freightTaxData['port_additional']);
            }

            if (!$needsUpdate) {
                $stats['skipped']++;
                continue;
            }

            // Display what will be updated
            $portName = $targetPorts[$portCode]->name ?? $portCode;
            $articleCode = $mapping->article->article_code ?? 'N/A';
            
            $this->info("  {$portName} ({$portCode}) - {$category} - {$articleCode}:");
            $this->line("    Current: freight_tax={$tariff->freight_tax_amount}, port_additional={$tariff->port_additional_amount}");
            $this->line("    Update:  freight_tax={$freightTaxData['amount']}, port_additional={$freightTaxData['port_additional']}");

            if (!$dryRun) {
                // Update ONLY freight_tax and port_additional fields
                $tariff->freight_tax_amount = $freightTaxData['amount'];
                $tariff->freight_tax_unit = $freightTaxData['unit'];
                
                // For Tema/Takoradi, also update port_additional
                if (in_array($portCode, ['TEM', 'TKR'])) {
                    $tariff->port_additional_amount = $freightTaxData['port_additional'];
                }
                
                $tariff->save();
                
                $stats["{$portCode}_updated"]++;
                $this->line("    ✓ Updated");
            } else {
                $this->line("    [DRY RUN - would update]");
                $stats["{$portCode}_updated"]++;
            }
        }

        $this->newLine();
        $this->info('📊 Summary:');
        $this->line("  Tema updated: {$stats['tema_updated']}");
        $this->line("  Takoradi updated: {$stats['takoradi_updated']}");
        $this->line("  Cotonou updated: {$stats['cotonou_updated']}");
        $this->line("  Skipped: {$stats['skipped']}");

        if ($dryRun) {
            $this->newLine();
            $this->info('💡 Run without --dry-run to apply changes');
        }

        return 0;
    }

    /**
     * Get port code from mapping
     */
    private function getPortCodeFromMapping(CarrierArticleMapping $mapping, $targetPorts): ?string
    {
        // Try POD code from article
        if ($mapping->article && $mapping->article->pod_code) {
            $portCode = strtoupper($mapping->article->pod_code);
            if (isset($targetPorts[$portCode])) {
                return $portCode;
            }
        }

        // Try port_ids from mapping
        $portIds = $mapping->port_ids ?? [];
        if (!empty($portIds) && is_array($portIds)) {
            $firstPortId = $portIds[0];
            $port = Port::find($firstPortId);
            if ($port && isset($targetPorts[$port->code])) {
                return $port->code;
            }
        }

        return null;
    }

    /**
     * Determine category from mapping (CAR, SVAN, BVAN, LM)
     */
    private function determineCategory(CarrierArticleMapping $mapping): ?string
    {
        // Try category groups first
        $categoryGroupIds = $mapping->category_group_ids ?? [];
        if (!empty($categoryGroupIds)) {
            $categoryGroups = \App\Models\CarrierCategoryGroup::whereIn('id', $categoryGroupIds)
                ->where('carrier_id', $mapping->carrier_id)
                ->get();

            foreach ($categoryGroups as $group) {
                $code = strtoupper($group->code ?? '');
                if ($code === 'CARS') return 'CAR';
                if ($code === 'SMALL_VANS') return 'SVAN';
                if ($code === 'BIG_VANS') return 'BVAN';
                if ($code === 'LM_CARGO' || strpos($code, 'LM') !== false) return 'LM';
            }
        }

        // Try article code/name patterns
        if ($mapping->article) {
            $articleCode = strtoupper($mapping->article->article_code ?? '');
            $articleName = strtoupper($mapping->article->article_name ?? '');
            
            if (str_ends_with($articleCode, 'CAR') || str_contains($articleCode, 'CAR')) return 'CAR';
            if (str_ends_with($articleCode, 'SV') || str_contains($articleCode, 'SV')) return 'SVAN';
            if (str_ends_with($articleCode, 'BV') || str_contains($articleCode, 'BV')) return 'BVAN';
            if (str_ends_with($articleCode, 'HH') || str_contains($articleCode, 'LM') || str_contains($articleCode, 'HH')) return 'LM';
            
            if (str_contains($articleName, ' CAR ')) return 'CAR';
            if (str_contains($articleName, ' SMALL VAN')) return 'SVAN';
            if (str_contains($articleName, ' BIG VAN')) return 'BVAN';
            if (str_contains($articleName, ' LM ') || str_contains($articleName, ' LM CARGO')) return 'LM';
        }

        return null;
    }

    /**
     * Calculate freight tax based on port, category, and current tariff
     */
    private function calculateFreightTax(string $portCode, ?string $category, CarrierPurchaseTariff $tariff): ?array
    {
        if (!$category) {
            return null;
        }

        // Freight Tax values from plan
        $freightTaxAmount = null;
        $portAdditionalAmount = $tariff->port_additional_amount;

        if ($portCode === 'COO') {
            // Cotonou: Freight Tax is 5€ (CAR/SVAN/BVAN), 15€ (LM)
            if (in_array($category, ['CAR', 'SVAN', 'BVAN'])) {
                $freightTaxAmount = 5;
            } elseif ($category === 'LM') {
                $freightTaxAmount = 15;
            }
            // Port Additional stays as is (null per PDF)
        } elseif (in_array($portCode, ['TEM', 'TKR'])) {
            // Tema/Takoradi: Calculate from port_additional
            // If port_additional = 55 → freight_tax = 5, port_additional = 50 (CAR/SVAN/BVAN)
            // If port_additional = 120 → freight_tax = 20, port_additional = 100 (LM)
            
            if (in_array($category, ['CAR', 'SVAN', 'BVAN'])) {
                if ($portAdditionalAmount == 55) {
                    $freightTaxAmount = 5;
                    $portAdditionalAmount = 50;
                } else {
                    // If not exactly 55, assume freight_tax should be 5
                    $freightTaxAmount = 5;
                    // Adjust port_additional if it seems to include freight_tax
                    if ($portAdditionalAmount > 50) {
                        $portAdditionalAmount = $portAdditionalAmount - 5;
                    }
                }
            } elseif ($category === 'LM') {
                if ($portAdditionalAmount == 120) {
                    $freightTaxAmount = 20;
                    $portAdditionalAmount = 100;
                } else {
                    // If not exactly 120, assume freight_tax should be 20
                    $freightTaxAmount = 20;
                    // Adjust port_additional if it seems to include freight_tax
                    if ($portAdditionalAmount > 100) {
                        $portAdditionalAmount = $portAdditionalAmount - 20;
                    }
                }
            }
        }

        if ($freightTaxAmount === null) {
            return null;
        }

        return [
            'amount' => $freightTaxAmount,
            'unit' => 'LUMPSUM',
            'port_additional' => $portAdditionalAmount,
        ];
    }
}
