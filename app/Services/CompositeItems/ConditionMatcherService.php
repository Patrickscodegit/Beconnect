<?php

namespace App\Services\CompositeItems;

use App\Models\QuotationRequest;
use Illuminate\Support\Collection;

class ConditionMatcherService
{
    /**
     * Check if conditions match for a given quotation
     *
     * @param array|null $conditions JSON conditions from pivot table
     * @param QuotationRequest $quotation The quotation to check against
     * @return bool
     */
    public function matchConditions(?array $conditions, QuotationRequest $quotation): bool
    {
        if (empty($conditions)) {
            return false;
        }

        // Check each condition type
        if (isset($conditions['commodity']) && !$this->matchCommodity($conditions['commodity'], $quotation)) {
            return false;
        }

        if (isset($conditions['dimensions']) && !$this->matchDimensions($conditions['dimensions'], $quotation)) {
            return false;
        }

        if (isset($conditions['route']) && !$this->matchRoute($conditions['route'], $quotation)) {
            return false;
        }

        if (isset($conditions['weight_kg_gt']) && !$this->matchWeight($conditions['weight_kg_gt'], $quotation)) {
            return false;
        }

        if (isset($conditions['carrier']) && !$this->matchCarrier($conditions['carrier'], $quotation)) {
            return false;
        }

        if (isset($conditions['customer_type']) && !$this->matchCustomerType($conditions['customer_type'], $quotation)) {
            return false;
        }

        if (isset($conditions['in_transit_to_empty']) && 
            !$this->matchInTransitTo($conditions['in_transit_to_empty'], $quotation)) {
            return false;
        }

        if (isset($conditions['in_transit_to']) && 
            !$this->matchInTransitToValue($conditions['in_transit_to'], $quotation)) {
            return false;
        }

        // All conditions matched
        return true;
    }

    /**
     * Match commodity types
     *
     * @param array $commodityTypes Array of commodity types to match
     * @param QuotationRequest $quotation
     * @return bool
     */
    public function matchCommodity(array $commodityTypes, QuotationRequest $quotation): bool
    {
        // Check simple commodity_type field
        if ($quotation->commodity_type) {
            $normalized = strtoupper(trim($quotation->commodity_type));
            foreach ($commodityTypes as $type) {
                if (strtoupper(trim($type)) === $normalized) {
                    return true;
                }
            }
        }

        // Check commodityItems
        if ($quotation->commodityItems && $quotation->commodityItems->count() > 0) {
            foreach ($quotation->commodityItems as $item) {
                $itemType = $this->normalizeCommodityType($item);
                if ($itemType) {
                    $normalized = strtoupper(trim($itemType));
                    foreach ($commodityTypes as $type) {
                        if (strtoupper(trim($type)) === $normalized) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Match dimensions (width, length, height)
     *
     * @param array $dimRules Dimension rules (e.g., ['width_m_gt' => 2.50, 'length_m_gt' => 6.00])
     * @param QuotationRequest $quotation
     * @return bool
     */
    public function matchDimensions(array $dimRules, QuotationRequest $quotation): bool
    {
        // Get dimensions from quotation commodityItems (stored in cm, convert to meters)
        $firstItem = $quotation->commodityItems?->first();
        $width = $firstItem ? ($firstItem->width_cm / 100) : 0;
        $length = $firstItem ? ($firstItem->length_cm / 100) : 0;
        $height = $firstItem ? ($firstItem->height_cm / 100) : 0;
        
        // Also check if there are max dimensions across all items
        if ($quotation->commodityItems && $quotation->commodityItems->count() > 1) {
            $maxWidth = $quotation->commodityItems->max('width_cm') / 100;
            $maxLength = $quotation->commodityItems->max('length_cm') / 100;
            $maxHeight = $quotation->commodityItems->max('height_cm') / 100;
            
            $width = max($width, $maxWidth);
            $length = max($length, $maxLength);
            $height = max($height, $maxHeight);
        }

        foreach ($dimRules as $key => $threshold) {
            if (str_ends_with($key, '_gt')) {
                $dimension = match (true) {
                    str_contains($key, 'width') => $width,
                    str_contains($key, 'length') => $length,
                    str_contains($key, 'height') => $height,
                    default => null,
                };

                if ($dimension === null || $dimension <= $threshold) {
                    return false;
                }
            } elseif (str_ends_with($key, '_lt')) {
                $dimension = match (true) {
                    str_contains($key, 'width') => $width,
                    str_contains($key, 'length') => $length,
                    str_contains($key, 'height') => $height,
                    default => null,
                };

                if ($dimension === null || $dimension >= $threshold) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Match POL/POD route
     *
     * @param array $routeRules Route rules (e.g., ['pol' => ['ANR', 'ZEE'], 'pod' => ['CNSHA']])
     * @param QuotationRequest $quotation
     * @return bool
     */
    public function matchRoute(array $routeRules, QuotationRequest $quotation): bool
    {
        if (isset($routeRules['pol'])) {
            $pols = is_array($routeRules['pol']) ? $routeRules['pol'] : [$routeRules['pol']];
            $quotationPol = strtoupper(trim($quotation->pol ?? ''));
            
            if (!empty($quotationPol)) {
                $matched = false;
                foreach ($pols as $pol) {
                    if (strtoupper(trim($pol)) === $quotationPol) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    return false;
                }
            }
        }

        if (isset($routeRules['pod'])) {
            $pods = is_array($routeRules['pod']) ? $routeRules['pod'] : [$routeRules['pod']];
            $quotationPod = strtoupper(trim($quotation->pod ?? ''));
            
            if (!empty($quotationPod)) {
                $matched = false;
                // Extract port code from POD if it's in format "City (CODE), Country"
                $quotationPodCode = $this->extractPortCode($quotationPod);
                
                foreach ($pods as $pod) {
                    $podUpper = strtoupper(trim($pod));
                    // Check exact match or if POD contains the code
                    if ($podUpper === $quotationPod || 
                        $podUpper === $quotationPodCode ||
                        str_contains($quotationPod, $podUpper)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Match weight threshold
     *
     * @param float $weightThreshold Minimum weight in kg
     * @param QuotationRequest $quotation
     * @return bool
     */
    public function matchWeight(float $weightThreshold, QuotationRequest $quotation): bool
    {
        // Sum weight from all commodity items
        $weight = $quotation->commodityItems?->sum('weight_kg') ?? 0;
        
        // Also check bruto_weight if available
        if ($quotation->commodityItems) {
            $brutoWeight = $quotation->commodityItems->sum('bruto_weight_kg') ?? 0;
            $weight = max($weight, $brutoWeight);
        }
        
        return $weight > $weightThreshold;
    }

    /**
     * Match carrier
     *
     * @param array|string $carriers Carrier names or codes
     * @param QuotationRequest $quotation
     * @return bool
     */
    public function matchCarrier(array|string $carriers, QuotationRequest $quotation): bool
    {
        $carrierList = is_array($carriers) ? $carriers : [$carriers];
        
        if ($quotation->selectedSchedule && $quotation->selectedSchedule->carrier) {
            $quotationCarrier = strtoupper(trim($quotation->selectedSchedule->carrier->name ?? ''));
            foreach ($carrierList as $carrier) {
                if (strtoupper(trim($carrier)) === $quotationCarrier) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Match customer type
     *
     * @param array|string $customerTypes Customer types
     * @param QuotationRequest $quotation
     * @return bool
     */
    public function matchCustomerType(array|string $customerTypes, QuotationRequest $quotation): bool
    {
        $typeList = is_array($customerTypes) ? $customerTypes : [$customerTypes];
        $quotationType = strtoupper(trim($quotation->customer_role ?? ''));
        
        foreach ($typeList as $type) {
            if (strtoupper(trim($type)) === $quotationType) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match in_transit_to field
     *
     * @param bool $shouldBeEmpty If true, only match when in_transit_to is empty
     * @param QuotationRequest $quotation
     * @return bool
     */
    public function matchInTransitTo(bool $shouldBeEmpty, QuotationRequest $quotation): bool
    {
        $inTransitTo = trim($quotation->in_transit_to ?? '');
        $isEmpty = empty($inTransitTo);
        
        // If condition requires empty, return true only if it's actually empty
        return $shouldBeEmpty === $isEmpty;
    }

    /**
     * Match in_transit_to field against specific values
     *
     * @param array|string $values Array of values to match (e.g., ["Burkina Faso", "BFA"])
     * @param QuotationRequest $quotation
     * @return bool
     */
    public function matchInTransitToValue(array|string $values, QuotationRequest $quotation): bool
    {
        $valueList = is_array($values) ? $values : [$values];
        $quotationInTransitTo = trim($quotation->in_transit_to ?? '');
        
        if (empty($quotationInTransitTo)) {
            return false;
        }
        
        // Normalize quotation value using CountryService
        $normalizedQuotationValue = \App\Services\Countries\CountryService::normalizeCountryName($quotationInTransitTo);
        
        // Case-insensitive matching
        $quotationInTransitToUpper = strtoupper($normalizedQuotationValue ?? $quotationInTransitTo);
        
        foreach ($valueList as $value) {
            // Normalize condition value as well
            $normalizedValue = \App\Services\Countries\CountryService::normalizeCountryName($value);
            $valueUpper = strtoupper(trim($normalizedValue ?? $value));
            
            // Exact match
            if ($valueUpper === $quotationInTransitToUpper) {
                return true;
            }
            
            // Partial match (e.g., "Burkina Faso" matches "Burkina Faso (BFA)")
            if (str_contains($quotationInTransitToUpper, $valueUpper) || 
                str_contains($valueUpper, $quotationInTransitToUpper)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Extract port code from POD string
     * Examples: "Dakar (DKR), Senegal" -> "DKR", "DKR" -> "DKR"
     *
     * @param string $podString
     * @return string
     */
    private function extractPortCode(string $podString): string
    {
        // Try to extract code from format "City (CODE), Country"
        if (preg_match('/\(([A-Z0-9]+)\)/', $podString, $matches)) {
            return strtoupper(trim($matches[1]));
        }
        
        // If no parentheses, return the string itself (might already be a code)
        return strtoupper(trim($podString));
    }

    /**
     * Normalize commodity type from quotation item
     *
     * @param mixed $item
     * @return string|null
     */
    private function normalizeCommodityType($item): ?string
    {
        if (is_object($item)) {
            return $item->commodity_type ?? $item->type ?? null;
        }
        
        if (is_array($item)) {
            return $item['commodity_type'] ?? $item['type'] ?? null;
        }

        return null;
    }
}

