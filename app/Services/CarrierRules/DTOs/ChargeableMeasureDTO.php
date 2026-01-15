<?php

namespace App\Services\CarrierRules\DTOs;

class ChargeableMeasureDTO
{
    public function __construct(
        public float $baseLm,
        public float $chargeableLm,
        public ?int $appliedTransformRuleId,
        public array $meta
    ) {}
}
