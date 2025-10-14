# Service Type Dropdown Update

## Summary
Updated the service type dropdown to include 17 comprehensive options covering freight services and additional service types.

## Complete Service Type List (17 Options)

### Freight Services (13)

1. **RORO Export** (`RORO_EXPORT`)
   - Direction: Export
   - Unit: per car
   - Requires Schedule: Yes

2. **RORO Import** (`RORO_IMPORT`)
   - Direction: Import
   - Unit: per car
   - Requires Schedule: Yes

3. **FCL Export** (`FCL_EXPORT`)
   - Direction: Export
   - Unit: per container
   - Quantity Tiers: 1, 2, 3, 4 vehicles
   - Requires Schedule: No

4. **FCL Import** (`FCL_IMPORT`)
   - Direction: Import
   - Unit: per container
   - Quantity Tiers: 1, 2, 3, 4 vehicles
   - Requires Schedule: No

5. **FCL Export Consol** (`FCL_EXPORT_CONSOL`)
   - Direction: Export
   - Unit: per car
   - Quantity Tiers: 2-pack or 3-pack
   - Requires Schedule: Yes
   - Has Formula Pricing: Yes

6. **FCL Import Consol** (`FCL_IMPORT_CONSOL`) ⭐ NEW
   - Direction: Import
   - Unit: per car
   - Quantity Tiers: 2-pack or 3-pack
   - Requires Schedule: Yes
   - Has Formula Pricing: Yes

7. **LCL Export** (`LCL_EXPORT`)
   - Direction: Export
   - Unit: per handling
   - Requires Schedule: No

8. **LCL Import** (`LCL_IMPORT`)
   - Direction: Import
   - Unit: per handling
   - Requires Schedule: No

9. **BB Export** (`BB_EXPORT`)
   - Direction: Export
   - Unit: per slot
   - Requires Schedule: Yes

10. **BB Import** (`BB_IMPORT`)
    - Direction: Import
    - Unit: per slot
    - Requires Schedule: Yes

11. **Airfreight Export** (`AIRFREIGHT_EXPORT`) 🔄 RENAMED
    - Previously: `AIR_EXPORT`
    - Direction: Export
    - Unit: per kg
    - Requires Schedule: No

12. **Airfreight Import** (`AIRFREIGHT_IMPORT`) 🔄 RENAMED
    - Previously: `AIR_IMPORT`
    - Direction: Import
    - Unit: per kg
    - Requires Schedule: No

13. **Crosstrade** (`CROSSTRADE`) 🔄 RENAMED
    - Previously: `CROSS_TRADE`
    - Direction: Cross Trade
    - Unit: per shipment
    - Requires Schedule: Yes

### Additional Services (4)

14. **Road Transport** (`ROAD_TRANSPORT`) ⭐ NEW
    - Direction: Both
    - Unit: per transport
    - Requires Schedule: No

15. **Customs** (`CUSTOMS`) ⭐ NEW
    - Direction: Both
    - Unit: per clearance
    - Requires Schedule: No

16. **Port Forwarding** (`PORT_FORWARDING`) ⭐ NEW
    - Direction: Both
    - Unit: per service
    - Requires Schedule: No

17. **Other** (`OTHER`) ⭐ NEW
    - Direction: Both
    - Unit: per service
    - Requires Schedule: No

## Changes Made

### File Modified
- `config/quotation.php` - Lines 108-230

### Key Updates

1. **Added New Service Types:**
   - `FCL_IMPORT_CONSOL` - Import consolidation service
   - `ROAD_TRANSPORT` - Road transportation service
   - `CUSTOMS` - Customs clearance service
   - `PORT_FORWARDING` - Port forwarding service
   - `OTHER` - Catch-all for other services

2. **Renamed Service Types:**
   - `AIR_EXPORT` → `AIRFREIGHT_EXPORT`
   - `AIR_IMPORT` → `AIRFREIGHT_IMPORT`
   - `FCL_CONSOL_EXPORT` → `FCL_EXPORT_CONSOL`
   - `CROSS_TRADE` → `CROSSTRADE`

3. **Reordered:**
   - Export options before Import for each service type
   - Freight services grouped first
   - Additional services grouped at the end

## Display Order in Dropdown

The dropdown will show options in this exact order:

1. RORO Export
2. RORO Import
3. FCL Export
4. FCL Import
5. FCL Export Consol
6. FCL Import Consol
7. LCL Export
8. LCL Import
9. BB Export
10. BB Import
11. Airfreight Export
12. Airfreight Import
13. Crosstrade
14. Road Transport
15. Customs
16. Port Forwarding
17. Other

## Impact Analysis

### ✅ No Breaking Changes
- Forms are already dynamic (read from config)
- Validation automatically uses config keys
- Database stores as string (no migration needed)
- Existing quotations continue to work

### Affected Areas

1. **Quotation Forms**
   - Public quotation form (`/public/quotations/create`)
   - Customer quotation form (`/customer/quotations/create`)
   - Both automatically show all 17 options

2. **Filament Admin**
   - QuotationRequestResource form
   - Displays all 17 service types
   - No code changes needed

3. **Validation**
   - Automatically validates against new config keys
   - Controllers already use `config('quotation.service_types')` for validation

4. **Article Filtering**
   - May need Robaws API mapping updates
   - New service types can be mapped to article categories

## Backward Compatibility

### Old Service Type Keys
Existing quotations with old keys will continue to work:
- `AIR_EXPORT` → Still stored in database, displays as stored
- `AIR_IMPORT` → Still stored in database, displays as stored
- `FCL_CONSOL_EXPORT` → Still stored in database, displays as stored
- `CROSS_TRADE` → Still stored in database, displays as stored

### Migration Path (Optional)
If you want to update old quotations to new keys, run:
```sql
UPDATE quotation_requests SET service_type = 'AIRFREIGHT_EXPORT' WHERE service_type = 'AIR_EXPORT';
UPDATE quotation_requests SET service_type = 'AIRFREIGHT_IMPORT' WHERE service_type = 'AIR_IMPORT';
UPDATE quotation_requests SET service_type = 'FCL_EXPORT_CONSOL' WHERE service_type = 'FCL_CONSOL_EXPORT';
UPDATE quotation_requests SET service_type = 'CROSSTRADE' WHERE service_type = 'CROSS_TRADE';
```

## Testing Checklist

- [x] ✅ Config updated with 17 service types
- [x] ✅ Cache cleared (config + application)
- [x] ✅ Verified all 17 options load correctly
- [x] ✅ No linter errors
- [x] ✅ Changes committed to git

### Manual Testing Required

- [ ] Visit `/public/quotations/create` - verify dropdown shows all 17 options
- [ ] Visit `/customer/quotations/create` - verify dropdown shows all 17 options
- [ ] Submit quotation with new service type (e.g., CUSTOMS)
- [ ] Verify validation accepts new service types
- [ ] Check Filament admin `/admin/quotation-requests` - verify dropdown works
- [ ] Test article selector with new service types
- [ ] Verify existing quotations still display correctly

## Configuration Location

**File:** `config/quotation.php`
**Section:** `service_types` (lines 108-230)

Each service type has:
```php
'SERVICE_TYPE_KEY' => [
    'name' => 'Display Name',
    'direction' => 'EXPORT|IMPORT|BOTH|CROSS_TRADE',
    'unit' => 'per car|per container|per service|etc',
    'requires_schedule' => true|false,
    // Optional fields:
    'quantity_tiers' => [1, 2, 3, 4],
    'has_formula_pricing' => true,
],
```

## Git Commit

```
git commit -m "Update service types dropdown with 17 options

Service Types Updated:

Freight Services (13):
- RORO Export/Import
- FCL Export/Import
- FCL Export Consol/Import Consol (added FCL_IMPORT_CONSOL)
- LCL Export/Import
- BB Export/Import
- Airfreight Export/Import (renamed from AIR_*)
- Crosstrade (renamed from CROSS_TRADE)

Additional Services (4):
- Road Transport (new)
- Customs (new)
- Port Forwarding (new)
- Other (new)

Changes:
- config/quotation.php: Updated service_types array
- Renamed AIR_* to AIRFREIGHT_*
- Renamed FCL_CONSOL_EXPORT to FCL_EXPORT_CONSOL
- Added FCL_IMPORT_CONSOL
- Renamed CROSS_TRADE to CROSSTRADE
- Added 4 new service types for additional services
- Reordered to match dropdown requirement

Total: 17 service type options
Forms will automatically show all options (already dynamic)"
```

## Date
October 14, 2025

