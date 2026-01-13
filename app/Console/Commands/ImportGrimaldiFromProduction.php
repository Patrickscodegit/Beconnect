<?php

namespace App\Console\Commands;

use App\Models\CarrierArticleMapping;
use App\Models\CarrierPurchaseTariff;
use App\Models\Port;
use App\Models\CarrierCategoryGroup;
use App\Models\CarrierPortGroup;
use App\Models\CarrierAcceptanceRule;
use App\Models\CarrierTransformRule;
use App\Models\CarrierSurchargeRule;
use App\Models\CarrierClause;
use App\Models\RobawsArticleCache;
use App\Models\ShippingCarrier;
use App\Services\Carrier\CarrierLookupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportGrimaldiFromProduction extends Command
{
    protected $signature = 'grimaldi:import-from-production
                          {--input=storage/exports : Input directory containing export files}
                          {--dry-run : Show what would be imported without making changes}';

    protected $description = 'Import Grimaldi mappings and tariffs from production export files';

    protected CarrierLookupService $carrierLookup;

    public function __construct(CarrierLookupService $carrierLookup)
    {
        parent::__construct();
        $this->carrierLookup = $carrierLookup;
    }

    public function handle(): int
    {
        $inputDir = $this->option('input');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Load export files
        $mappingsFile = $inputDir . '/grimaldi_mappings.json';
        $tariffsFile = $inputDir . '/grimaldi_tariffs.json';
        $portsFile = $inputDir . '/grimaldi_ports_reference.json';
        $categoryGroupsFile = $inputDir . '/grimaldi_category_groups.json';
        $portGroupsFile = $inputDir . '/grimaldi_port_groups.json';
        $portGroupsRefFile = $inputDir . '/grimaldi_port_groups_reference.json';
        $acceptanceRulesFile = $inputDir . '/grimaldi_acceptance_rules.json';
        $transformRulesFile = $inputDir . '/grimaldi_transform_rules.json';
        $surchargeRulesFile = $inputDir . '/grimaldi_surcharge_rules.json';
        $clausesFile = $inputDir . '/grimaldi_clauses.json';

        if (!file_exists($mappingsFile)) {
            $this->error("❌ Mappings file not found: {$mappingsFile}");
            $this->info("💡 Run on production: php export_grimaldi_production.php");
            return Command::FAILURE;
        }

        $this->info('📂 Loading export files...');
        $mappingsData = json_decode(file_get_contents($mappingsFile), true);
        $tariffsData = file_exists($tariffsFile) ? json_decode(file_get_contents($tariffsFile), true) : [];
        $portsRef = file_exists($portsFile) ? json_decode(file_get_contents($portsFile), true) : [];
        $categoryGroupsRef = file_exists($categoryGroupsFile) ? json_decode(file_get_contents($categoryGroupsFile), true) : [];
        $portGroupsRef = file_exists($portGroupsFile) ? json_decode(file_get_contents($portGroupsFile), true) : [];
        $portGroupsRefData = file_exists($portGroupsRefFile) ? json_decode(file_get_contents($portGroupsRefFile), true) : [];
        $acceptanceRulesData = file_exists($acceptanceRulesFile) ? json_decode(file_get_contents($acceptanceRulesFile), true) : [];
        $transformRulesData = file_exists($transformRulesFile) ? json_decode(file_get_contents($transformRulesFile), true) : [];
        $surchargeRulesData = file_exists($surchargeRulesFile) ? json_decode(file_get_contents($surchargeRulesFile), true) : [];
        $clausesData = file_exists($clausesFile) ? json_decode(file_get_contents($clausesFile), true) : [];

        $this->info("✅ Loaded " . count($mappingsData) . " mappings");
        $this->info("✅ Loaded " . count($tariffsData) . " tariffs");
        $this->info("✅ Loaded " . count($portGroupsRef) . " port groups");
        $this->info("✅ Loaded " . count($portGroupsRefData) . " port group references");
        $this->info("✅ Loaded " . count($acceptanceRulesData) . " acceptance rules");
        $this->info("✅ Loaded " . count($transformRulesData) . " transform rules");
        $this->info("✅ Loaded " . count($surchargeRulesData) . " surcharge rules");
        $this->info("✅ Loaded " . count($clausesData) . " clauses");
        $this->newLine();

        // Find local Grimaldi carrier
        $carrier = $this->carrierLookup->findGrimaldi();
        if (!$carrier) {
            $this->error('❌ Grimaldi carrier not found locally!');
            $this->info('💡 Run: php artisan carriers:link-to-suppliers');
            return Command::FAILURE;
        }

        $this->info("✅ Using local carrier: {$carrier->name} (ID: {$carrier->id})");
        $this->newLine();

        // Import category groups first
        $this->info('📋 Importing category groups...');
        $categoryGroupsCreated = 0;
        $categoryGroupsUpdated = 0;
        $categoryGroupCodeToLocalId = [];

        foreach ($categoryGroupsRef as $cgData) {
            $localCg = CarrierCategoryGroup::updateOrCreate(
                [
                    'carrier_id' => $carrier->id,
                    'code' => $cgData['code'],
                ],
                [
                    'display_name' => $cgData['display_name'],
                    'aliases' => $cgData['aliases'] ?? null,
                    'priority' => $cgData['priority'] ?? 0,
                    'sort_order' => $cgData['sort_order'] ?? 0,
                    'effective_from' => $cgData['effective_from'] ? \Carbon\Carbon::parse($cgData['effective_from']) : null,
                    'effective_to' => $cgData['effective_to'] ? \Carbon\Carbon::parse($cgData['effective_to']) : null,
                    'is_active' => $cgData['is_active'] ?? true,
                ]
            );

            if ($localCg->wasRecentlyCreated) {
                $categoryGroupsCreated++;
            } else {
                $categoryGroupsUpdated++;
            }

            $categoryGroupCodeToLocalId[$cgData['code']] = $localCg->id;

            // Import category group members
            if (!empty($cgData['members']) && is_array($cgData['members'])) {
                foreach ($cgData['members'] as $memberData) {
                    \App\Models\CarrierCategoryGroupMember::updateOrCreate(
                        [
                            'carrier_category_group_id' => $localCg->id,
                            'vehicle_category' => $memberData['vehicle_category'],
                        ],
                        [
                            'is_active' => $memberData['is_active'] ?? true,
                        ]
                    );
                }
            }
        }

        $this->info("✅ Category groups: {$categoryGroupsCreated} created, {$categoryGroupsUpdated} updated");
        $this->newLine();

        // Build port ID mapping (production port code -> local port ID)
        $portCodeToLocalId = [];
        foreach ($portsRef as $portRef) {
            $localPort = Port::where('code', $portRef['code'])->first();
            if ($localPort) {
                $portCodeToLocalId[$portRef['code']] = $localPort->id;
            }
        }

        // Import port groups
        $this->info('🌐 Importing port groups...');
        $portGroupsCreated = 0;
        $portGroupsUpdated = 0;
        $portGroupCodeToLocalId = [];

        foreach ($portGroupsRef as $pgData) {
            $localPg = CarrierPortGroup::updateOrCreate(
                [
                    'carrier_id' => $carrier->id,
                    'code' => $pgData['code'],
                ],
                [
                    'display_name' => $pgData['display_name'],
                    'aliases' => $pgData['aliases'] ?? null,
                    'priority' => $pgData['priority'] ?? 0,
                    'sort_order' => $pgData['sort_order'] ?? 0,
                    'effective_from' => $pgData['effective_from'] ? \Carbon\Carbon::parse($pgData['effective_from']) : null,
                    'effective_to' => $pgData['effective_to'] ? \Carbon\Carbon::parse($pgData['effective_to']) : null,
                    'is_active' => $pgData['is_active'] ?? true,
                ]
            );

            if ($localPg->wasRecentlyCreated) {
                $portGroupsCreated++;
            } else {
                $portGroupsUpdated++;
            }

            $portGroupCodeToLocalId[$pgData['code']] = $localPg->id;

            // Import port group members
            if (!empty($pgData['members']) && is_array($pgData['members']) && !$dryRun) {
                foreach ($pgData['members'] as $memberData) {
                    // Map production port ID to local port ID
                    $localPortId = null;
                    foreach ($portsRef as $portRef) {
                        if ($portRef['id'] == $memberData['port_id']) {
                            if (isset($portCodeToLocalId[$portRef['code']])) {
                                $localPortId = $portCodeToLocalId[$portRef['code']];
                                break;
                            }
                        }
                    }

                    if ($localPortId) {
                        \App\Models\CarrierPortGroupMember::updateOrCreate(
                            [
                                'carrier_port_group_id' => $localPg->id,
                                'port_id' => $localPortId,
                            ],
                            [
                                'is_active' => $memberData['is_active'] ?? true,
                            ]
                        );
                    }
                }
            }
        }

        $this->info("✅ Port groups: {$portGroupsCreated} created, {$portGroupsUpdated} updated");
        $this->newLine();

        $mappingsCreated = 0;
        $mappingsUpdated = 0;
        $mappingsSkipped = 0;
        $tariffsCreated = 0;
        $tariffsUpdated = 0;
        $errors = [];

        $progressBar = $this->output->createProgressBar(count($mappingsData));
        $progressBar->setFormat('verbose');

        foreach ($mappingsData as $mappingData) {
            try {
                // Find article by code
                $article = null;
                if (!empty($mappingData['article_code'])) {
                    $article = RobawsArticleCache::where('article_code', $mappingData['article_code'])->first();
                }

                if (!$article) {
                    $mappingsSkipped++;
                    $errors[] = "Article not found: {$mappingData['article_code']}";
                    $progressBar->advance();
                    continue;
                }

                // Map port_ids (production IDs -> local IDs via port codes)
                $localPortIds = [];
                if (!empty($mappingData['port_ids']) && is_array($mappingData['port_ids'])) {
                    // Map production port IDs to local port IDs using port codes
                    foreach ($portsRef as $portRef) {
                        if (in_array($portRef['id'], $mappingData['port_ids'])) {
                            if (isset($portCodeToLocalId[$portRef['code']])) {
                                $localPortIds[] = $portCodeToLocalId[$portRef['code']];
                            }
                        }
                    }
                    $localPortIds = array_unique($localPortIds);
                    if (empty($localPortIds)) {
                        $localPortIds = null;
                    }
                }

                // Map category_group_ids (production IDs -> local IDs via codes)
                $localCategoryGroupIds = [];
                if (!empty($mappingData['category_group_ids']) && is_array($mappingData['category_group_ids'])) {
                    // Map production category group IDs to local IDs using codes
                    foreach ($categoryGroupsRef as $cgRef) {
                        if (in_array($cgRef['id'], $mappingData['category_group_ids'])) {
                            if (isset($categoryGroupCodeToLocalId[$cgRef['code']])) {
                                $localCategoryGroupIds[] = $categoryGroupCodeToLocalId[$cgRef['code']];
                            }
                        }
                    }
                    $localCategoryGroupIds = array_unique($localCategoryGroupIds);
                    if (empty($localCategoryGroupIds)) {
                        $localCategoryGroupIds = null;
                    }
                }

                // Create or update mapping
                $mapping = CarrierArticleMapping::updateOrCreate(
                    [
                        'carrier_id' => $carrier->id,
                        'article_id' => $article->id,
                    ],
                    [
                        'name' => $mappingData['name'] ?? ($article->article_name . ' Mapping'),
                        'port_ids' => !empty($localPortIds) ? $localPortIds : null,
                        'port_group_ids' => $mappingData['port_group_ids'] ?? null,
                        'vehicle_categories' => $mappingData['vehicle_categories'] ?? null,
                        'category_group_ids' => $localCategoryGroupIds,
                        'vessel_names' => $mappingData['vessel_names'] ?? null,
                        'vessel_classes' => $mappingData['vessel_classes'] ?? null,
                        'is_active' => $mappingData['is_active'] ?? true,
                        'sort_order' => $mappingData['sort_order'] ?? 0,
                    ]
                );

                if ($mapping->wasRecentlyCreated) {
                    $mappingsCreated++;
                } else {
                    $mappingsUpdated++;
                }

                // Import tariffs for this mapping
                $mappingTariffs = array_filter($tariffsData, function ($t) use ($mappingData) {
                    return $t['carrier_article_mapping_id'] == $mappingData['id'];
                });

                foreach ($mappingTariffs as $tariffData) {
                    if ($dryRun) {
                        continue;
                    }

                    $tariff = CarrierPurchaseTariff::updateOrCreate(
                        [
                            'carrier_article_mapping_id' => $mapping->id,
                            'effective_from' => $tariffData['effective_from'] ? \Carbon\Carbon::parse($tariffData['effective_from']) : null,
                        ],
                        [
                            'effective_to' => $tariffData['effective_to'] ? \Carbon\Carbon::parse($tariffData['effective_to']) : null,
                            'update_date' => $tariffData['update_date'] ? \Carbon\Carbon::parse($tariffData['update_date']) : null,
                            'validity_date' => $tariffData['validity_date'] ? \Carbon\Carbon::parse($tariffData['validity_date']) : null,
                            'is_active' => $tariffData['is_active'] ?? true,
                            'sort_order' => $tariffData['sort_order'] ?? 0,
                            'currency' => $tariffData['currency'] ?? 'EUR',
                            'base_freight_amount' => $tariffData['base_freight_amount'],
                            'base_freight_unit' => $tariffData['base_freight_unit'] ?? 'LUMPSUM',
                            'baf_amount' => $tariffData['baf_amount'],
                            'baf_unit' => $tariffData['baf_unit'] ?? 'LUMPSUM',
                            'ets_amount' => $tariffData['ets_amount'],
                            'ets_unit' => $tariffData['ets_unit'] ?? 'LUMPSUM',
                            'port_additional_amount' => $tariffData['port_additional_amount'],
                            'port_additional_unit' => $tariffData['port_additional_unit'] ?? 'LUMPSUM',
                            'admin_fxe_amount' => $tariffData['admin_fxe_amount'],
                            'admin_fxe_unit' => $tariffData['admin_fxe_unit'] ?? 'LUMPSUM',
                            'thc_amount' => $tariffData['thc_amount'],
                            'thc_unit' => $tariffData['thc_unit'] ?? 'LUMPSUM',
                            'measurement_costs_amount' => $tariffData['measurement_costs_amount'],
                            'measurement_costs_unit' => $tariffData['measurement_costs_unit'] ?? 'LUMPSUM',
                            'congestion_surcharge_amount' => $tariffData['congestion_surcharge_amount'],
                            'congestion_surcharge_unit' => $tariffData['congestion_surcharge_unit'] ?? 'LUMPSUM',
                            'iccm_amount' => $tariffData['iccm_amount'],
                            'iccm_unit' => $tariffData['iccm_unit'] ?? 'LUMPSUM',
                            'freight_tax_amount' => $tariffData['freight_tax_amount'] ?? null,
                            'freight_tax_unit' => $tariffData['freight_tax_unit'] ?? null,
                            'source' => $tariffData['source'] ?? 'production_import',
                            'notes' => $tariffData['notes'],
                        ]
                    );

                    if ($tariff->wasRecentlyCreated) {
                        $tariffsCreated++;
                    } else {
                        $tariffsUpdated++;
                    }
                }

            } catch (\Exception $e) {
                $errors[] = "Error processing mapping {$mappingData['id']}: {$e->getMessage()}";
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Initialize counters
        $acceptanceRulesCreated = 0;
        $acceptanceRulesUpdated = 0;
        $transformRulesCreated = 0;
        $transformRulesUpdated = 0;
        $surchargeRulesCreated = 0;
        $surchargeRulesUpdated = 0;
        $clausesCreated = 0;
        $clausesUpdated = 0;

        // Import acceptance rules
        if (!empty($acceptanceRulesData)) {
            $this->info('✅ Importing acceptance rules...');

            foreach ($acceptanceRulesData as $ruleData) {
                if ($dryRun) {
                    $acceptanceRulesCreated++;
                    continue;
                }

                // Map port_id, port_ids, port_group_ids, category_group_ids
                $mappedData = $this->mapRuleData($ruleData, $portsRef, $portCodeToLocalId, $portGroupCodeToLocalId, $categoryGroupCodeToLocalId, $categoryGroupsRef, $portGroupsRefData);

                $rule = CarrierAcceptanceRule::updateOrCreate(
                    [
                        'carrier_id' => $carrier->id,
                        'id' => $ruleData['id'] ?? null,
                    ],
                    array_merge($mappedData, [
                        'carrier_id' => $carrier->id,
                    ])
                );

                if ($rule->wasRecentlyCreated) {
                    $acceptanceRulesCreated++;
                } else {
                    $acceptanceRulesUpdated++;
                }
            }

            $this->info("✅ Acceptance rules: {$acceptanceRulesCreated} created, {$acceptanceRulesUpdated} updated");
            $this->newLine();
        }

        // Import transform rules
        if (!empty($transformRulesData)) {
            $this->info('🔄 Importing transform rules...');
            $transformRulesCreated = 0;
            $transformRulesUpdated = 0;

            foreach ($transformRulesData as $ruleData) {
                if ($dryRun) {
                    $transformRulesCreated++;
                    continue;
                }

                $mappedData = $this->mapRuleData($ruleData, $portsRef, $portCodeToLocalId, $portGroupCodeToLocalId, $categoryGroupCodeToLocalId, $categoryGroupsRef, $portGroupsRefData);

                $rule = CarrierTransformRule::updateOrCreate(
                    [
                        'carrier_id' => $carrier->id,
                        'id' => $ruleData['id'] ?? null,
                    ],
                    array_merge($mappedData, [
                        'carrier_id' => $carrier->id,
                    ])
                );

                if ($rule->wasRecentlyCreated) {
                    $transformRulesCreated++;
                } else {
                    $transformRulesUpdated++;
                }
            }

            $this->info("✅ Transform rules: {$transformRulesCreated} created, {$transformRulesUpdated} updated");
            $this->newLine();
        }

        // Import surcharge rules (includes article_id)
        if (!empty($surchargeRulesData)) {
            $this->info('💰 Importing surcharge rules...');
            $surchargeRulesCreated = 0;
            $surchargeRulesUpdated = 0;

            foreach ($surchargeRulesData as $ruleData) {
                if ($dryRun) {
                    $surchargeRulesCreated++;
                    continue;
                }

                $mappedData = $this->mapRuleData($ruleData, $portsRef, $portCodeToLocalId, $portGroupCodeToLocalId, $categoryGroupCodeToLocalId, $categoryGroupsRef, $portGroupsRefData);

                // Map article_id from article_code if present
                if (!empty($ruleData['article_code'])) {
                    $article = RobawsArticleCache::where('article_code', $ruleData['article_code'])->first();
                    if ($article) {
                        $mappedData['article_id'] = $article->id;
                    }
                }

                $rule = CarrierSurchargeRule::updateOrCreate(
                    [
                        'carrier_id' => $carrier->id,
                        'id' => $ruleData['id'] ?? null,
                    ],
                    array_merge($mappedData, [
                        'carrier_id' => $carrier->id,
                    ])
                );

                if ($rule->wasRecentlyCreated) {
                    $surchargeRulesCreated++;
                } else {
                    $surchargeRulesUpdated++;
                }
            }

            $this->info("✅ Surcharge rules: {$surchargeRulesCreated} created, {$surchargeRulesUpdated} updated");
            $this->newLine();
        }

        // Import clauses
        if (!empty($clausesData)) {
            $this->info('📜 Importing clauses...');
            $clausesCreated = 0;
            $clausesUpdated = 0;

            foreach ($clausesData as $clauseData) {
                if ($dryRun) {
                    $clausesCreated++;
                    continue;
                }

                // Map port_id
                $localPortId = null;
                if (!empty($clauseData['port_id'])) {
                    foreach ($portsRef as $portRef) {
                        if ($portRef['id'] == $clauseData['port_id']) {
                            if (isset($portCodeToLocalId[$portRef['code']])) {
                                $localPortId = $portCodeToLocalId[$portRef['code']];
                                break;
                            }
                        }
                    }
                }

                $clause = CarrierClause::updateOrCreate(
                    [
                        'carrier_id' => $carrier->id,
                        'id' => $clauseData['id'] ?? null,
                    ],
                    [
                        'carrier_id' => $carrier->id,
                        'port_id' => $localPortId,
                        'vessel_name' => $clauseData['vessel_name'] ?? null,
                        'vessel_class' => $clauseData['vessel_class'] ?? null,
                        'clause_type' => $clauseData['clause_type'] ?? null,
                        'text' => $clauseData['text'] ?? null,
                        'sort_order' => $clauseData['sort_order'] ?? 0,
                        'effective_from' => $clauseData['effective_from'] ? \Carbon\Carbon::parse($clauseData['effective_from']) : null,
                        'effective_to' => $clauseData['effective_to'] ? \Carbon\Carbon::parse($clauseData['effective_to']) : null,
                        'is_active' => $clauseData['is_active'] ?? true,
                    ]
                );

                if ($clause->wasRecentlyCreated) {
                    $clausesCreated++;
                } else {
                    $clausesUpdated++;
                }
            }

            $this->info("✅ Clauses: {$clausesCreated} created, {$clausesUpdated} updated");
            $this->newLine();
        }

        // Summary
        $this->info('📊 Import Summary:');
        $summary = [
            ['Category Groups Created', $categoryGroupsCreated],
            ['Category Groups Updated', $categoryGroupsUpdated],
            ['Port Groups Created', $portGroupsCreated],
            ['Port Groups Updated', $portGroupsUpdated],
            ['Mappings Created', $mappingsCreated],
            ['Mappings Updated', $mappingsUpdated],
            ['Mappings Skipped', $mappingsSkipped],
            ['Tariffs Created', $tariffsCreated],
            ['Tariffs Updated', $tariffsUpdated],
        ];

        if (!empty($acceptanceRulesData)) {
            $summary[] = ['Acceptance Rules Created', $acceptanceRulesCreated ?? 0];
            $summary[] = ['Acceptance Rules Updated', $acceptanceRulesUpdated ?? 0];
        }
        if (!empty($transformRulesData)) {
            $summary[] = ['Transform Rules Created', $transformRulesCreated ?? 0];
            $summary[] = ['Transform Rules Updated', $transformRulesUpdated ?? 0];
        }
        if (!empty($surchargeRulesData)) {
            $summary[] = ['Surcharge Rules Created', $surchargeRulesCreated ?? 0];
            $summary[] = ['Surcharge Rules Updated', $surchargeRulesUpdated ?? 0];
        }
        if (!empty($clausesData)) {
            $summary[] = ['Clauses Created', $clausesCreated ?? 0];
            $summary[] = ['Clauses Updated', $clausesUpdated ?? 0];
        }

        $this->table(['Action', 'Count'], $summary);

        if (!empty($errors)) {
            $this->newLine();
            $this->warn('⚠️  Errors encountered:');
            foreach (array_slice($errors, 0, 10) as $error) {
                $this->line("  - {$error}");
            }
            if (count($errors) > 10) {
                $this->line("  ... and " . (count($errors) - 10) . " more");
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('💡 This was a dry run. Remove --dry-run to perform actual import.');
        } else {
            $this->newLine();
            $this->info('✅ Import completed!');
        }

        return Command::SUCCESS;
    }

    /**
     * Map rule data (port_ids, port_group_ids, category_group_ids) from production to local IDs
     */
    protected function mapRuleData(array $ruleData, array $portsRef, array $portCodeToLocalId, array $portGroupCodeToLocalId, array $categoryGroupCodeToLocalId, array $categoryGroupsRef = [], array $portGroupsRefData = []): array
    {
        $mapped = [];

        // Map port_id
        if (!empty($ruleData['port_id'])) {
            foreach ($portsRef as $portRef) {
                if ($portRef['id'] == $ruleData['port_id']) {
                    if (isset($portCodeToLocalId[$portRef['code']])) {
                        $mapped['port_id'] = $portCodeToLocalId[$portRef['code']];
                    }
                    break;
                }
            }
        }

        // Map port_ids array
        if (!empty($ruleData['port_ids']) && is_array($ruleData['port_ids'])) {
            $localPortIds = [];
            foreach ($portsRef as $portRef) {
                if (in_array($portRef['id'], $ruleData['port_ids'])) {
                    if (isset($portCodeToLocalId[$portRef['code']])) {
                        $localPortIds[] = $portCodeToLocalId[$portRef['code']];
                    }
                }
            }
            $mapped['port_ids'] = !empty($localPortIds) ? array_unique($localPortIds) : null;
        }

        // Map port_group_ids array
        if (!empty($ruleData['port_group_ids']) && is_array($ruleData['port_group_ids'])) {
            $localPgIds = [];
            foreach ($portGroupsRefData as $pgRef) {
                if (in_array($pgRef['id'], $ruleData['port_group_ids'])) {
                    if (isset($portGroupCodeToLocalId[$pgRef['code']])) {
                        $localPgIds[] = $portGroupCodeToLocalId[$pgRef['code']];
                    }
                }
            }
            $mapped['port_group_ids'] = !empty($localPgIds) ? array_unique($localPgIds) : null;
        }

        // Map category_group_id
        if (!empty($ruleData['category_group_id'])) {
            foreach ($categoryGroupsRef as $cgRef) {
                if ($cgRef['id'] == $ruleData['category_group_id']) {
                    if (isset($categoryGroupCodeToLocalId[$cgRef['code']])) {
                        $mapped['category_group_id'] = $categoryGroupCodeToLocalId[$cgRef['code']];
                    }
                    break;
                }
            }
        }

        // Map category_group_ids array
        if (!empty($ruleData['category_group_ids']) && is_array($ruleData['category_group_ids'])) {
            $localCgIds = [];
            foreach ($categoryGroupsRef as $cgRef) {
                if (in_array($cgRef['id'], $ruleData['category_group_ids'])) {
                    if (isset($categoryGroupCodeToLocalId[$cgRef['code']])) {
                        $localCgIds[] = $categoryGroupCodeToLocalId[$cgRef['code']];
                    }
                }
            }
            $mapped['category_group_ids'] = !empty($localCgIds) ? array_unique($localCgIds) : null;
        }

        // Copy all other fields
        $fieldsToCopy = [
            'name', 'vehicle_category', 'vehicle_categories', 'vessel_name', 'vessel_names',
            'vessel_class', 'vessel_classes', 'min_length_cm', 'min_width_cm', 'min_height_cm',
            'min_cbm', 'min_weight_kg', 'max_length_cm', 'max_width_cm', 'max_height_cm',
            'max_cbm', 'max_weight_kg', 'min_is_hard', 'must_be_empty', 'must_be_self_propelled',
            'allow_accessories', 'complete_vehicles_only', 'allows_stacked', 'allows_piggy_back',
            'soft_max_height_cm', 'soft_height_requires_approval', 'soft_max_weight_kg',
            'soft_weight_requires_approval', 'is_free_out', 'requires_waiver', 'waiver_provided_by_carrier',
            'notes', 'priority', 'sort_order', 'effective_from', 'effective_to', 'is_active',
            'transform_code', 'params', 'event_code', 'calc_mode', 'article_id', 'qty_mode',
        ];

        foreach ($fieldsToCopy as $field) {
            if (isset($ruleData[$field])) {
                if (in_array($field, ['effective_from', 'effective_to']) && $ruleData[$field]) {
                    $mapped[$field] = \Carbon\Carbon::parse($ruleData[$field]);
                } else {
                    $mapped[$field] = $ruleData[$field];
                }
            }
        }

        return $mapped;
    }
}
