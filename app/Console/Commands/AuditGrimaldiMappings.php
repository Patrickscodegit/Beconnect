<?php

namespace App\Console\Commands;

use App\Models\CarrierArticleMapping;
use App\Models\Port;
use App\Models\ShippingCarrier;
use Illuminate\Console\Command;

class AuditGrimaldiMappings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:grimaldi-mappings';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all Grimaldi carrier article mappings grouped by port and category';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Auditing Grimaldi Carrier Article Mappings...');
        $this->newLine();

        // Find Grimaldi carrier
        $carrier = ShippingCarrier::where('code', 'GRIMALDI')->first();
        if (!$carrier) {
            $this->error('❌ Grimaldi carrier not found.');
            return Command::FAILURE;
        }

        $this->info("✓ Found carrier: {$carrier->name} (ID: {$carrier->id})");
        $this->newLine();

        // Load all Grimaldi mappings with eager loading
        $mappings = CarrierArticleMapping::where('carrier_id', $carrier->id)
            ->with([
                'article' => function ($query) {
                    $query->select('id', 'pod_code', 'pod', 'article_code', 'article_name');
                },
                'purchaseTariffs' => function ($query) {
                    $query->active()
                        ->orderBy('effective_from', 'desc')
                        ->orderBy('sort_order', 'asc');
                }
            ])
            ->get();

        if ($mappings->isEmpty()) {
            $this->warn('⚠ No mappings found for Grimaldi carrier.');
            return Command::SUCCESS;
        }

        // Group mappings by port and category
        $grouped = [];
        
        foreach ($mappings as $mapping) {
            // Resolve destination port
            $portInfo = $this->getPortFromMapping($mapping);
            if (!$portInfo) {
                continue; // Skip if no port can be determined
            }

            $portCode = $portInfo['code'];
            $portName = $portInfo['name'];

            // Determine PDF category
            $category = $this->determinePdfCategory($mapping);
            if (!$category) {
                continue; // Skip if category cannot be determined
            }

            // Initialize port if not exists
            if (!isset($grouped[$portCode])) {
                $grouped[$portCode] = [
                    'name' => $portName,
                    'categories' => [],
                ];
            }

            // Get active tariff
            $tariff = $mapping->activePurchaseTariff();
            $tariffId = $tariff ? $tariff->id : null;

            // Add to category
            if (!isset($grouped[$portCode]['categories'][$category])) {
                $grouped[$portCode]['categories'][$category] = [];
            }

            $grouped[$portCode]['categories'][$category][] = [
                'mapping_id' => $mapping->id,
                'article_id' => $mapping->article?->id,
                'article_code' => $mapping->article?->article_code ?? 'N/A',
                'article_name' => $mapping->article?->article_name ?? 'N/A',
                'tariff_id' => $tariffId,
            ];
        }

        // Sort ports by code
        ksort($grouped);

        // Display results
        $totalMappings = 0;
        $totalWithTariffs = 0;

        foreach ($grouped as $portCode => $portData) {
            $this->info("PORT {$portCode} ({$portData['name']})");
            
            $categoryOrder = ['CAR', 'SVAN', 'BVAN', 'LM'];
            foreach ($categoryOrder as $category) {
                if (!isset($portData['categories'][$category])) {
                    continue;
                }

                foreach ($portData['categories'][$category] as $item) {
                    $totalMappings++;
                    $tariffStatus = $item['tariff_id'] ? "#{$item['tariff_id']}" : 'none';
                    $totalWithTariffs += $item['tariff_id'] ? 1 : 0;
                    
                    $this->line(sprintf(
                        "  - %-5s | article %s | mapping #%d | tariff: %s",
                        $category,
                        $item['article_code'],
                        $item['mapping_id'],
                        $tariffStatus
                    ));
                }
            }
            
            $this->newLine();
        }

        $this->info("Summary:");
        $this->info("  Total mappings: {$totalMappings}");
        $this->info("  Mappings with active tariffs: {$totalWithTariffs}");
        $this->info("  Mappings without tariffs: " . ($totalMappings - $totalWithTariffs));

        return Command::SUCCESS;
    }

    /**
     * Get port information from mapping
     * Reuses logic from GrimaldiPurchaseRatesOverviewService
     */
    protected function getPortFromMapping(CarrierArticleMapping $mapping): ?array
    {
        // Prefer POD from article
        if ($mapping->article) {
            $article = $mapping->article;
            
            // Try pod_code first
            if ($article->pod_code) {
                $port = Port::where('code', $article->pod_code)->first();
                if ($port) {
                    return [
                        'code' => $port->code,
                        'name' => $port->name,
                    ];
                }
            }

            // Try extracting from pod field (format: "Port Name (CODE), Country")
            if ($article->pod) {
                // Extract code from format like "Abidjan (ABJ), Côte d'Ivoire"
                if (preg_match('/\(([A-Z]{3,4})\)/', $article->pod, $matches)) {
                    $portCode = $matches[1];
                    $port = Port::where('code', $portCode)->first();
                    if ($port) {
                        return [
                            'code' => $port->code,
                            'name' => $port->name,
                        ];
                    }
                }
            }
        }

        // Fallback to first port in port_ids array
        $portIds = $mapping->port_ids ?? [];
        if (!empty($portIds) && is_array($portIds)) {
            $firstPortId = is_array($portIds) ? ($portIds[0] ?? null) : $portIds;
            if ($firstPortId) {
                $port = Port::find($firstPortId);
                if ($port) {
                    return [
                        'code' => $port->code,
                        'name' => $port->name,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Determine PDF category from mapping
     * Reuses logic from GrimaldiPurchaseRatesOverviewService
     */
    protected function determinePdfCategory(CarrierArticleMapping $mapping): ?string
    {
        // Prefer category groups
        $categoryGroupIds = $mapping->category_group_ids ?? [];
        if (!empty($categoryGroupIds)) {
            // Load category groups to check their codes
            $categoryGroups = \App\Models\CarrierCategoryGroup::whereIn('id', $categoryGroupIds)
                ->where('carrier_id', $mapping->carrier_id)
                ->get();

            foreach ($categoryGroups as $group) {
                $code = strtoupper($group->code ?? '');
                if ($code === 'CARS') {
                    return 'CAR';
                } elseif ($code === 'SMALL_VANS') {
                    return 'SVAN';
                } elseif ($code === 'BIG_VANS') {
                    return 'BVAN';
                } elseif ($code === 'LM_CARGO' || strpos($code, 'LM') !== false) {
                    return 'LM';
                }
            }
        }

        // Fallback to vehicle_categories
        $vehicleCategories = $mapping->vehicle_categories ?? [];
        if (!empty($vehicleCategories)) {
            foreach ($vehicleCategories as $category) {
                $category = strtolower($category ?? '');
                if ($category === 'car') {
                    return 'CAR';
                } elseif (in_array($category, ['small_van', 'smallvan'])) {
                    return 'SVAN';
                } elseif (in_array($category, ['big_van', 'bigvan'])) {
                    return 'BVAN';
                } elseif (in_array($category, ['truck', 'truckhead', 'trailer', 'lm', 'roro'])) {
                    return 'LM';
                }
            }
        }

        return null;
    }
}
