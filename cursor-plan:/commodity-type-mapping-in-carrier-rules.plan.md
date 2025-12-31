# Commodity Type Mapping in Carrier Rules

## Problem
Currently, the commodity type mapping (e.g., truckhead → ['TRUCKHEAD', 'HH', 'LM CARGO', 'BIG VAN', 'CAR', 'SMALL VAN']) is hardcoded in `RobawsArticleCache::scopeForQuotationContext()`. This prevents carrier-specific and route-specific mappings.

**Audit Findings:**

1. **Article Commodity Types in Database** (9 types, case-sensitive):
   - Big Van (47 articles)
   - Break Bulk (2 articles)
   - Bus (16 articles)
   - Car (115 articles)
   - Container (35 articles)
   - LM Cargo (48 articles)
   - SUV (13 articles)
   - Small Van (100 articles)
   - Truck (228 articles)
   - **Note**: "TRUCKHEAD" and "HH" do NOT exist as article commodity types

2. **Current Hardcoded Mapping Issues**:
   - Maps to "TRUCKHEAD" and "HH" which don't exist in articles
   - Should only use existing types: "LM Cargo", "Big Van", "Car", "Small Van", "Truck", "Bus"
   - Case-insensitive matching works (UPPER(TRIM())), so "LM CARGO" matches "LM Cargo"

3. **Vehicle Category Mapping**:
   - 22 vehicle categories exist in system
   - Only 9 are mapped in `getVehicleCategoryMapping()`: car, suv, small_van, big_van, truck, truckhead, trailer, bus, motorcycle
   - 13 unmapped categories return NULL: truck_chassis, tipper_truck, platform_truck, box_truck, vacuum_truck, refuse_truck, concrete_mixer, tank_truck, trailer_stack, tank_trailer, truck_trailer_combination, loaded_truck_trailer, high_and_heavy

4. **Route-Specific Variations**:
   - Dakar (DKR): Has Big Van, Car, Small Van (NO LM Cargo)
   - Abidjan (ABJ): Has Big Van, Car, LM Cargo
   - Different routes may need different mappings

5. **Carrier-Specific**:
   - Currently Grimaldi and Sallaum have same article types
   - Could differ in future or for different routes

## Goal
Make commodity type mapping configurable per carrier (and optionally per route/port) through the Carrier Rules UI, allowing different carriers to have different mappings for how vehicle categories map to article commodity types.

## Implementation Plan

### Phase 1: Database Schema

**New Table: `carrier_commodity_type_mappings`**
- `id` (primary key)
- `carrier_id` (FK to shipping_carriers, cascade delete)
- `port_id` (nullable FK to ports, cascade delete) - for route-specific mappings
- `port_ids` (JSONB/JSON array, nullable) - for multiple ports
- `port_group_ids` (JSONB/JSON array, nullable) - for port groups
- `vehicle_category` (string, nullable) - specific vehicle category (e.g., 'truckhead')
- `category_group_ids` (JSONB/JSON array, nullable) - category groups (e.g., LM_CARGO)
- `mapped_article_types` (JSONB/JSON array, required) - array of article commodity types to match (e.g., ['LM Cargo', 'Big Van', 'Car', 'Small Van', 'Truck', 'Bus'])
  - Note: Use exact case as stored in database (case-insensitive matching is handled in query)
  - Should only include types that actually exist in articles
- `priority` (integer, default 0) - for rule precedence
- `effective_from` (date, nullable)
- `effective_to` (date, nullable)
- `is_active` (boolean, default true)
- `sort_order` (integer, default 0)
- `timestamps`

**Indexes:**
- `(carrier_id, is_active, priority desc)`
- `(carrier_id, port_id, vehicle_category, priority desc)`
- GIN index on `port_ids`, `port_group_ids`, `category_group_ids`, `mapped_article_types`

**Migration:** `2025_12_30_XXXXXX_create_carrier_commodity_type_mappings_table.php`

### Phase 2: Model

**New Model: `app/Models/CarrierCommodityTypeMapping.php`**
- Use `HasMultiScopeMatches` trait
- Fillable: carrier_id, port_id, port_ids, port_group_ids, vehicle_category, category_group_ids, mapped_article_types, priority, effective_from, effective_to, is_active, sort_order
- Casts: port_ids, port_group_ids, category_group_ids, mapped_article_types → array; effective_from, effective_to → date; is_active → boolean
- Relationships: carrier(), port()
- Scope: `scopeActive()` (is_active + effective dates)
- Normalize empty arrays to NULL in `booted()` event

**Update `ShippingCarrier` model:**
- Add `commodityTypeMappings()` relationship

### Phase 3: Resolver Service

**Update `app/Services/CarrierRules/CarrierRuleResolver.php`:**
- Add method: `resolveCommodityTypeMappings(int $carrierId, ?int $portId, ?string $vehicleCategory, ?int $categoryGroupId): Collection`
  - Query `CarrierCommodityTypeMapping` with carrier, port, vehicle category, and category group filters
  - Use `HasMultiScopeMatches` trait for port/port group matching
  - Use `selectMostSpecific()` for rule precedence
  - Return collection of matching mappings

### Phase 4: Update Article Selection Logic

**Update `app/Models/RobawsArticleCache.php::scopeForQuotationContext()`:**
- After extracting commodity types from quotation, check if carrier has commodity type mappings
- If mappings exist:
  - Use `CarrierRuleResolver::resolveCommodityTypeMappings()` to get applicable mappings
  - For each normalized commodity type (e.g., "TRUCKHEAD"), look up the mapping
  - If mapping found, use `mapped_article_types` from the rule (convert to uppercase for matching)
  - If no mapping found, fall back to current hardcoded expansion (but remove non-existent types: 'TRUCKHEAD', 'HH')
- If no mappings exist for carrier, use current hardcoded expansion (backward compatibility, but cleaned up)
- Handle unmapped vehicle categories: If category doesn't map to article type, check category groups and look for mappings by category_group_ids

**Update hardcoded fallback:**
- Remove 'TRUCKHEAD' and 'HH' from expansion (they don't exist in articles)
- Use only existing types: ['LM Cargo', 'Big Van', 'Car', 'Small Van', 'Truck', 'Bus']

**Logic flow:**
1. Normalize commodity type from quotation item (e.g., "truckhead" → "Truckhead" → "TRUCKHEAD")
2. Check if carrier has commodity type mappings for this port/vehicle category
3. If mapping found: use `mapped_article_types` from the rule (convert to uppercase for matching)
4. If no mapping: use hardcoded match statement (current behavior, but remove non-existent types)
5. Apply filter: `UPPER(TRIM(commodity_type)) IN (mapped_types_uppercase)` OR `commodity_type IS NULL`
6. Handle unmapped vehicle categories: If `normalizeCommodityType()` returns NULL, check if category belongs to a category group, then look for mappings by category_group_ids

### Phase 5: UI (Filament)

**Update `app/Filament/Resources/CarrierRuleResource.php`:**
- Add new tab: "Commodity Type Mappings"
- Repeater for `commodityTypeMappings` relationship
- Fields:
  - `port_ids` (Select multiple) - optional, for route-specific mappings
  - `port_group_ids` (Select multiple) - optional, for port group mappings
  - `vehicle_category` (Select) - optional, specific vehicle category
  - `category_group_ids` (Select multiple) - optional, category groups
  - `mapped_article_types` (TagsInput or Repeater) - required, list of article commodity types to match
  - `priority` (TextInput numeric)
  - `effective_from` (DatePicker)
  - `effective_to` (DatePicker)
  - `is_active` (Toggle)
- Repeater settings: reorderable, collapsible, collapsed by default
- Helper text: "Map vehicle categories to article commodity types. When a quotation has the specified vehicle category, articles with any of the mapped commodity types will be shown."

**Example UI entry:**
- Vehicle Category: `truckhead`
- Mapped Article Types: `LM Cargo`, `Big Van`, `Car`, `Small Van`, `Truck`, `Bus`
- Port Group: `Grimaldi_WAF` (optional - for route-specific mapping)
- Note: For Dakar route (no LM Cargo articles), could create route-specific mapping: `Big Van`, `Car`, `Small Van` only

### Phase 6: Seeder/Data Migration

**Update `database/seeders/GrimaldiWestAfricaRulesSeeder.php`:**
- Add commodity type mappings for Grimaldi:
  - Global mappings (all routes):
    - `truckhead` → ['LM Cargo', 'Big Van', 'Car', 'Small Van', 'Truck', 'Bus']
    - `truck` → ['LM Cargo', 'Big Van', 'Car', 'Small Van', 'Truck', 'Bus']
    - `trailer` → ['LM Cargo', 'Big Van', 'Car', 'Small Van', 'Truck', 'Bus']
    - `bus` → ['LM Cargo', 'Big Van', 'Car', 'Small Van', 'Truck', 'Bus']
  - Route-specific mappings (optional):
    - `truckhead` for Dakar (DKR) → ['Big Van', 'Car', 'Small Van'] (no LM Cargo available)
    - `truckhead` for Abidjan (ABJ) → ['LM Cargo', 'Big Van', 'Car', 'Small Van'] (LM Cargo available)
- Note: Remove non-existent types ('TRUCKHEAD', 'HH') from mappings

### Phase 7: Documentation

**Update `docs/carrier-rules.md`:**
- Add section on "Commodity Type Mappings"
- Explain how mappings work
- Provide examples
- Document fallback behavior

## Benefits

1. **Flexibility**: Different carriers can have different mappings
2. **Route-specific**: Mappings can vary by port/route
3. **Maintainability**: No code changes needed to update mappings
4. **Backward compatibility**: Falls back to hardcoded mappings if no rules exist

## Testing

1. Create mapping for Grimaldi: truckhead → ['LM Cargo', 'Big Van', 'Car', 'Small Van', 'Truck', 'Bus']
2. Test quotation QR-2025-0175 with truckhead - should find articles (Big Van, Car, Small Van for Dakar)
3. Test with different carrier (no mappings) - should use hardcoded fallback
4. Test port-specific mapping - create Dakar-specific mapping without 'LM Cargo', verify only Big Van/Car/Small Van shown
5. Test priority/precedence - more specific rules should win
6. Test unmapped vehicle categories (truck_chassis, etc.) - should handle gracefully (return NULL or use category group mapping)

## Additional Considerations from Audit

1. **Unmapped Vehicle Categories**: 13 categories are not mapped (truck_chassis, tipper_truck, etc.)
   - These return NULL from `normalizeCommodityType()`
   - Consider: Should these map to category groups? Or should we add mappings for them?
   - Solution: Allow category_group_ids in mapping rules to handle unmapped categories

2. **Case Sensitivity**: Article types use mixed case ("LM Cargo", "Big Van"), but matching is case-insensitive
   - Store mappings with exact case as in database for clarity
   - Query uses `UPPER(TRIM())` for matching, so case doesn't matter

3. **Non-existent Types**: Current hardcoded mapping includes "TRUCKHEAD" and "HH" which don't exist
   - Remove these from default mappings
   - Only use types that actually exist in articles

## Files to Create/Modify

**New Files:**
- `database/migrations/2025_12_30_XXXXXX_create_carrier_commodity_type_mappings_table.php`
- `app/Models/CarrierCommodityTypeMapping.php`

**Modified Files:**
- `app/Models/ShippingCarrier.php` - add relationship
- `app/Services/CarrierRules/CarrierRuleResolver.php` - add resolve method
- `app/Models/RobawsArticleCache.php` - update scopeForQuotationContext to use mappings
- `app/Filament/Resources/CarrierRuleResource.php` - add new tab
- `app/Filament/Resources/CarrierRuleResource/Pages/EditCarrierRule.php` - handle sort_order
- `database/seeders/GrimaldiWestAfricaRulesSeeder.php` - add mappings
- `docs/carrier-rules.md` - add documentation

