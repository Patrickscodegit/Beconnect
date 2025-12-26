<?php

namespace Database\Seeders;

use App\Models\CarrierAcceptanceRule;
use App\Models\CarrierCategoryGroup;
use App\Models\CarrierCategoryGroupMember;
use App\Models\CarrierClassificationBand;
use App\Models\CarrierClause;
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
     * - Classification bands (CBM/height-based)
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

        // 1. Create category groups
        $categoryGroups = $this->createCategoryGroups($carrier);
        $this->command->info("✓ Created " . count($categoryGroups) . " category groups");

        // 2. Create classification bands
        $this->createClassificationBands($carrier, $westAfricaPorts);
        $this->command->info("✓ Created classification bands");

        // 3. Create acceptance rules
        $this->createAcceptanceRules($carrier, $westAfricaPorts, $categoryGroups);
        $this->command->info("✓ Created acceptance rules");

        // 4. Create transform rules (overwidth)
        $this->createTransformRules($carrier, $westAfricaPorts, $categoryGroups);
        $this->command->info("✓ Created transform rules");

        // 5. Create surcharge rules
        $this->createSurchargeRules($carrier, $westAfricaPorts, $categoryGroups);
        $this->command->info("✓ Created surcharge rules");

        // 6. Create article mappings (will create placeholders if articles don't exist)
        $this->createArticleMappings($carrier, $westAfricaPorts, $categoryGroups);
        $this->command->info("✓ Created article mappings");

        // 7. Create clauses
        $this->createClauses($carrier, $westAfricaPorts);
        $this->command->info("✓ Created clauses");

        $this->command->newLine();
        $this->command->info('✅ Grimaldi West Africa rules seeded successfully!');
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
     * Create classification bands
     */
    private function createClassificationBands(ShippingCarrier $carrier, array $ports): void
    {
        $bands = [
            // Cars: CBM < 15, height < 200cm
            [
                'outcome_vehicle_category' => 'car',
                'max_cbm' => 15,
                'max_height_cm' => 200,
                'rule_logic' => 'AND',
                'priority' => 10,
            ],
            // Small Vans: CBM 15-25, height 200-250cm
            [
                'outcome_vehicle_category' => 'small_van',
                'min_cbm' => 15,
                'max_cbm' => 25,
                'max_height_cm' => 250,
                'rule_logic' => 'AND',
                'priority' => 9,
            ],
            // Big Vans: CBM 25-40, height 250-300cm
            [
                'outcome_vehicle_category' => 'big_van',
                'min_cbm' => 25,
                'max_cbm' => 40,
                'max_height_cm' => 300,
                'rule_logic' => 'AND',
                'priority' => 8,
            ],
            // LM Cargo: CBM > 40, height > 300cm
            [
                'outcome_vehicle_category' => 'truck',
                'min_cbm' => 40,
                'rule_logic' => 'OR',
                'priority' => 7,
            ],
            // High & Heavy: CBM > 60 OR height > 400cm
            [
                'outcome_vehicle_category' => 'high_and_heavy',
                'min_cbm' => 60,
                'rule_logic' => 'OR',
                'priority' => 6,
            ],
        ];

        foreach ($bands as $bandData) {
            CarrierClassificationBand::updateOrCreate(
                [
                    'carrier_id' => $carrier->id,
                    'port_id' => null, // Global rules
                    'outcome_vehicle_category' => $bandData['outcome_vehicle_category'],
                ],
                array_merge($bandData, [
                    'effective_from' => now()->subYear(),
                    'is_active' => true,
                ])
            );
        }
    }

    /**
     * Create acceptance rules
     */
    private function createAcceptanceRules(ShippingCarrier $carrier, array $ports, array $categoryGroups): void
    {
        // Global acceptance rules
        $globalRules = [
            // Cars
            [
                'vehicle_category' => 'car',
                'max_length_cm' => 600,
                'max_width_cm' => 250,
                'max_height_cm' => 200,
                'max_weight_kg' => 3500,
                'must_be_empty' => false,
                'must_be_self_propelled' => true,
                'allow_accessories' => 'UNRESTRICTED',
                'priority' => 10,
            ],
            // Small Vans
            [
                'vehicle_category' => 'small_van',
                'max_length_cm' => 600,
                'max_width_cm' => 250,
                'max_height_cm' => 250,
                'max_weight_kg' => 4500,
                'must_be_self_propelled' => true,
                'priority' => 9,
            ],
            // Big Vans
            [
                'vehicle_category' => 'big_van',
                'max_length_cm' => 700,
                'max_width_cm' => 260,
                'max_height_cm' => 300,
                'max_weight_kg' => 7500,
                'must_be_self_propelled' => true,
                'priority' => 8,
            ],
            // LM Cargo (via group)
            [
                'category_group_id' => $categoryGroups['LM_CARGO']->id,
                'max_length_cm' => 1600,
                'max_width_cm' => 300,
                'max_height_cm' => 450,
                'max_weight_kg' => 35000,
                'must_be_self_propelled' => true,
                'soft_max_height_cm' => 500,
                'soft_height_requires_approval' => true,
                'priority' => 7,
            ],
            // High & Heavy
            [
                'category_group_id' => $categoryGroups['HH']->id,
                'max_length_cm' => 2000,
                'max_width_cm' => 350,
                'max_height_cm' => 500,
                'max_weight_kg' => 50000,
                'must_be_self_propelled' => true,
                'soft_max_height_cm' => 600,
                'soft_height_requires_approval' => true,
                'soft_max_weight_kg' => 60000,
                'soft_weight_requires_approval' => true,
                'priority' => 6,
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

        // Conakry-specific rules (stricter weight limits)
        if (isset($ports['CKY'])) {
            CarrierAcceptanceRule::updateOrCreate(
                [
                    'carrier_id' => $carrier->id,
                    'port_id' => $ports['CKY']->id,
                    'category_group_id' => $categoryGroups['LM_CARGO']->id,
                ],
                [
                    'max_weight_kg' => 25000, // Lower limit for Conakry
                    'soft_max_weight_kg' => 30000,
                    'soft_weight_requires_approval' => true,
                    'notes' => 'Conakry has stricter weight limits due to port infrastructure',
                    'priority' => 15, // Higher priority than global
                    'effective_from' => now()->subYear(),
                    'is_active' => true,
                ]
            );
        }
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
                            ['max_kg' => 10000, 'amount' => 120],
                            ['max_kg' => 15000, 'amount' => 180],
                            ['max_kg' => 20000, 'amount' => 250],
                            ['max_kg' => 25000, 'amount' => 350],
                            ['max_kg' => null, 'amount' => 500], // Above 25t
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
                'params' => ['amount' => 200],
                'priority' => 10,
                'effective_from' => now()->subYear(),
                'is_active' => true,
            ]
        );

        // 5. Overwidth step blocks surcharge (alternative to LM recalculation)
        // Example: per 25cm block over 250cm, applied per LM
        CarrierSurchargeRule::updateOrCreate(
            [
                'carrier_id' => $carrier->id,
                'port_id' => null,
                'event_code' => 'OVERWIDTH_STEP_BLOCKS',
            ],
            [
                'name' => 'Overwidth Step Blocks Surcharge',
                'calc_mode' => 'WIDTH_STEP_BLOCKS',
                'params' => [
                    'trigger_width_gt_cm' => 260,
                    'threshold_cm' => 250,
                    'block_cm' => 25,
                    'rounding' => 'CEIL',
                    'qty_basis' => 'LM',
                    'amount_per_block' => 50, // €50 per block per LM
                    'exclusive_group' => 'OVERWIDTH', // Don't apply if OVERWIDTH_LM_RECALC is used
                ],
                'priority' => 5, // Lower priority than transform rule
                'effective_from' => now()->subYear(),
                'is_active' => true,
            ]
        );

        // 6. Overwidth LM basis surcharge (alternative: surcharge per LM when overwidth)
        CarrierSurchargeRule::updateOrCreate(
            [
                'carrier_id' => $carrier->id,
                'port_id' => null,
                'event_code' => 'OVERWIDTH_LM_BASIS',
            ],
            [
                'name' => 'Overwidth LM Basis Surcharge',
                'calc_mode' => 'WIDTH_LM_BASIS',
                'params' => [
                    'trigger_width_gt_cm' => 260,
                    'amount_per_lm' => 25, // €25 per LM when overwidth
                    'exclusive_group' => 'OVERWIDTH',
                ],
                'priority' => 4,
                'effective_from' => now()->subYear(),
                'is_active' => true,
            ]
        );
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
            'OVERWIDTH_STEP_BLOCKS' => 'Overwidth Surcharge',
            'OVERWIDTH_LM_BASIS' => 'Overwidth LM Surcharge',
        ];

        foreach ($articleMappings as $eventCode => $articleName) {
            // Try to find article by name (case-insensitive, partial match)
            $article = RobawsArticleCache::whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($articleName) . '%'])
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
            'OVERWIDTH_STEP_BLOCKS' => 'WIDTH_STEP_BLOCKS',
            'OVERWIDTH_LM_BASIS' => 'WIDTH_LM_BASIS',
            default => 'FLAT',
        };
    }

    /**
     * Create clauses
     */
    private function createClauses(ShippingCarrier $carrier, array $ports): void
    {
        $clauses = [
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'All vehicles must be empty of personal belongings and cargo unless otherwise agreed in writing.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Non-self-propelled vehicles require prior approval and may be subject to additional handling charges.',
            ],
            [
                'clause_type' => 'LEGAL',
                'text' => 'Carrier\'s liability is limited in accordance with the applicable international conventions and national laws.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'text' => 'Overwidth cargo (width > 260cm) will be charged based on recalculated LM = (Length × Width) / 2.5m.',
            ],
            [
                'clause_type' => 'OPERATIONAL',
                'port_id' => $ports['CKY']->id ?? null,
                'text' => 'Conakry: Weight restrictions apply. Cargo exceeding 25,000kg requires prior approval and may incur additional charges.',
            ],
        ];

        foreach ($clauses as $clauseData) {
            $portId = $clauseData['port_id'] ?? null;
            unset($clauseData['port_id']);

            CarrierClause::updateOrCreate(
                [
                    'carrier_id' => $carrier->id,
                    'port_id' => $portId,
                    'clause_type' => $clauseData['clause_type'],
                ],
                array_merge($clauseData, [
                    'effective_from' => now()->subYear(),
                    'is_active' => true,
                ])
            );
        }
    }
}

