<?php

namespace App\Services\CarrierRules\DTOs;

/**
 * Input DTO for carrier rule engine
 */
class CargoInputDTO
{
    public function __construct(
        public int $carrierId,
        public ?int $podPortId,
        public float $lengthCm,
        public float $widthCm,
        public float $heightCm,
        public float $cbm, // Compute if missing
        public float $weightKg,
        public int $unitCount,
        public ?string $commodityType = null, // 'vehicles', 'machinery', etc.
        public ?string $category = null, // 'car', 'truck', etc. (one of 22 keys) - optional for detailed quote
        public ?string $quickBucket = null, // 'CARS', 'SMALL_VANS', etc. - optional for quick quote
        public ?int $categoryGroupId = null, // Category group ID (for acceptance rules, surcharges, etc.)
        public array $flags = [], // ['tank_truck', 'non_self_propelled', 'stacked', 'piggy_back']
        public ?float $basicFreightAmount = null, // Optional: for percentage-based surcharges
        public ?string $vesselName = null, // Optional: for vessel-specific limits
        public ?string $vesselClass = null, // Optional: for vessel-specific limits
    ) {}

    /**
     * Create from QuotationCommodityItem
     */
    public static function fromCommodityItem(
        \App\Models\QuotationCommodityItem $item,
        ?\App\Models\Port $pod = null,
        ?\App\Models\ShippingSchedule $schedule = null
    ): self {
        return new self(
            carrierId: $schedule?->carrier_id ?? 0,
            podPortId: $pod?->id ?? $schedule?->pod_id ?? null,
            lengthCm: (float) ($item->stack_length_cm ?? $item->length_cm ?? 0),
            widthCm: (float) ($item->stack_width_cm ?? $item->width_cm ?? 0),
            heightCm: (float) ($item->stack_height_cm ?? $item->height_cm ?? 0),
            cbm: (float) ($item->stack_cbm ?? $item->cbm ?? 0),
            weightKg: (float) ($item->stack_weight_kg ?? $item->weight_kg ?? 0),
            unitCount: $item->stack_unit_count ?? $item->quantity ?? 1,
            commodityType: $item->commodity_type,
            category: $item->category,
            quickBucket: null, // Will be derived from category groups
            flags: self::extractFlags($item),
            basicFreightAmount: null, // Will be calculated from articles
            vesselName: $schedule?->vessel_name,
            vesselClass: $schedule?->vessel_class,
        );
    }

    /**
     * Extract flags from commodity item
     */
    private static function extractFlags(\App\Models\QuotationCommodityItem $item): array
    {
        $flags = [];

        // Check commodity item boolean fields that map to flags
        // Note: This is a simplified mapping - adjust based on actual commodity item structure
        if ($item->category === 'tank_truck') {
            $flags[] = 'tank_truck';
        }

        // Add more flag mappings as needed based on commodity item structure

        return $flags;
    }
}

