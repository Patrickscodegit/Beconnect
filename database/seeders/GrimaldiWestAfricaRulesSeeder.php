<?php

namespace Database\Seeders;

use App\Models\CarrierAcceptanceRule;
use App\Models\CarrierArticleMapping;
use App\Models\CarrierCategoryGroup;
use App\Models\CarrierCategoryGroupMember;
use App\Models\CarrierClause;
use App\Models\CarrierPortGroup;
use App\Models\CarrierPortGroupMember;
use App\Models\CarrierSurchargeArticleMap;
use App\Models\CarrierSurchargeRule;
use App\Models\CarrierTransformRule;
use App\Models\Port;
use App\Models\RobawsArticleCache;
use App\Models\ShippingCarrier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GrimaldiWestAfricaRulesSeeder extends Seeder
{
    /**
     * Seed Grimaldi West Africa carrier rules
     * 
     * This seeder creates comprehensive rules for Grimaldi's West Africa routes including:
     * - Category groups (CARS, SMALL_VANS, BIG_VANS, LM_CARGO, HH)
     * - Acceptance rules (dimensions, weight, operational flags)
     * - Transform rules (overwidth LM recalculation)
     * - Surcharge rules (tracking, Conakry tiers, towing, tank inspection, overwidth)
     * - Article mappings
     * - Clauses
     */
    public function run(): void
    {
        $this->command->info('🚢 Seeding Grimaldi West Africa carrier rules...');
        $this->command->newLine();

        // Find Grimaldi carrier
        $carrier = ShippingCarrier::where('code', 'GRIMALDI')->first();
        if (!$carrier) {
            $this->command->error('❌ Grimaldi carrier not found. Please run ShippingCarrierSeeder first.');
            return;
        }

        $this->command->info("✓ Found carrier: {$carrier->name} (ID: {$carrier->id})");

        // Get West Africa ports
        $westAfricaPorts = $this->getWestAfricaPorts();
        $this->command->info("✓ Found " . count($westAfricaPorts) . " West Africa ports");

        // 1. Create port groups
        $portGroups = $this->createPortGroups($carrier);
        $this->command->info("✓ Created " . count($portGroups) . " port groups");

        // 2. Create category groups
        $categoryGroups = $this->createCategoryGroups($carrier);
        $this->command->info("✓ Created " . count($categoryGroups) . " category groups");

        // 3. Create acceptance rules
        $this->createAcceptanceRules($carrier, $westAfricaPorts, $categoryGroups);
        $this->command->info("✓ Created acceptance rules");

        // 4. Create transform rules (overwidth)
        $this->createTransformRules($carrier, $westAfricaPorts, $categoryGroups);
        $this->command->info("✓ Created transform rules");

        // 5. Create surcharge rules
        $this->createSurchargeRules($carrier, $westAfricaPorts, $categoryGroups);
        $this->command->info("✓ Created surcharge rules");

        // 6. Create surcharge article mappings (will create placeholders if articles don't exist)
        $this->createArticleMappings($carrier, $westAfricaPorts, $categoryGroups);
        $this->command->info("✓ Created surcharge article mappings");

        // 7. Create freight mappings (ALLOWLIST)
        $this->createFreightMappings($carrier, $westAfricaPorts, $categoryGroups);
        $this->command->info("✓ Created freight mappings");

        // 8. Create clauses
        $this->createClauses($carrier, $westAfricaPorts);
        $this->command->info("✓ Created clauses");

        $this->command->newLine();
        $this->command->info('✅ Grimaldi West Africa rules seeded successfully!');
    }

    /**
     * Create port groups
     */
    private function createPortGroups(ShippingCarrier $carrier): array
    {
        $groups = [
            [
                'code' => 'Grimaldi_WAF',
                'display_name' => 'Grimaldi WAF',
                'aliases' => [],
                'priority' => 0,
                'sort_order' => 1,
                'port_codes' => ['CAS', 'CKY', 'COO', 'DKR', 'DLA', 'FNA', 'LOS', 'LBV', 'LFW', 'PNR', 'NKC', 'ABJ'],
            ],
            [
                'code' => 'Grimaldi_MED',
                'display_name' => 'Grimaldi MED',
                'aliases' => [],
                'priority' => 0,
                'sort_order' => 2,
                'port_codes' => ['ALG', 'MRS', 'GOA'],
            ],
        ];

        $createdGroups = [];
        foreach ($groups as $groupData) {
            $portCodes = $groupData['port_codes'];
            unset($groupData['port_codes']);

            $group = CarrierPortGroup::updateOrCreate(
                [
                    'carrier_id' => $carrier->id,
                    'code' => $groupData['code'],
                ],
                array_merge($groupData, [
                    'effective_from' => now()->subYear(),
                    'is_active' => true,
                ])
            );

            // Create members
            foreach ($portCodes as $portCode) {
                $port = Port::where('code', $portCode)->first();
                if ($port) {
                    CarrierPortGroupMember::updateOrCreate(
                        [
                            'carrier_port_group_id' => $group->id,
                            'port_id' => $port->id,
                        ],
                        ['is_active' => true]
                    );
                } else {
                    $this->command->warn("  ⚠ Port '{$portCode}' not found. Skipping port group member.");
                }
            }

            $createdGroups[$groupData['code']] = $group;
        }

        return $createdGroups;
    }

    /**
     * Get West Africa ports
     */
    private function getWestAfricaPorts(): array
    {
        $portCodes = ['ABJ', 'LOS', 'DKR', 'CKY', 'LFW', 'COO', 'DLA', 'PNR'];
        $ports = [];
        
        foreach ($portCodes as $code) {
            $port = Port::where('code', $code)->first();
            if ($port) {
                $ports[$code] = $port;
            }
        }
        
        return $ports;
    }

    /**
     * Create category groups
     */
    private function createCategoryGroups(ShippingCarrier $carrier): array
    {
        $groups = [
            [
                'code' => 'CARS',
                'display_name' => 'Cars',
                'aliases' => ['Car', 'Passenger Car', 'Automobile'],
                'priority' => 10,
                'members' => ['car', 'suv'],
            ],
            [
                'code' => 'SMALL_VANS',
                'display_name' => 'Small Vans',
                'aliases' => ['Small Van', 'Van'],
                'priority' => 9,
                'members' => ['small_van'],
            ],
            [
                'code' => 'BIG_VANS',
                'display_name' => 'Big Vans',
                'aliases' => ['Big Van', 'Large Van'],
                'priority' => 8,
                'members' => ['big_van'],
            ],
            [
                'code' => 'LM_CARGO',
                'display_name' => 'LM Cargo',
                'aliases' => ['LM', 'LM Cargo', 'Linear Meter Cargo'],
                'priority' => 7,
                'members' => ['truck', 'truckhead', 'truck_chassis', 'tipper_truck', 'platform_truck', 'box_truck', 'bus'],
            ],
            [
                'code' => 'HH',
                'display_name' => 'High & Heavy',
                'aliases' => ['High and Heavy', 'HH', 'Heavy Machinery'],
                'priority' => 6,
                'members' => ['high_and_heavy', 'concrete_mixer', 'tank_truck', 'vacuum_truck', 'refuse_truck'],
            ],
        ];

        $createdGroups = [];
        foreach ($groups as $groupData) {
            $members = $groupData['members'];
            unset($groupData['members']);

            $group = CarrierCategoryGroup::updateOrCreate(
                [
                    'carrier_id' => $carrier->id,
                    'code' => $groupData['code'],
                ],
                array_merge($groupData, [
                    'effective_from' => now()->subYear(), // Active for 1 year
                    'is_active' => true,
                ])
            );

            // Create members
            foreach ($members as $category) {
                CarrierCategoryGroupMember::updateOrCreate(
                    [
                        'carrier_category_group_id' => $group->id,
                        'vehicle_category' => $category,
                    ],
                    ['is_active' => true]
                );
            }

            $createdGroups[$groupData['code']] = $group;
        }

        return $createdGroups;
    }

    /**
     * Create acceptance rules
     */
    private function createAcceptanceRules(ShippingCarrier $carrier, array $ports, array $categoryGroups): void
    {
        // Global acceptance rules
        $globalRules = [
            // Cars: up to 13 cbm and/or max. height 1.70m
            [
                'vehicle_category' => 'car',
                'max_length_cm' => 600,
                'max_width_cm' => 250,
                'max_height_cm' => 170, // Document: 1.70m
                'max_cbm' => 13,
                'max_weight_kg' => 3500,
                'must_be_empty' => true, // Document: "All Cars, Small vans and Big vans must be delivered empty"
                'must_be_self_propelled' => true, // Document: "Units have to be self-propelled"
                'allow_accessories' => 'UNRESTRICTED',
                'priority' => 10,
            ],
            // Small Vans: max. height 2.10m, max CBM 18
            [
                'vehicle_category' => 'small_van',
                'max_length_cm' => 600,
                'max_width_cm' => 250,
                'max_height_cm' => 210, // Document: 2.10m
                'max_cbm' => 18,
                'max_weight_kg' => 4500,
                'must_be_empty' => true, // Document: "All Cars, Small vans and Big vans must be delivered empty"
                'must_be_self_propelled' => true,
                'priority' => 9,
            ],
            // Big Vans: max. height 2.60m, max CBM 28
            [
                'vehicle_category' => 'big_van',
                'max_length_cm' => 700,
                'max_width_cm' => 260,
                'max_height_cm' => 260, // Document: 2.60m
                'max_cbm' => 28,
                'max_weight_kg' => 7500,
                'must_be_empty' => true, // Document: "All Cars, Small vans and Big vans must be delivered empty"
                'must_be_self_propelled' => true,
                'priority' => 8,
            ],
            // LM Roro: max. width 2.60m, max. height 4.40m, max. Weight 65mt
            [
                'category_group_id' => $categoryGroups['LM_CARGO']->id,
                'max_length_cm' => 1600,
                'max_width_cm' => 260, // Document: 2.60m
                'max_height_cm' => 440, // Document: 4.40m (hard limit)
                'max_weight_kg' => 65000, // Document: 65mt
                'must_be_self_propelled' => true,
                'soft_max_height_cm' => 440, // Document: "Max height 4.40m: without surcharge - upon request"
                'soft_height_requires_approval' => true,
                'soft_max_weight_kg' => 65000, // Document: "Max weight 65tons: without surcharge - upon request"
                'soft_weight_requires_approval' => true,
                'notes' => 'Trucks can be loaded with fully assembled, and empty, vehicles',
                'priority' => 7,
            ],
        ];

        foreach ($globalRules as $ruleData) {
            CarrierAcceptanceRule::updateOrCreate(
                [
                    'carrier_id' => $carrier->id,
                    'port_id' => null,
                    'vehicle_category' => $ruleData['vehicle_category'] ?? null,
                    'category_group_id' => $ruleData['category_group_id'] ?? null,
                ],
                array_merge($ruleData, [
                    'effective_from' => now()->subYear(),
                    'is_active' => true,
                ])
            );
        }

        // Conakry-specific rules (weight tier surcharge applies, but no stricter acceptance limits in document)
        // Note: Conakry weight surcharge is handled via surcharge rules, not acceptance limits
    }

    /**
     * Create transform rules (overwidth)
     */
    private function createTransformRules(ShippingCarrier $carrier, array $ports, array $categoryGroups): void
    {
        // Global overwidth transform: trigger at 260cm, recalculate LM = (L×W)/2.5
        CarrierTransformRule::updateOrCreate(
            [
                'carrier_id' => $carrier->id,
                'port_id' => null,
                'transform_code' => 'OVERWIDTH_LM_RECALC',
            ],
            [
                'params' => [
                    'trigger_width_gt_cm' => 260,
                    'divisor_cm' => 250, // Standard 2.5m divisor
                ],
                'priority' => 10,
                'effective_from' => now()->subYear(),
                'is_active' => true,
            ]
        );

        // Some destinations may have different triggers (example: Abidjan at 255cm)
        if (isset($ports['ABJ'])) {
            CarrierTransformRule::updateOrCreate(
                [
                    'carrier_id' => $carrier->id,
                    'port_id' => $ports['ABJ']->id,
                    'transform_code' => 'OVERWIDTH_LM_RECALC',
                ],
                [
                    'params' => [
                        'trigger_width_gt_cm' => 255,
                        'divisor_cm' => 250,
                    ],
                    'priority' => 15, // Higher priority for specific port
                    'effective_from' => now()->subYear(),
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * Create surcharge rules
     */
    private function createSurchargeRules(ShippingCarrier $carrier, array $ports, array $categoryGroups): void
    {
        // 1. Tracking surcharge: 10% of basic freight
        CarrierSurchargeRule::updateOrCreate(
            [
                'carrier_id' => $carrier->id,
                'port_id' => null,
                'event_code' => 'TRACKING_PERCENT',
            ],
            [
                'name' => 'Tracking Surcharge',
                'calc_mode' => 'PERCENT_OF_BASIC_FREIGHT',
                'params' => ['percentage' => 10],
                'priority' => 10,
                'effective_from' => now()->subYear(),
                'is_active' => true,
            ]
        );

        // 2. Conakry weight tier surcharge
        // Document: <10t = €120, <20t = €155, +20t = €155 + €11/t above 20t
        if (isset($ports['CKY'])) {
            CarrierSurchargeRule::updateOrCreate(
                [
                    'carrier_id' => $carrier->id,
                    'port_id' => $ports['CKY']->id,
                    'event_code' => 'CONAKRY_WEIGHT_TIER',
                ],
                [
                    'name' => 'Conakry Weight Tier Surcharge',
                    'calc_mode' => 'WEIGHT_TIER',
                    'params' => [
                        'tiers' => [
                            ['max_kg' => 10000, 'amount' => 120], // <10t = €120
                            ['max_kg' => 20000, 'amount' => 155], // <20t = €155
                            // For >20t: base €155 + €11/t above 20t
                            // This is handled by using min_kg with per_ton_over
                            ['min_kg' => 20000, 'amount' => 155, 'per_ton_over' => 11],
                        ],
                    ],
                    'priority' => 15,
                    'effective_from' => now()->subYear(),
                    'is_active' => true,
                ]
            );
        }

        // 3. Towing surcharge (per unit)
        CarrierSurchargeRule::updateOrCreate(
            [
                'carrier_id' => $carrier->id,
                'port_id' => null,
                'event_code' => 'TOWING',
            ],
            [
                'name' => 'Towing Surcharge',
                'calc_mode' => 'PER_UNIT',
                'params' => ['amount' => 150],
                'priority' => 10,
                'effective_from' => now()->subYear(),
                'is_active' => true,
            ]
        );

        // 4. Tank inspection surcharge (per tank)
        // Document: Tank trucks/trailers: mandatory inspection on terminal - Eur 220/tank
        CarrierSurchargeRule::updateOrCreate(
            [
                'carrier_id' => $carrier->id,
                'port_id' => null,
                'event_code' => 'TANK_INSPECTION',
                'vehicle_category' => 'tank_truck',
            ],
            [
                'name' => 'Tank Inspection Surcharge',
                'calc_mode' => 'PER_TANK',
                'params' => ['amount' => 220], // Document: Eur 220/tank
                'priority' => 10,
                'effective_from' => now()->subYear(),
                'is_active' => true,
            ]
        );

        // Note: Overwidth is handled via transform rule (LM recalculation), not surcharge rules
        // Document: "Overwidth: as from 2.60m pro rata line meter (L X W / 2.5 m) this is the new length to calculate all charges"
    }

    /**
     * Create article mappings
     */
    private function createArticleMappings(ShippingCarrier $carrier, array $ports, array $categoryGroups): void
    {
        // Try to find existing articles by name pattern, or create placeholders
        $articleMappings = [
            'TRACKING_PERCENT' => 'Tracking Surcharge',
            'CONAKRY_WEIGHT_TIER' => 'Conakry Weight Surcharge',
            'TOWING' => 'Towing Surcharge',
            'TANK_INSPECTION' => 'Tank Inspection',
        ];

        foreach ($articleMappings as $eventCode => $articleName) {
            // Try to find article by name (case-insensitive, partial match)
            $article = RobawsArticleCache::whereRaw('LOWER(article_name) LIKE ?', ['%' . strtolower($articleName) . '%'])
                ->whereRaw('LOWER(shipping_line) LIKE ?', ['%grimaldi%'])
                ->first();

            if (!$article) {
                // Create placeholder article (will need to be replaced with real article later)
                $this->command->warn("  ⚠ Article not found for '{$articleName}'. Skipping mapping.");
                continue;
            }

            CarrierSurchargeArticleMap::updateOrCreate(
                [
                    'carrier_id' => $carrier->id,
                    'port_id' => null,
                    'event_code' => $eventCode,
                    'article_id' => $article->id,
                ],
                [
                    'qty_mode' => $this->getQtyModeForEvent($eventCode),
                    'effective_from' => now()->subYear(),
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * Get quantity mode for event code
     */
    private function getQtyModeForEvent(string $eventCode): string
    {
        return match ($eventCode) {
            'TRACKING_PERCENT' => 'PERCENT_OF_BASIC_FREIGHT',
            'CONAKRY_WEIGHT_TIER' => 'WEIGHT_TIER',
            'TOWING' => 'PER_UNIT',
            'TANK_INSPECTION' => 'PER_TANK',
            default => 'FLAT',
        };
    }

    /**
     * Create freight mappings (ALLOWLIST strategy)
     * Maps articles to vehicle categories/category groups for article selection
     */
    private function createFreightMappings(ShippingCarrier $carrier, array $ports, array $categoryGroups): void
    {
        // Get WAF port group if it exists
        $wafPortGroup = \App\Models\CarrierPortGroup::where('carrier_id', $carrier->id)
            ->where('code', 'Grimaldi_WAF')
            ->first();
        
        $wafPortGroupIds = $wafPortGroup ? [$wafPortGroup->id] : null;

        // Example: Map Big Van, Car, Small Van articles for Dakar to truckhead/truck/trailer
        // This allows truckhead to find these articles when no LM Cargo articles exist for Dakar
        if (isset($ports['DKR'])) {
            $dakarPortId = $ports['DKR']->id;
            
            // Find articles for Dakar
            $dakarArticles = RobawsArticleCache::where('is_parent_article', true)
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->whereRaw('LOWER(shipping_line) LIKE ?', ['%grimaldi%'])
                      ->orWhereNull('shipping_line');
                })
                ->where(function ($q) use ($dakarPortId) {
                    $q->whereRaw('LOWER(pod) LIKE ?', ['%dakar%'])
                      ->orWhereRaw('LOWER(pod) LIKE ?', ['%dkr%']);
                })
                ->get();

            foreach ($dakarArticles as $article) {
                $commodityType = strtoupper(trim($article->commodity_type ?? ''));
                
                // Map Big Van, Car, Small Van articles to LM cargo categories (truckhead, truck, trailer)
                if (in_array($commodityType, ['BIG VAN', 'CAR', 'SMALL VAN'])) {
                    CarrierArticleMapping::updateOrCreate(
                        [
                            'carrier_id' => $carrier->id,
                            'article_id' => $article->id,
                        ],
                        [
                            'port_ids' => [$dakarPortId],
                            'port_group_ids' => $wafPortGroupIds,
                            'vehicle_categories' => ['truckhead', 'truck', 'trailer'],
                            'priority' => 10,
                            'effective_from' => now()->subYear(),
                            'is_active' => true,
                        ]
                    );
                }
            }
        }

        // Example: Map LM Cargo articles for Abidjan to truckhead/truck/trailer
        if (isset($ports['ABJ'])) {
            $abidjanPortId = $ports['ABJ']->id;
            
            $abidjanLmArticles = RobawsArticleCache::where('is_parent_article', true)
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->whereRaw('LOWER(shipping_line) LIKE ?', ['%grimaldi%'])
                      ->orWhereNull('shipping_line');
                })
                ->where(function ($q) use ($abidjanPortId) {
                    $q->whereRaw('LOWER(pod) LIKE ?', ['%abidjan%'])
                      ->orWhereRaw('LOWER(pod) LIKE ?', ['%abj%']);
                })
                ->whereRaw("UPPER(TRIM(commodity_type)) = ?", ['LM CARGO'])
                ->get();

            foreach ($abidjanLmArticles as $article) {
                CarrierArticleMapping::updateOrCreate(
                    [
                        'carrier_id' => $carrier->id,
                        'article_id' => $article->id,
                    ],
                    [
                        'port_ids' => [$abidjanPortId],
                        'port_group_ids' => $wafPortGroupIds,
                        'vehicle_categories' => ['truckhead', 'truck', 'trailer'],
                        'priority' => 10,
                        'effective_from' => now()->subYear(),
                        'is_active' => true,
                    ]
                );
            }
        }
    }

    /**
     * Create clauses
     * Based on official Grimaldi rate sheet document
     */
    private function createClauses(ShippingCarrier $carrier, array $ports): void
    {
        $clauses = [
            // Operational Clauses
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Subject to final operational acceptance.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Subject to vessel and space availability and schedules.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Units 30 days free on terminal for export, after parking costs will occur.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Rolling units will be loaded as per FIFO procedure.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Upon shipment Grimaldi\'s bill of lading conditions/terms shall apply.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Units have to be self-propelled and comply with the cargo modalities of the Port of Antwerp.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Tariff only valid for used cargo (brand new - always upon request).',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Conditions subject to acceptance by carrier and official authorities (arms/ammunition regulation).',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'For unpacked, second-hand units, Carrier is not responsible for dents, bends, scratches, etc.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Warning: Personal effects in vehicles are not covered by carrier\'s insurance. Shipper/consignee responsible for any loss or damage.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'All Cars, Small vans and Big vans must be delivered empty.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Trucks can be loaded with fully assembled, and empty, vehicles.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Merchants responsible for missing/wrong/incorrect information regarding measures.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'For complicated shipments, transport manual or method statement should be provided.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Rolling units on wooden wheels subject to approval.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Towable units must be equipped with suitable towing hook, ring or bracket.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Shipper responsible to supply Operating Instructions.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Carrier not responsible to load non-starting drivable units.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'All roro to be fully operational and in good working order.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'If cargo breaks down on route, shipper/consignee responsible to have unit fixed.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'All rolling units must have adequate, identifiable lashing points.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Piggy-back (nested) rolling units must be presented properly secured.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Rolling units with ground clearance < 30cm require details and drawings in advance.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Cargo to comply with all regulations at port of loading and discharge.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'If cargo differs or does not comply, shipper/consignee remain liable for additional costs.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Overwidth: as from 2.60m pro rata line meter (L X W / 2.5 m) this is the new length to calculate all charges.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Max height 4.40m: without surcharge - upon request.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Max weight 65tons: without surcharge - upon request.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Tank trucks/trailers: mandatory inspection on terminal - Eur 220/tank.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'port_id' => $ports['CKY']->id ?? null,
                'text' => 'Conakry: Weight tier surcharge applies. <10t = €120, <20t = €155, +20t = €155 + €11/t above 20t.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'port_id' => $ports['CKY']->id ?? null,
                'text' => 'Conakry: All waivers are for shippers account except Conakry, this will be provided by Grimaldi.',
            ],

            // Legal Clauses
            [
                'clause_type' => 'LEGAL',
                'text' => 'Subject to all surcharges (also BAF) valid at time of shipment (VATOS).',
            ],
            [
                'clause_type' => 'LEGAL',
                'text' => 'All destinations under liner terms with exception of Casablanca and Douala which are Free Out.',
            ],
            [
                'clause_type' => 'LEGAL',
                'text' => 'No fac our rates are net rates.',
            ],
            [
                'clause_type' => 'LEGAL',
                'text' => 'All offers subject to revision until engagement.',
            ],
            [
                'clause_type' => 'LEGAL',
                'text' => 'Rates and Conditions based on dimensions and weights supplied by shippers.',
            ],
            [
                'clause_type' => 'LEGAL',
                'text' => 'Carrier reserves right to revise quotation if dimensions/weights change.',
            ],
            [
                'clause_type' => 'LEGAL',
                'text' => 'Exclusive customs formalities.',
            ],
            [
                'clause_type' => 'LIABILITY',
                'text' => 'Carrier\'s liability is limited in accordance with the applicable international conventions and national laws.',
            ],
        ];

        foreach ($clauses as $clauseData) {
            $portId = $clauseData['port_id'] ?? null;
            unset($clauseData['port_id']);

            // Use text as unique identifier along with carrier, port, and type
            CarrierClause::updateOrCreate(
                [
                    'carrier_id' => $carrier->id,
                    'port_id' => $portId,
                    'clause_type' => $clauseData['clause_type'],
                    'text' => $clauseData['text'], // Use text as part of unique key
                ],
                array_merge($clauseData, [
                    'effective_from' => now()->subYear(),
                    'is_active' => true,
                ])
            );
        }
    }
}

