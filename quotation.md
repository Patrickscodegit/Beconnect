## Quotation Logic (End-To-End)

This document describes the full quotation flow as implemented today, from
commodity inputs through carrier rules, selection, pricing, and recalculation.

---

## 1) Data Model Map

### QuotationRequest
- Holds quotation metadata and pricing totals.
- Relationships:
  - `commodityItems` (`QuotationCommodityItem`)
  - `selectedSchedule` (`ShippingSchedule`)
  - `quotationRequestArticles` (`QuotationRequestArticle`)
- Pricing is computed via `calculateTotals()` which writes `subtotal`,
  `total_excl_vat`, `vat_amount`, `total_incl_vat`.
- When `selected_schedule_id` changes, the pricing orchestrator is triggered
  after commit to reprocess carrier rules and recalculations.
- Stores resolved carrier clauses in `carrier_clauses` (JSON) for display on
  customer/admin/print views.

### QuotationCommodityItem
- Represents the unit(s) being quoted.
- Stores:
  - Dimensions: `length_cm`, `width_cm`, `height_cm`, `stack_*`
  - Weight: `weight_kg`, `stack_weight_kg`
  - Calculated LM: `lm`, `chargeable_lm`
  - Carrier rule metadata in `carrier_rule_meta`

### RobawsArticleCache
- Article cache used for selection and pricing.
- Stores:
  - Route info (`pol`, `pod`, `pol_port_id`, `pod_port_id`)
  - `commodity_type`, `shipping_carrier_id`, `transport_mode`
  - `max_dimensions_breakdown` derived from acceptance rules

### QuotationRequestArticle
- Selected pricing lines for a quotation.
- Tracks unit type, quantity, and pricing.
- `carrier_rule_applied` and event code are set for carrier-rule line items.

---

## 2) Quotation Flow Overview

1. Commodity item is saved or schedule changes.
2. Carrier rule processing applies acceptance rules, LM calculations, and surcharges.
3. Selection suggestions and strict mapping determine which parent articles are valid.
4. Auto-add logic adds missing parent articles by commodity type.
5. Article quantities are recalculated based on commodity matching and unit types.
6. Pricing totals are recalculated with VAT logic.

---

## 3) Carrier Rule Processing (Per Commodity Item)
Entry point: `CarrierRuleIntegrationService::processCommodityItem()`.

Steps:
1. Build `CargoInputDTO` from the commodity item:
   - Dimensions use `stack_*` when present, otherwise base fields.
   - Weight uses `stack_weight_kg` or `weight_kg`.
   - CBM is computed when missing for trailer categories.
2. Resolve acceptance rules and validate min/max limits.
3. If max limits are exceeded, apply transform (category can shift to
   `high_and_heavy`).
4. Compute chargeable LM via `ChargeableMeasureService`.
5. Generate surcharge events and map to quote line drafts.
6. Sync surcharge articles (add/update/remove) and mark them as
   `carrier_rule_applied`.
7. Resolve and store carrier clauses on the quotation (by carrier/POD/vessel).
8. Remove non-carrier-rule articles that no longer match strict mappings.

---

## 4) Article Selection Pipeline
Primary selection logic: `SmartArticleSelectionService::calculateSuggestions()`.

### Base scope
Uses `RobawsArticleCache::forQuotationContext($quotation)`:
- Filters for active articles.
- Prefers parent articles if they exist.
- Applies early carrier-rule mappings before strict POL/POD filtering.

### Strict mapping
Uses carrier category group mappings derived from commodity categories.

### Dimension-aligned override
When carrier rules record max-limit violations:
- The service evaluates alternative vehicle categories (e.g. `car -> small_van`)
  using acceptance rules.
- The first category that fits max dimensions/weight is used to select category
  group mappings.
- If strict mapping yields no suggestions, mapped IDs are reloaded directly.

### Strict eligibility check
- `QuotationRequest::addArticle()` calls `SmartArticleSelectionService::isStrictlyEligible()`.
- If the article is not strictly eligible and no admin override is allowed, it
  is blocked.

---

## 5) Commodity Type Mapping
`QuotationCommodityItem::normalizeCommodityTypes()` maps items to Robaws types.

Base mapping by vehicle category:
- `car -> CAR`
- `small_van -> SMALL VAN`
- `big_van -> BIG VAN`, `LM CARGO`
- `truck/trailer/truckhead -> TRUCK`, `HH`, `LM CARGO`

Dimension-aligned override:
- If max-limit violations exist, the first acceptance rule that fits determines
  a new vehicle category.
- That category’s article type is added to the mapped types so quantities
  are computed correctly (e.g., Small Van instead of LM).

---

## 6) Article Quantity And Recalculation
Entry point: `QuotationPricingOrchestrator::recalculateForCommodityItem()`.

It:
1. Reprocesses carrier rules for the item.
2. Runs `QuotationCommodityItem::recalculateQuotationArticles()`.
3. Recalculates totals.

Quantity rules during recalculation:
- `SHIPM.` unit type is forced to quantity `1`.
- `LM` articles use LM-based quantity logic.
- For stacked items, `stack_length_cm`/`stack_width_cm` are treated as overall
  stack dimensions (no extra multiplier by stack unit count).
- Other unit types use commodity matching based on mapped article types.
- Carrier-rule line items are skipped during quantity recalculation.

Auto-add behavior:
- Missing parent articles are auto-added based on distinct commodity types
  (subject to strict eligibility), even if other parent articles already exist.

LM display breakdown:
- Uses the same stack-aware LM calculation as `LmQuantityCalculator`, preferring
  `stack_length_cm`/`stack_width_cm` for stacks and trailer defaults when needed.

---

## 7) Pricing Pipeline

### Article pricing
`QuotationRequest::addArticle()` sets the selling price in this order:
1. Pricing tier (if `pricing_tier_id` and tier are available).
2. Customer role pricing (fallback).

### Totals and VAT
`QuotationRequest::calculateTotals()`:
- Sums article subtotals.
- Applies discount (percentage or amount).
- VAT logic depends on `project_vat_code`.
  - `vrijgesteld VF` and `intracommunautaire levering VF` result in 0% VAT.
  - Default uses `vat_rate` or configured default.

### Pending quotation totals (UI)
- If not quoted yet, the customer overview shows a calculated total from
  article subtotals and displays a note that pricing is subject to review.
- The summary card uses the same calculated total when available.

---

## 8) Quotation Display Notes

### Selected Services
- Uses `sales_name` as the primary label when available, with fallback to
  `article_name` / `description`.
- LM articles show a detailed LM breakdown.

### Cargo Description Summary
- `cargo_description` is a generated summary of commodity items.
- The summary format mirrors `RobawsFieldGenerator::generateCargoField()` so Filament and exports stay consistent.
- When commodity items are created, updated, or deleted, the quotation’s `cargo_description` is updated accordingly.

### Intro Templates
- Intro text is selected via `OfferTemplate` based on `service_type` (and optionally `customer_type`).
- On customer/prospect submission, `OfferTemplateService::applyTemplates()` renders and stores `intro_text` using template variables.
- Public confirmation and customer quotation pages display `renderIntroText()` to keep content consistent with Filament.
- Available variables include: `${POL}`, `${POD}`, `${POR}`, `${FDEST}`, `${CARGO}`, `${CARGO_DESCRIPTION}`, `${ROUTE_PHRASE}`, `${SERVICE_TYPE}`, `${REQUEST_NUMBER}` and schedule-based fields (`${CARRIER}`, `${VESSEL}`, `${VOYAGE}`, `${NEXT_SAILING}`, `${TRANSIT_TIME}`, `${FREQUENCY}`).

### Carrier Clauses
- Resolved carrier clauses are displayed on customer and admin views, grouped
  by clause type.

### General Conditions
- A standard “General Conditions” block is appended to the quotation view and
  included in print output.

---

## 9) Operational Commands (Production)

Audit:
- `php artisan quotation:audit QR-YYYY-NNNN`

Recalculate a single quotation item:
- Use tinker to call `QuotationPricingOrchestrator::recalculateForCommodityItem()`.

---

## 10) Production SSH Access

Host:
- `forge@bconnect.64.226.120.45.nip.io`

App path:
- `/home/forge/app.belgaco.be`

Example session:
```
ssh forge@bconnect.64.226.120.45.nip.io
cd /home/forge/app.belgaco.be
```

Safe commands:
```
git pull origin main
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear
php artisan migrate
```
