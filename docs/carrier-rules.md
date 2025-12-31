# Carrier Rules & Surcharge System

## Overview

The Carrier Rules & Surcharge System is a comprehensive rules engine that automatically applies carrier-specific business logic to quotations. It handles:

- **Acceptance Validation**: Validates cargo against carrier limits (dimensions, weight, operational rules)
- **LM Transformations**: Applies carrier-aware LM calculations (e.g., overwidth recalculation)
- **Surcharge Calculation**: Calculates and applies surcharges (tracking, overwidth, weight tiers, etc.)
- **Article Mapping**: Automatically adds surcharge articles to quotations

## System Architecture

### Core Components

1. **ChargeableMeasureService**: Centralized LM calculation with carrier-aware transforms
2. **CarrierRuleResolver**: Resolves which rule applies using specificity scoring
3. **CarrierSurchargeCalculator**: Calculates surcharge amounts based on calc_mode
4. **CarrierRuleEngine**: Main processing engine that orchestrates all rule types
5. **CarrierRuleIntegrationService**: Integrates engine into quotation flow

### Database Tables

- `carrier_category_groups`: Groups of vehicle categories (e.g., CARS, LM_CARGO, HH)
- `carrier_category_group_members`: Maps vehicle categories to groups
- `carrier_acceptance_rules`: Dimension/weight limits and operational requirements
- `carrier_transform_rules`: LM transformation rules (e.g., overwidth recalculation)
- `carrier_surcharge_rules`: Surcharge calculation rules
- `carrier_surcharge_article_maps`: Maps surcharge events to Robaws articles
- `carrier_article_mappings`: Maps articles to vehicle categories/category groups for article selection (ALLOWLIST)
- `carrier_clauses`: Legal, operational, and liability clauses

## Rule Precedence System

Rules are resolved using a **specificity scoring system**. Higher scores win:

| Match Type | Score | Description |
|------------|-------|-------------|
| Vessel Name | +10 | Exact vessel name match |
| Port (POD) | +8 | Exact port match |
| Vessel Class | +6 | Vessel class match |
| Exact Category | +2 | Exact vehicle category match |
| Category Group | +1 | Category group membership (matches if cargo's group is in rule's category_group_ids array) |

**Tie-breakers** (when scores are equal):
1. Higher `priority` value
2. Latest `effective_from` date
3. Highest `id` (most recent)

### Example

```
Rule A: Global car rule (score: 2)
Rule B: Port-specific car rule for Abidjan (score: 2 + 8 = 10)
Rule C: Vessel-specific car rule for "Vessel A" (score: 2 + 10 = 12)

Result: Rule C wins (highest score)
```

## How to Add Rules

### 1. Category Groups

Category groups allow you to create "buckets" for quick quotes (e.g., CARS, LM_CARGO).

**Steps:**
1. Go to **Carrier Rules** → Select carrier → **Category Groups** tab
2. Click **Add Item**
3. Fill in:
   - **Code**: Unique code (e.g., `CARS`, `LM_CARGO`)
   - **Display Name**: User-friendly name
   - **Aliases**: Alternative names (e.g., "LM", "High & Heavy")
   - **Priority**: Higher = checked first
4. Add **Members**: Select vehicle categories that belong to this group

**Example:**
- Code: `LM_CARGO`
- Display Name: `LM Cargo`
- Members: `truck`, `truckhead`, `bus`, `box_truck`

### 2. Acceptance Rules

Acceptance rules validate cargo against carrier limits.

**Steps:**
1. Go to **Acceptance Rules** tab
2. Click **Add Item**
3. Fill in:
   - **Port/Vessel**: Optional - for specific routes/vessels
   - **Vehicle Categories** OR **Category Groups**: Select one or more (mutually exclusive)
   - **Dimension Limits**: Max length, width, height, CBM, weight
   - **Operational Requirements**: Empty, self-propelled, accessories, etc.
   - **Soft Limits**: "Upon request" limits with approval flags

**Note:** Category Groups can be selected as multiple values. A rule matches if the cargo's category group is in the rule's `category_group_ids` array (OR logic). Legacy `category_group_id` (single value) is still supported for backward compatibility.

**Example:**
- Vehicle Category: `car`
- Max Length: `600cm`
- Max Width: `250cm`
- Max Height: `200cm`
- Max Weight: `3500kg`
- Must Be Self-Propelled: `Yes`

### 3. Transform Rules

Transform rules change the chargeable basis (e.g., LM recalculation for overwidth).

**Steps:**
1. Go to **Transforms** tab
2. Click **Add Item**
3. Fill in:
   - **Port/Vessel**: Optional
   - **Transform Type**: Currently only `OVERWIDTH_LM_RECALC`
   - **Trigger Width**: Width in cm that triggers recalculation (e.g., `260`)
   - **Divisor**: Divisor for LM calculation (usually `250` = 2.5m)

**Example:**
- Transform Type: `OVERWIDTH_LM_RECALC`
- Trigger Width: `260cm`
- Divisor: `250cm`
- Meaning: When width > 260cm, recalculate LM = (L × W) / 2.5 (no min width)

### 4. Surcharge Rules

Surcharge rules calculate surcharge amounts based on various calculation modes.

**Steps:**
1. Go to **Surcharges** tab
2. Click **Add Item**
3. Fill in:
   - **Event Code**: Unique identifier (e.g., `TRACKING_PERCENT`, `OVERWIDTH_STEP_BLOCKS`)
   - **Name**: Display name
   - **Calculation Mode**: Select from dropdown
   - **Parameters**: Fill in based on selected mode

**Calculation Modes:**

#### FLAT
- **Params**: `amount` (flat amount)

#### PER_UNIT
- **Params**: `amount` (amount per unit)

#### PERCENT_OF_BASIC_FREIGHT
- **Params**: `percentage` (percentage of basic freight)

#### WEIGHT_TIER
- **Params**: `tiers` (array of `{max_kg, amount}`)

#### WIDTH_STEP_BLOCKS
- **Params**:
  - `trigger_width_gt_cm`: Optional - only apply if width exceeds this
  - `threshold_cm`: Base threshold (usually `250`)
  - `block_cm`: Block size (e.g., `10`, `20`, `25`)
  - `rounding`: `CEIL`, `FLOOR`, or `ROUND`
  - `qty_basis`: `LM` or `UNIT`
  - `amount_per_block`: Amount per block
  - `exclusive_group`: Optional - group name (only one rule per group applies)

#### WIDTH_LM_BASIS
- **Params**:
  - `trigger_width_gt_cm`: Width that triggers surcharge
  - `amount_per_lm`: Amount per LM
  - `exclusive_group`: Optional

**Example - Overwidth Step Blocks:**
- Event Code: `OVERWIDTH_STEP_BLOCKS`
- Calc Mode: `WIDTH_STEP_BLOCKS`
- Trigger Width: `260cm`
- Threshold: `250cm`
- Block Size: `25cm`
- Rounding: `CEIL`
- Qty Basis: `LM`
- Amount Per Block: `50`
- Exclusive Group: `OVERWIDTH`

**Calculation:**
- Width: 288cm
- Over: 288 - 250 = 38cm
- Blocks: ceil(38 / 25) = 2 blocks
- Qty: 2 blocks × 6.912 LM = 13.824
- Amount: 50 per block

### 5. Article Mapping

Article mappings connect surcharge events to actual Robaws articles.

**Steps:**
1. Go to **Article Mapping** tab
2. Click **Add Item**
3. Fill in:
   - **Event Code**: Must match a surcharge rule event_code
   - **Robaws Article**: Select article from dropdown
   - **Quantity Mode**: How quantity is calculated
   - **Override Parameters**: Optional - override default params

**Example:**
- Event Code: `TOWING`
- Article: "Towing Surcharge" (from Robaws)
- Qty Mode: `PER_UNIT`

### 6. Freight Mapping (ALLOWLIST)

Freight Mapping allows you to explicitly control which articles are shown for specific vehicle categories, routes, and contexts. This uses an **ALLOWLIST strategy**: when mappings exist and match the quotation context, only the mapped articles (plus universal articles with `commodity_type IS NULL`) are shown.

**How it works:**

1. **If mappings exist and match context:**
   - Only mapped articles are shown (ALLOWLIST)
   - Universal articles (with `commodity_type IS NULL`) are also included
   - Commodity type string matching is bypassed

2. **If no mappings exist or none match:**
   - System falls back to cleaned commodity type matching
   - Uses existing article commodity types (removed non-existent types like TRUCKHEAD, HH)

**Steps:**
1. Go to **Freight Mapping** tab
2. Click **Add Item**
3. Fill in:
   - **Article**: Select article from Robaws (only active parent articles shown)
   - **Ports (POD)**: Optional - select specific ports
   - **Port Groups**: Optional - select port groups (e.g., WAF, MED)
   - **Vehicle Categories** OR **Category Groups**: Select one or more (mutually exclusive)
   - **Vessel Names/Classes**: Optional - for vessel-specific mappings
   - **Priority**: Higher = checked first
   - **Effective Dates**: When rule is active

**Important Notes:**
- **Vehicle Categories and Category Groups are mutually exclusive** - set one or the other, not both
- **Union behavior**: Multiple matching mappings are combined (all article IDs from all matching mappings are included)
- **Null input handling**: If port/category/vessel is unknown (null), only global rules (no scope) match
- **Route-specific**: Same article can map to different categories for different ports

**Example 1: Global Mapping**
- Article: "Grimaldi(ANR 1333) Dakar Senegal, BIG VAN Seafreight"
- Vehicle Categories: `truckhead`, `truck`, `trailer`
- Ports: (empty - global)
- **Result:** This article shows for truckhead/truck/trailer on all routes

**Example 2: Route-Specific Mapping**
- Article: "Grimaldi(ANR 1333) Dakar Senegal, BIG VAN Seafreight"
- Vehicle Categories: `truckhead`, `truck`, `trailer`
- Port Groups: `Grimaldi_WAF`
- **Result:** This article shows for truckhead/truck/trailer only on WAF routes

**Example 3: Category Group Mapping**
- Article: "Grimaldi(ANR 1333) Abidjan Ivory Coast, LM CARGO Seafreight"
- Category Groups: `LM_CARGO_TRUCKS` (instead of individual vehicle categories)
- Port Groups: `Grimaldi_WAF`
- **Result:** This article shows for all categories in the LM_CARGO_TRUCKS group on WAF routes

**Fallback Behavior:**
If no mappings exist for a carrier, the system uses cleaned commodity type matching:
- `truckhead` → matches articles with commodity types: `LM Cargo`, `Big Van`, `Car`, `Small Van`, `Truck`, `Bus`
- Non-existent types (`TRUCKHEAD`, `HH`) are removed from expansions

### 7. Clauses

Clauses are legal, operational, or liability text that appears in quotations.

**Steps:**
1. Go to **Clauses** tab
2. Click **Add Item**
3. Fill in:
   - **Port/Vessel**: Optional
   - **Clause Type**: `LEGAL`, `OPERATIONAL`, or `LIABILITY`
   - **Text**: Rich text editor

## Examples

### Example 1: Grimaldi West Africa - Car Rules

**Category Group:**
- Code: `CARS`
- Members: `car`, `suv`

**Acceptance Rule:**
- Vehicle Category: `car`
- Max Length: `600cm`
- Max Width: `250cm`
- Max Height: `200cm`
- Max Weight: `3500kg`

**Result:** Cars up to 600×250×200cm and 3500kg are accepted.

### Example 2: Overwidth LM Recalculation

**Transform Rule:**
- Trigger Width: `260cm`
- Divisor: `250cm`

**Complete Calculation Logic:**

1. **If width ≤ trigger_width_gt_cm (260cm):**
   - LM = (Length × 250cm) / 250cm
   - Minimum width of 250cm always applies
   - Example: 1000cm × 255cm → LM = (1000 × 250) / 250 = 10.0 LM

2. **If width > trigger_width_gt_cm (260cm):**
   - LM = (Length × Width) / divisor_cm
   - Actual width is used (no minimum)
   - Example: 1000cm × 280cm → LM = (1000 × 280) / 250 = 11.2 LM

3. **If no transform rules match for the port:**
   - Global fallback: LM = (Length × max(Width, 250cm)) / 250cm
   - Example: 1000cm × 240cm → LM = (1000 × 250) / 250 = 10.0 LM
   - Example: 1000cm × 300cm → LM = (1000 × 300) / 250 = 12.0 LM

**Note:** The trigger width is dynamically extracted from the first matching transform rule, allowing different thresholds per port group (e.g., MED ports use 255cm, WAF ports use 260cm).

### Example 3: Conakry Weight Tiers

**Surcharge Rule:**
- Event Code: `CONAKRY_WEIGHT_TIER`
- Calc Mode: `WEIGHT_TIER`
- Port: Conakry
- Tiers:
  - ≤ 10t: €120
  - ≤ 15t: €180
  - ≤ 20t: €250
  - ≤ 25t: €350
  - > 25t: €500

**Cargo:** 18,000kg → €250 surcharge

### Example 4: Overwidth Step Blocks

**Surcharge Rule:**
- Event Code: `OVERWIDTH_STEP_BLOCKS`
- Calc Mode: `WIDTH_STEP_BLOCKS`
- Trigger: `260cm`
- Threshold: `250cm`
- Block: `25cm`
- Rounding: `CEIL`
- Qty Basis: `LM`
- Amount: `50` per block

**Cargo:** 600cm × 288cm, 6.912 LM

**Calculation:**
- Over: 288 - 250 = 38cm
- Blocks: ceil(38 / 25) = 2 blocks
- Qty: 2 × 6.912 = 13.824
- Amount: 50 per block

## Simulator

The **Simulator** tab allows you to test cargo through the rules engine before creating a quotation.

**Input Fields:**
- Port of Discharge (POD)
- Vessel Name/Class (optional)
- Vehicle Category or Category Group
- Dimensions (Length, Width, Height)
- CBM and Weight
- Unit Count
- Basic Freight Amount (for % calculations)
- Commodity Flags

**Output:**
- Classified Category
- Matched Category Group
- Acceptance Status
- Violations (if any)
- Approvals Required (if any)
- Base LM vs Chargeable LM
- Applied Transformations
- Surcharge Events
- Mapped Articles

## Integration

The system is automatically integrated into the quotation flow:

1. When a commodity item is saved with a schedule/carrier:
   - Cargo is processed through `CarrierRuleEngine`
   - Results are stored in `chargeable_lm` and `carrier_rule_meta`
   - Surcharge articles are automatically added

2. LM calculations use `ChargeableMeasureService`:
   - Base ISO LM: (L × max(W, 2.5)) / 2.5
   - Carrier transforms applied when rules match

3. Article quantities are recalculated:
   - LM articles use chargeable LM
   - Unit-based articles use unit count

## Best Practices

1. **Start with Global Rules**: Create global rules first, then add port/vessel-specific overrides
2. **Use Category Groups**: Group similar categories for easier management. You can select multiple category groups per rule - the rule matches if cargo belongs to ANY of the selected groups (OR logic).
3. **Set Priorities**: Higher priority = checked first (use 10, 20, 30... for clarity)
4. **Effective Dates**: Use `effective_from` and `effective_to` for rule versioning
5. **Exclusive Groups**: Use for mutually exclusive surcharges (e.g., overwidth methods)
6. **Test with Simulator**: Always test new rules in the simulator before deploying
7. **Document in Internal Comments**: Add notes in carrier's internal comments field

## Troubleshooting

### Rule Not Applying

1. Check `is_active` is `true`
2. Check `effective_from` and `effective_to` dates
3. Check specificity score (more specific rules win)
4. Check priority (higher wins in ties)

### Surcharge Not Calculating

1. Verify surcharge rule matches cargo (category, port, vessel)
2. Check calculation parameters are correct
3. Verify trigger conditions (e.g., width > trigger)
4. Check exclusive group conflicts

### Article Not Adding

1. Verify article mapping exists for event_code
2. Check article mapping is active
3. Verify article exists in Robaws cache
4. Check quantity calculation (may be 0)

### LM Calculation Wrong

1. Check transform rules are active
2. Verify trigger width is correct
3. Check divisor is correct (usually 250)
4. Verify dimensions are in cm

## API Reference

### Services

#### ChargeableMeasureService

```php
$service->calculateBaseLm(float $lengthCm, float $widthCm): float
$service->computeChargeableLm(
    float $lengthCm,
    float $widthCm,
    ?int $carrierId,
    ?int $portId = null,
    ?string $vehicleCategory = null,
    ?string $vesselName = null,
    ?string $vesselClass = null
): ChargeableMeasureDTO
```

#### CarrierRuleEngine

```php
$engine->processCargo(CargoInputDTO $input): CarrierRuleResultDTO
```

#### CarrierRuleIntegrationService

```php
$service->processCommodityItem(QuotationCommodityItem $item): void
```

## Testing

Run unit tests:

```bash
php artisan test tests/Unit/Services/CarrierRules
```

Test files:
- `ChargeableMeasureServiceTest.php`
- `CarrierRuleResolverTest.php`
- `CarrierSurchargeCalculatorTest.php`
- `CarrierRuleEngineTest.php`

## Support

For questions or issues, contact the development team or refer to the codebase:
- Services: `app/Services/CarrierRules/`
- Models: `app/Models/Carrier*.php`
- UI: `app/Filament/Resources/CarrierRuleResource.php`

