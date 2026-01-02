<?php

namespace Database\Seeders;

use App\Models\CarrierArticleMapping;
use App\Models\CarrierCategoryGroup;
use App\Models\CarrierPortGroup;
use App\Models\CarrierPurchaseTariff;
use App\Models\Port;
use App\Models\RobawsArticleCache;
use App\Models\ShippingCarrier;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class PopulateGrimaldiPurchaseTariffs extends Seeder
{
    /**
     * Base freight (Seafreight) amounts from PDF, organized by port code and category
     * Effective date: 2026-01-01
     */
    private array $pdfData = [
        'ABJ' => ['CAR' => 560, 'SMALL_VAN' => 710, 'BIG_VAN' => 1260, 'LM' => 450], // Abidjan (ARIDJAN in PDF)
        'FNA' => ['CAR' => 875, 'SMALL_VAN' => 985, 'BIG_VAN' => 1740, 'LM' => 765], // Freetown
        'BJL' => ['CAR' => 675, 'SMALL_VAN' => 1003, 'BIG_VAN' => 2029, 'LM' => 880], // Banjul (SABJUL in PDF)
        'LOS' => ['CAR' => 651, 'SMALL_VAN' => 761, 'BIG_VAN' => 1220, 'LM' => 540], // Lagos
        'CAS' => ['CAR' => 655, 'SMALL_VAN' => 765, 'BIG_VAN' => 1570, 'LM' => 605], // Casablanca/Tenerife (using CAS for Casablanca)
        'CKY' => ['CAR' => 555, 'SMALL_VAN' => 735, 'BIG_VAN' => 1420, 'LM' => 450], // Conakry (CORAFRY in PDF)
        'LFW' => ['CAR' => 565, 'SMALL_VAN' => 645, 'BIG_VAN' => 1330, 'LM' => 465], // Lome (LUNES in PDF)
        'COO' => ['CAR' => 605, 'SMALL_VAN' => 685, 'BIG_VAN' => 1470, 'LM' => 465], // Cotonou
        'DKR' => ['CAR' => 525, 'SMALL_VAN' => 635, 'BIG_VAN' => 1320, 'LM' => 460], // Dakar (from PDF)
    ];

    /**
     * Combined port labels from PDF that map to multiple port codes
     */
    private array $combinedPorts = [
        'SATA/MALABO' => ['MLW'], // May need to check actual port codes
        'CASABLANCA/TENERIFE' => ['CAS'], // Using CAS (Casablanca)
        'TEMA/TAKORADI' => ['TEM'], // May need to check actual port codes
    ];

    /**
     * Category to article code suffix mapping
     */
    private array $categorySuffixes = [
        'CAR' => 'CAR',
        'SMALL_VAN' => 'SV',
        'BIG_VAN' => 'BV',
        'LM' => 'HH',
    ];

    /**
     * Category to commodity type mapping (for article lookup)
     */
    private array $categoryToCommodityType = [
        'CAR' => 'Car',
        'SMALL_VAN' => 'Small Van',
        'BIG_VAN' => 'Big Van',
        'LM' => 'LM Cargo',
    ];

    /**
     * Port code to article code pattern mapping
     * Some ports use different codes in article names (e.g., LFW -> LOM, BJL -> BAN)
     */
    private array $portCodeToArticlePattern = [
        'LFW' => 'LOM', // Lome uses LOM in article codes
        'BJL' => 'BAN', // Banjul uses BAN in article codes
    ];

    private const EFFECTIVE_DATE = '2026-01-01';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🚢 Populating Grimaldi Purchase Tariffs from PDF (effective 2026-01-01)...');
        $this->command->newLine();

        // Find Grimaldi carrier
        $carrier = ShippingCarrier::where('code', 'GRIMALDI')->first();
        if (!$carrier) {
            $this->command->error('❌ Grimaldi carrier not found. Please run ShippingCarrierSeeder first.');
            return;
        }

        $this->command->info("✓ Found carrier: {$carrier->name} (ID: {$carrier->id})");

        // Get category groups
        $categoryGroups = $carrier->categoryGroups()->where('is_active', true)->get()->keyBy('code');
        
        // Get WAF port group
        $wafPortGroup = CarrierPortGroup::where('carrier_id', $carrier->id)
            ->where('code', 'Grimaldi_WAF')
            ->first();
        
        $wafPortGroupIds = $wafPortGroup ? [$wafPortGroup->id] : null;

        $stats = [
            'ports_processed' => 0,
            'mappings_found' => 0,
            'mappings_created' => 0,
            'tariffs_created' => 0,
            'tariffs_updated' => 0,
            'warnings' => 0,
        ];

        // Process each port in PDF data
        foreach ($this->pdfData as $portCode => $categories) {
            $port = Port::where('code', $portCode)->first();
            if (!$port) {
                $this->command->warn("  ⚠ Port code '{$portCode}' not found in database. Skipping.");
                $stats['warnings']++;
                continue;
            }

            $this->command->info("  Processing port: {$port->name} ({$portCode})");
            $stats['ports_processed']++;

            // Process each category for this port
            foreach ($categories as $category => $baseFreightAmount) {
                if ($baseFreightAmount === null) {
                    continue; // Skip null values
                }

                // Find or create mapping
                $mapping = $this->findOrCreateMapping(
                    $carrier,
                    $port,
                    $category,
                    $categoryGroups,
                    $wafPortGroupIds,
                    $stats
                );

                if (!$mapping) {
                    continue; // Warning already logged
                }

                // Deactivate older tariffs
                $this->deactivateOlderTariffs($mapping);

                // Create or update purchase tariff
                // Use Carbon::parse to ensure consistent date handling for updateOrCreate
                $tariff = CarrierPurchaseTariff::updateOrCreate(
                    [
                        'carrier_article_mapping_id' => $mapping->id,
                        'effective_from' => Carbon::parse(self::EFFECTIVE_DATE)->format('Y-m-d'),
                    ],
                    [
                        'effective_to' => null,
                        'is_active' => true,
                        'sort_order' => 0,
                        'currency' => 'EUR',
                        'base_freight_amount' => $baseFreightAmount,
                        'base_freight_unit' => $category === 'LM' ? 'LM' : 'LUMPSUM',
                        'source' => 'import',
                        'notes' => 'Grimaldi WAF base freight from PDF, effective 2026-01-01',
                    ]
                );

                if ($tariff->wasRecentlyCreated) {
                    $stats['tariffs_created']++;
                    $this->command->info("    ✓ Created tariff: {$category} = {$baseFreightAmount} " . ($category === 'LM' ? 'LM' : 'EUR'));
                } else {
                    $stats['tariffs_updated']++;
                    $this->command->info("    ✓ Updated tariff: {$category} = {$baseFreightAmount} " . ($category === 'LM' ? 'LM' : 'EUR'));
                }
            }
        }

        $this->command->newLine();
        $this->command->info('✅ Purchase tariffs populated successfully!');
        $this->command->info("  Ports processed: {$stats['ports_processed']}");
        $this->command->info("  Mappings found: {$stats['mappings_found']}");
        $this->command->info("  Mappings created: {$stats['mappings_created']}");
        $this->command->info("  Tariffs created: {$stats['tariffs_created']}");
        $this->command->info("  Tariffs updated: {$stats['tariffs_updated']}");
        if ($stats['warnings'] > 0) {
            $this->command->warn("  Warnings: {$stats['warnings']}");
        }
    }

    /**
     * Find or create CarrierArticleMapping for a port and category
     */
    private function findOrCreateMapping(
        ShippingCarrier $carrier,
        Port $port,
        string $category,
        $categoryGroups,
        ?array $wafPortGroupIds,
        array &$stats
    ): ?CarrierArticleMapping {
        // First, try to find article by code pattern
        $article = $this->findArticle($port, $category);

        if (!$article) {
            $this->command->warn("    ⚠ Article not found for {$port->code} / {$category}. Skipping tariff.");
            $stats['warnings']++;
            return null;
        }

        // Check if mapping already exists (carrier_id + article_id is unique)
        $existingMapping = CarrierArticleMapping::where('carrier_id', $carrier->id)
            ->where('article_id', $article->id)
            ->first();

        if ($existingMapping) {
            $stats['mappings_found']++;
            return $existingMapping;
        }

        // Mapping doesn't exist, create it following GrimaldiWestAfricaRulesSeeder pattern
        $mappingName = $this->generateMappingName($article, $port);
        $categoryGroupIds = $this->getCategoryGroupIds($category, $categoryGroups);

        $mapping = CarrierArticleMapping::updateOrCreate(
            [
                'carrier_id' => $carrier->id,
                'article_id' => $article->id,
            ],
            [
                'name' => $mappingName,
                'port_ids' => [$port->id],
                'port_group_ids' => $wafPortGroupIds,
                'category_group_ids' => $categoryGroupIds,
                'vehicle_categories' => null, // Mutually exclusive with category_group_ids
                'priority' => 10,
                'effective_from' => now()->subYear(),
                'is_active' => true,
            ]
        );

        if ($mapping->wasRecentlyCreated) {
            $stats['mappings_created']++;
            $this->command->info("    ✓ Created mapping: {$mappingName}");
        }
        
        return $mapping;
    }

    /**
     * Find RobawsArticleCache by article code pattern or description
     */
    private function findArticle(Port $port, string $category): ?RobawsArticleCache
    {
        // Build expected article code: GANR + port code + suffix
        $suffix = $this->categorySuffixes[$category];
        $expectedCode = 'GANR' . $port->code . $suffix;

        // Try exact match first
        $article = RobawsArticleCache::where('article_code', $expectedCode)
            ->where('is_parent_article', true)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereRaw('LOWER(shipping_line) LIKE ?', ['%grimaldi%'])
                  ->orWhereNull('shipping_line');
            })
            ->first();

        if ($article) {
            return $article;
        }

        // Try with alternative article code pattern (e.g., LFW -> LOM, BJL -> BAN)
        if (isset($this->portCodeToArticlePattern[$port->code])) {
            $altPattern = $this->portCodeToArticlePattern[$port->code];
            $altExpectedCode = 'GANR' . $altPattern . $suffix;
            
            $article = RobawsArticleCache::where('article_code', $altExpectedCode)
                ->where('is_active', true) // Allow non-parent articles too
                ->where(function ($q) {
                    $q->whereRaw('LOWER(shipping_line) LIKE ?', ['%grimaldi%'])
                      ->orWhereNull('shipping_line');
                })
                ->first();

            if ($article) {
                return $article;
            }
        }

        // Fallback: search by description (destination name + category keywords)
        $commodityType = $this->categoryToCommodityType[$category];
        $portName = strtolower($port->name);
        $portCode = strtolower($port->code);
        
        // Also try alternative article code pattern in fallback
        $altPattern = isset($this->portCodeToArticlePattern[$port->code]) 
            ? strtolower($this->portCodeToArticlePattern[$port->code]) 
            : null;

        $article = RobawsArticleCache::where('is_active', true) // Allow non-parent articles
            ->where(function ($q) {
                $q->whereRaw('LOWER(shipping_line) LIKE ?', ['%grimaldi%'])
                  ->orWhereNull('shipping_line');
            })
            ->where('commodity_type', $commodityType)
            ->where(function ($q) use ($portName, $portCode, $altPattern, $suffix) {
                $q->whereRaw('LOWER(pod) LIKE ?', ['%' . $portName . '%'])
                  ->orWhereRaw('LOWER(pod) LIKE ?', ['%' . $portCode . '%'])
                  ->orWhereRaw('LOWER(pod_code) LIKE ?', ['%' . $portCode . '%'])
                  ->orWhereRaw('LOWER(article_name) LIKE ?', ['%' . $portName . '%'])
                  ->orWhereRaw('LOWER(article_name) LIKE ?', ['%' . $portCode . '%'])
                  ->orWhereRaw('LOWER(article_code) LIKE ?', ['%ganr' . $portCode . '%'])
                  ->orWhereRaw('LOWER(article_code) LIKE ?', ['%ganr' . $portCode . strtolower($suffix) . '%']);
                
                // Also search by alternative pattern
                if ($altPattern) {
                    $q->orWhereRaw('LOWER(article_code) LIKE ?', ['%ganr' . $altPattern . '%'])
                      ->orWhereRaw('LOWER(article_code) LIKE ?', ['%ganr' . $altPattern . strtolower($suffix) . '%']);
                }
            })
            ->first();

        return $article;
    }

    /**
     * Get category group IDs for a category
     */
    private function getCategoryGroupIds(string $category, $categoryGroups): ?array
    {
        return match ($category) {
            'CAR' => $categoryGroups->has('CARS') ? [$categoryGroups['CARS']->id] : null,
            'SMALL_VAN' => $categoryGroups->has('SMALL_VANS') ? [$categoryGroups['SMALL_VANS']->id] : null,
            'BIG_VAN' => $categoryGroups->has('BIG_VANS') ? [$categoryGroups['BIG_VANS']->id] : null,
            'LM' => $this->getLmCargoGroupIds($categoryGroups),
            default => null,
        };
    }

    /**
     * Get LM Cargo category group IDs (try TRUCKS/TRAILERS first, fallback to LM_CARGO)
     */
    private function getLmCargoGroupIds($categoryGroups): ?array
    {
        $lmCargoTrucksId = $categoryGroups->has('LM_CARGO_TRUCKS') ? $categoryGroups['LM_CARGO_TRUCKS']->id : null;
        $lmCargoTrailersId = $categoryGroups->has('LM_CARGO_TRAILERS') ? $categoryGroups['LM_CARGO_TRAILERS']->id : null;
        $lmCargoId = $categoryGroups->has('LM_CARGO') ? $categoryGroups['LM_CARGO']->id : null;

        $ids = array_filter([$lmCargoTrucksId, $lmCargoTrailersId, $lmCargoId]);
        return !empty($ids) ? array_values($ids) : null;
    }

    /**
     * Generate mapping name (following GrimaldiWestAfricaRulesSeeder pattern)
     */
    private function generateMappingName(RobawsArticleCache $article, Port $port): string
    {
        $commodityType = $article->commodity_type ?? '';

        // Build mapping name: "CommodityType PortName (ArticleCode)"
        // Example: "Car Abidjan (GANRABICAR)"
        $name = $commodityType . ' ' . $port->name . ' (' . $article->article_code . ')';

        return $name;
    }

    /**
     * Deactivate older tariffs (effective_from < 2026-01-01)
     */
    private function deactivateOlderTariffs(CarrierArticleMapping $mapping): void
    {
        CarrierPurchaseTariff::where('carrier_article_mapping_id', $mapping->id)
            ->where('effective_from', '<', Carbon::parse(self::EFFECTIVE_DATE)->format('Y-m-d'))
            ->update(['is_active' => false]);
    }
}

