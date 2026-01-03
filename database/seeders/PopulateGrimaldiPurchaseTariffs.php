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
     * Purchase tariffs from PDF, organized by port code and category
     * Includes base freight and all surcharges
     * Effective date: 2026-01-01
     * Source: GRIMALDI BELGIUM - Tariff Sheet West Africa - used vehicles (1/01/26)
     */
    private array $pdfData = [
        'ABJ' => [ // Abidjan (ARIDJAN in PDF)
            'CAR' => [
                'base_freight' => 560,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 12,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 2,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 710,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 10,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 1260,
                'baf' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 10,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 20,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 450,
                'baf' => ['amount' => 75, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => 50,
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
        'FNA' => [ // Freetown
            'CAR' => [
                'base_freight' => 875,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 40,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 5,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 985,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 40,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 1740,
                'baf' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 40,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 20,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 765,
                'baf' => ['amount' => 75, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => 80,
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
        'BJL' => [ // Banjul (SABJUL in PDF)
            'CAR' => [
                'base_freight' => 675,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 26,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 5,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 1003,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 26,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 2029,
                'baf' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 26,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 20,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 880,
                'baf' => ['amount' => 75, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => 26,
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
        'LOS' => [ // Lagos
            'CAR' => [
                'base_freight' => 651,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Lagos
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 761,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Lagos
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 1220,
                'baf' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Lagos
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 20,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 540,
                'baf' => ['amount' => 75, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => null, // Not in PDF for Lagos
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
        'CAS' => [ // Casablanca (CABARLANCA/TEHERIFE in PDF)
            'CAR' => [
                'base_freight' => 655,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Casablanca
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 765,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Casablanca
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 1570,
                'baf' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Casablanca
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 20,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 605,
                'baf' => ['amount' => 75, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => null, // Not in PDF for Casablanca
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
        'CKY' => [ // Conakry (CONAKRY in PDF) - includes congestion + ICCM
            'CAR' => [
                'base_freight' => 555,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 52,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'iccm' => 67,
            ],
            'SMALL_VAN' => [
                'base_freight' => 735,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 80,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => ['amount' => 100, 'unit' => 'LUMPSUM'],
                'iccm' => 67,
            ],
            'BIG_VAN' => [
                'base_freight' => 1420,
                'baf' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 93,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 20,
                'congestion' => ['amount' => 200, 'unit' => 'LUMPSUM'],
                'iccm' => 67,
            ],
            'LM' => [
                'base_freight' => 450,
                'baf' => ['amount' => 75, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => null, // PDF says "see below" - complex calculation
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => ['amount' => 100, 'unit' => 'LM'],
                'iccm' => 67,
            ],
        ],
        'LFW' => [ // Lome (LUNES in PDF)
            'CAR' => [
                'base_freight' => 565,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Lome
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 645,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Lome
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 1330,
                'baf' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Lome
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 20,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 465,
                'baf' => ['amount' => 75, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => null, // Not in PDF for Lome
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
        'COO' => [ // Cotonou
            'CAR' => [
                'base_freight' => 605,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Cotonou
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => ['amount' => 150, 'unit' => 'LUMPSUM'], // "Cogn. addit." in PDF
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 685,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Cotonou
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => ['amount' => 100, 'unit' => 'LUMPSUM'], // "Cogn. addit." in PDF
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 1470,
                'baf' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Cotonou
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 20,
                'congestion' => ['amount' => 200, 'unit' => 'LUMPSUM'], // "Cogn. addit." in PDF
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 465,
                'baf' => ['amount' => 75, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => null, // Not in PDF for Cotonou
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => ['amount' => 100, 'unit' => 'LM'], // "Cogn. addit." in PDF
                'iccm' => null,
            ],
        ],
        'DKR' => [ // Dakar (not in PDF, keeping placeholder values)
            'CAR' => [
                'base_freight' => 525,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 635,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 1320,
                'baf' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 460,
                'baf' => ['amount' => 75, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
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
            foreach ($categories as $category => $categoryData) {
                if (!is_array($categoryData) || !isset($categoryData['base_freight'])) {
                    continue; // Skip invalid entries
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

                // Create or update purchase tariff with all surcharges
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
                        'base_freight_amount' => $categoryData['base_freight'],
                        'base_freight_unit' => $category === 'LM' ? 'LM' : 'LUMPSUM',
                        'baf_amount' => $categoryData['baf']['amount'] ?? null,
                        'baf_unit' => $categoryData['baf']['unit'] ?? null,
                        'ets_amount' => $categoryData['ets']['amount'] ?? null,
                        'ets_unit' => $categoryData['ets']['unit'] ?? null,
                        'port_additional_amount' => $categoryData['port_additional'] ?? null,
                        'port_additional_unit' => $categoryData['port_additional'] ? 'LUMPSUM' : null, // Default to LUMPSUM if amount is set
                        'admin_fxe_amount' => $categoryData['admin_fxe'] ?? null,
                        'admin_fxe_unit' => $categoryData['admin_fxe'] ? 'LUMPSUM' : null, // Default to LUMPSUM if amount is set
                        'thc_amount' => $categoryData['thc']['amount'] ?? null,
                        'thc_unit' => $categoryData['thc']['unit'] ?? null,
                        'measurement_costs_amount' => $categoryData['measurement_costs'] ?? null,
                        'measurement_costs_unit' => $categoryData['measurement_costs'] ? 'LUMPSUM' : null, // Default to LUMPSUM if amount is set
                        'congestion_surcharge_amount' => $categoryData['congestion']['amount'] ?? null,
                        'congestion_surcharge_unit' => $categoryData['congestion']['unit'] ?? null,
                        'iccm_amount' => $categoryData['iccm'] ?? null,
                        'iccm_unit' => $categoryData['iccm'] ? 'LUMPSUM' : null, // Default to LUMPSUM if amount is set
                        'source' => 'import',
                        'notes' => 'Grimaldi WAF purchase tariffs from PDF, effective 2026-01-01',
                    ]
                );

                $baseAmount = $categoryData['base_freight'];
                $unit = $category === 'LM' ? 'LM' : 'EUR';
                if ($tariff->wasRecentlyCreated) {
                    $stats['tariffs_created']++;
                    $this->command->info("    ✓ Created tariff: {$category} = {$baseAmount} {$unit}");
                } else {
                    $stats['tariffs_updated']++;
                    $this->command->info("    ✓ Updated tariff: {$category} = {$baseAmount} {$unit}");
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
                  ->orWhere(function ($q2) {
                      // Allow NULL shipping_line only if article code starts with GANR (Grimaldi pattern)
                      $q2->whereNull('shipping_line')
                         ->where('article_code', 'LIKE', 'GANR%')
                         ->whereRaw('LOWER(article_name) NOT LIKE ?', ['%nmt%']);
                  });
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
                      ->orWhere(function ($q2) {
                          // Allow NULL shipping_line only if article code starts with GANR (Grimaldi pattern)
                          $q2->whereNull('shipping_line')
                             ->where('article_code', 'LIKE', 'GANR%')
                             ->whereRaw('LOWER(article_name) NOT LIKE ?', ['%nmt%']);
                      });
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
                  ->orWhere(function ($q2) {
                      // Allow NULL shipping_line only if article code starts with GANR (Grimaldi pattern)
                      $q2->whereNull('shipping_line')
                         ->where('article_code', 'LIKE', 'GANR%')
                         ->whereRaw('LOWER(article_name) NOT LIKE ?', ['%nmt%']);
                  });
            })
            ->where('commodity_type', $commodityType)
            ->where('article_code', 'LIKE', 'GANR%') // Only Grimaldi article codes
            ->whereRaw('LOWER(article_name) NOT LIKE ?', ['%nmt%']) // Exclude NMT articles
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

