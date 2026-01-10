<?php

namespace App\Services\Pricing;

use App\Models\CarrierArticleMapping;
use App\Models\CarrierPurchaseTariff;
use App\Models\Port;
use App\Models\ShippingCarrier;
use App\Filament\Resources\CarrierRuleResource;
use App\Services\Carrier\CarrierLookupService;

class GrimaldiPurchaseRatesOverviewService
{
    /**
     * Get rates matrix for Grimaldi purchase tariffs in PDF-style format
     */
    public function getRatesMatrix(): array
    {
        // Find Grimaldi carrier using CarrierLookupService
        $carrier = app(CarrierLookupService::class)->findGrimaldi();
        
        if (!$carrier) {
            // Return empty matrix if carrier not found (instead of throwing)
            return [
                'ports' => [],
                'category_order' => ['CAR', 'SVAN', 'BVAN', 'LM'],
                'effective_date' => null,
                'has_congestion' => false,
                'has_iccm' => false,
            ];
        }

        // Load all Grimaldi mappings with eager loading
        $mappings = CarrierArticleMapping::where('carrier_id', $carrier->id)
            ->with([
                'article' => function ($query) {
                    $query->select('id', 'pod_code', 'pod', 'article_code', 'article_name', 'unit_price', 'currency', 
                        'update_date', 'validity_date', 'update_date_override', 'validity_date_override');
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
                    'oldest_update_date' => null,
                    'oldest_validity_date' => null,
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
            
            // Prefer mappings with tariffs when there are duplicates for the same port/category
            $existingCategory = $ports[$portCode]['categories'][$pdfCategory] ?? null;
            if ($existingCategory && $existingCategory['tariff'] && !$tariff) {
                // Existing entry has a tariff and current doesn't, keep the existing one
                continue;
            }
            
            // Include article data if available
            $articleData = null;
            if ($mapping->article) {
                $articleData = [
                    'id' => $mapping->article->id,
                    'article_code' => $mapping->article->article_code,
                    'article_name' => $mapping->article->article_name,
                    'unit_price' => $mapping->article->unit_price,
                    'currency' => $mapping->article->currency ?? 'EUR',
                ];
                
                // Track oldest dates from article cache for this port
                // Only collect dates from articles that are actually being displayed (not skipped)
                $effectiveUpdateDate = $mapping->article->effective_update_date;
                $effectiveValidityDate = $mapping->article->effective_validity_date;
                
                // Ensure date keys exist before accessing them (defensive check)
                if (!isset($ports[$portCode]['oldest_update_date'])) {
                    $ports[$portCode]['oldest_update_date'] = null;
                }
                if (!isset($ports[$portCode]['oldest_validity_date'])) {
                    $ports[$portCode]['oldest_validity_date'] = null;
                }
                
                if ($effectiveUpdateDate) {
                    $currentOldest = $ports[$portCode]['oldest_update_date'];
                    if ($currentOldest === null || $effectiveUpdateDate->lt($currentOldest)) {
                        $ports[$portCode]['oldest_update_date'] = $effectiveUpdateDate;
                    }
                }
                
                if ($effectiveValidityDate) {
                    $currentOldest = $ports[$portCode]['oldest_validity_date'];
                    if ($currentOldest === null || $effectiveValidityDate->lt($currentOldest)) {
                        $ports[$portCode]['oldest_validity_date'] = $effectiveValidityDate;
                    }
                }
            }
            
            $ports[$portCode]['categories'][$pdfCategory] = [
                'tariff_id' => $tariffId,
                'tariff' => $tariff,
                'mapping_id' => $mapping->id,
                'carrier_id' => $mapping->carrier_id,
                'edit_url' => $editUrl,
                'article' => $articleData,
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

        // Add create URLs for missing categories
        $expectedCategories = ['CAR', 'SVAN', 'BVAN', 'LM'];
        foreach ($ports as $portCode => &$portData) {
            $categories = $portData['categories'] ?? [];
            foreach ($expectedCategories as $category) {
                if (!isset($categories[$category]) || $categories[$category] === null) {
                    // No mapping exists for this category, add create URL
                    if (!isset($portData['categories'])) {
                        $portData['categories'] = [];
                    }
                    $portData['categories'][$category] = [
                        'tariff_id' => null,
                        'tariff' => null,
                        'mapping_id' => null,
                        'carrier_id' => $carrier->id,
                        'edit_url' => null,
                        'create_url' => CarrierRuleResource::getUrl('edit', [
                            'record' => $carrier->id
                        ]) . '?port_code=' . urlencode($portCode) . '&category=' . urlencode($category) . '#article_mappings',
                        'article' => null,
                    ];
                }
            }
        }
        unset($portData);

        // Sort ports by code and sort tariff_ids arrays
        ksort($ports);
        foreach ($ports as $portCode => &$portData) {
            sort($portData['tariff_ids']);
            // Ensure date keys always exist (defensive programming)
            if (!isset($portData['oldest_update_date'])) {
                $portData['oldest_update_date'] = null;
            }
            if (!isset($portData['oldest_validity_date'])) {
                $portData['oldest_validity_date'] = null;
            }
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

        // Fallback to article code/name patterns (for mappings without category groups)
        if ($mapping->article) {
            $articleCode = strtoupper($mapping->article->article_code ?? '');
            $articleName = strtoupper($mapping->article->article_name ?? '');
            
            // Check article code suffix patterns
            if (str_ends_with($articleCode, 'CAR') || str_contains($articleCode, 'CAR')) {
                return 'CAR';
            } elseif (str_ends_with($articleCode, 'SV') || str_contains($articleCode, 'SV')) {
                return 'SVAN';
            } elseif (str_ends_with($articleCode, 'BV') || str_contains($articleCode, 'BV')) {
                return 'BVAN';
            } elseif (str_ends_with($articleCode, 'HH') || str_contains($articleCode, 'LM') || str_contains($articleCode, 'HH')) {
                return 'LM';
            }
            
            // Check article name patterns
            if (str_contains($articleName, ' CAR ') || str_contains($articleName, ' CAR,') || str_contains($articleName, ' CAR.')) {
                return 'CAR';
            } elseif (str_contains($articleName, ' SMALL VAN') || str_contains($articleName, ' SMALLVAN')) {
                return 'SVAN';
            } elseif (str_contains($articleName, ' BIG VAN') || str_contains($articleName, ' BIGVAN')) {
                return 'BVAN';
            } elseif (str_contains($articleName, ' LM ') || str_contains($articleName, ' LM,') || str_contains($articleName, ' LM.') || str_contains($articleName, ' LM SEAFREIGHT') || str_contains($articleName, ' LM CARGO')) {
                return 'LM';
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
            
            // Try pod_code first - check database first, then use directly if not found
            if ($article->pod_code) {
                $port = Port::where('code', $article->pod_code)->first();
                if ($port) {
                    return [
                        'code' => $port->code,
                        'name' => $port->name,
                    ];
                }
                // Port record doesn't exist, but we have the code - extract name from pod field if available
                if ($article->pod) {
                    // Try to extract name from format like "Cotonou (COO), Benin"
                    if (preg_match('/^([^(]+)\s*\(([A-Z]{3,4})\)/', $article->pod, $matches)) {
                        $portName = trim($matches[1]);
                        return [
                            'code' => $article->pod_code,
                            'name' => $portName,
                        ];
                    }
                }
                // Fallback: use pod_code as both code and name
                return [
                    'code' => $article->pod_code,
                    'name' => $article->pod_code,
                ];
            }

            // Try extracting from pod field (format: "Port Name (CODE), Country")
            if ($article->pod) {
                // Extract code and name from format like "Cotonou (COO), Benin" or "Abidjan (ABJ), Côte d'Ivoire"
                if (preg_match('/^([^(]+)\s*\(([A-Z]{3,4})\)/', $article->pod, $matches)) {
                    $portName = trim($matches[1]);
                    $portCode = $matches[2];
                    // Try database lookup first
                    $port = Port::where('code', $portCode)->first();
                    if ($port) {
                        return [
                            'code' => $port->code,
                            'name' => $port->name,
                        ];
                    }
                    // Port record doesn't exist, but we have code and name from pod field
                    return [
                        'code' => $portCode,
                        'name' => $portName,
                    ];
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

