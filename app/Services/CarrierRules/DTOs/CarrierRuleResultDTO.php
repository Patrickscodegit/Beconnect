<?php

namespace App\Services\CarrierRules\DTOs;

use App\Services\CarrierRules\ChargeableMeasureDTO;

/**
 * Output DTO from carrier rule engine
 */
class CarrierRuleResultDTO
{
    public function __construct(
        public ?string $classifiedVehicleCategory, // One of 22 keys
        public ?string $matchedCategoryGroup = null, // e.g., 'LM_CARGO', 'HH' - optional
        public string $acceptanceStatus = 'ALLOWED', // 'ALLOWED', 'ALLOWED_WITH_SURCHARGES', 'ALLOWED_UPON_REQUEST', 'NOT_ALLOWED'
        public array $violations = [], // ['max_height_exceeded', 'must_be_empty_required']
        public array $approvalsRequired = [], // ['soft_height_approval', 'soft_weight_approval']
        public array $warnings = [], // ['min_length_below', 'min_weight_below'] - soft min limit violations
        public ChargeableMeasureDTO $chargeableMeasure, // base_lm, chargeable_lm, transform_reason, rule_id
        public array $surchargeEvents = [], // [['event_code' => 'OVERHEIGHT', 'qty' => 1, 'amount_basis' => 'FLAT', 'params' => [...], 'matched_rule_id' => 123, 'reason' => '...']]
        public array $quoteLineDrafts = [], // [['article_id' => 123, 'qty' => 1, 'amount_override' => null, 'meta' => [...]]]
    ) {}
}

