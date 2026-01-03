<?php

namespace App\Services\Pricing;

use App\Models\CarrierArticleMapping;
use App\Models\CarrierPurchaseTariff;
use App\Models\Port;
use App\Models\ShippingCarrier;
use App\Filament\Resources\CarrierRuleResource;

class GrimaldiPurchaseRatesOverviewService
{
    /**
     * Get rates matrix for Grimaldi purchase tariffs in PDF-style format
     */
    public function getRatesMatrix(): array
    {
        // #region agent log
        @file_put_contents(base_path('.cursor/debug.log'), json_encode([
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'A',
            'location' => 'GrimaldiPurchaseRatesOverviewService.php:18',
            'message' => 'getRatesMatrix entry',
            'data' => [
                'db_driver' => \Illuminate\Support\Facades\DB::getDriverName(),
            ],
            'timestamp' => time() * 1000
        ]) . "\n", FILE_APPEND);
        // #endregion

        // Find Grimaldi carrier - use database-agnostic case-insensitive matching
        $useIlike = \Illuminate\Support\Facades\DB::getDriverName() === 'pgsql';
        
        // #region agent log
        @file_put_contents(base_path('.cursor/debug.log'), json_encode([
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'A',
            'location' => 'GrimaldiPurchaseRatesOverviewService.php:25',
            'message' => 'Carrier query setup',
            'data' => [
                'use_ilike' => $useIlike,
                'db_driver' => \Illuminate\Support\Facades\DB::getDriverName(),
            ],
            'timestamp' => time() * 1000
        ]) . "\n", FILE_APPEND);
        // #endregion

        $carrier = ShippingCarrier::where('code', 'GRIMALDI');
        
        if ($useIlike) {
            $carrier = $carrier->orWhere('name', 'ILIKE', '%grimaldi%');
        } else {
            $carrier = $carrier->orWhereRaw('LOWER(name) LIKE ?', ['%grimaldi%']);
        }
        
        $carrier = $carrier->firstOrFail();

        // #region agent log
        @file_put_contents(base_path('.cursor/debug.log'), json_encode([
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'A',
            'location' => 'GrimaldiPurchaseRatesOverviewService.php:38',
            'message' => 'Carrier found',
            'data' => [
                'carrier_id' => $carrier->id,
                'carrier_code' => $carrier->code,
                'carrier_name' => $carrier->name,
            ],
            'timestamp' => time() * 1000
        ]) . "\n", FILE_APPEND);
        // #endregion

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

        $ports = [];
        $maxEffectiveDate = null;
        $hasCongestion = false;
        $hasIccm = false;

        // Process each mapping
        foreach ($mappings as $mapping) {
            $tariff = $mapping->activePurchaseTariff();
            
            // Get port information
            $portInfo = $this->getPortFromMapping($mapping);
            if (!$portInfo) {
                continue; // Skip if no port can be determined
            }

            $portCode = $portInfo['code'];
            $portName = $portInfo['name'];

            // Determine PDF category
            $pdfCategory = $this->determinePdfCategory($mapping);
            if (!$pdfCategory) {
                continue; // Skip if category cannot be determined
            }

            // Initialize port if not exists
            if (!isset($ports[$portCode])) {
                $ports[$portCode] = [
                    'code' => $portCode,
                    'name' => $portName,
                    'tariff_ids' => [],
                    'categories' => [
                        'CAR' => null,
                        'SVAN' => null,
                        'BVAN' => null,
                        'LM' => null,
                    ],
                ];
            }

            // Build edit URL
            $editUrl = null;
            if ($mapping->carrier_id) {
                $editUrl = CarrierRuleResource::getUrl('edit', [
                    'record' => $mapping->carrier_id
                ]) . '?mapping_id=' . $mapping->id;
            }

            // Build category entry with tariff_id
            $tariffId = $tariff ? $tariff->id : null;
            
            $ports[$portCode]['categories'][$pdfCategory] = [
                'tariff_id' => $tariffId,
                'tariff' => $tariff,
                'mapping_id' => $mapping->id,
                'carrier_id' => $mapping->carrier_id,
                'edit_url' => $editUrl,
            ];
            
            // Add tariff_id to port's tariff_ids array if not null
            if ($tariffId && !in_array($tariffId, $ports[$portCode]['tariff_ids'])) {
                $ports[$portCode]['tariff_ids'][] = $tariffId;
            }

            // Track effective date
            if ($tariff && $tariff->effective_from) {
                if ($maxEffectiveDate === null || $tariff->effective_from > $maxEffectiveDate) {
                    $maxEffectiveDate = $tariff->effective_from;
                }
            }

            // Track surcharge flags
            if ($tariff) {
                if ($tariff->congestion_surcharge_amount) {
                    $hasCongestion = true;
                }
                if ($tariff->iccm_amount) {
                    $hasIccm = true;
                }
            }
        }

        // Sort ports by code and sort tariff_ids arrays
        ksort($ports);
        foreach ($ports as $portCode => &$portData) {
            sort($portData['tariff_ids']);
        }
        unset($portData);

        return [
            'ports' => $ports,
            'category_order' => ['CAR', 'SVAN', 'BVAN', 'LM'],
            'effective_date' => $maxEffectiveDate ? $maxEffectiveDate->format('Y-m-d') : null,
            'has_congestion' => $hasCongestion,
            'has_iccm' => $hasIccm,
        ];
    }

    /**
     * Determine PDF category from mapping
     * Returns 'CAR'|'SVAN'|'BVAN'|'LM'|null
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

    /**
     * Get port information from mapping
     * Returns array{code: string, name: string}|null
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
     * Safely get unit field from tariff, with default
     */
    public function safeGetUnit(?CarrierPurchaseTariff $tariff, string $fieldName, string $default = 'LUMPSUM'): string
    {
        if (!$tariff) {
            return $default;
        }

        $unit = $tariff->getAttribute($fieldName);
        return $unit ?: $default;
    }
}

