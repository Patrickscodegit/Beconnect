# Customer Type & Role Field Removal

## Summary
Removed `customer_type` and `customer_role` fields from public and customer quotation forms. These fields are now exclusively managed by the Belgaco team in the Filament admin panel.

## Business Logic Change

### Before
- Public prospects and customers had to select their customer type and role when requesting quotations
- Fields were required in both public and customer portals
- Validation enforced specific values

### After
- Public/customer forms no longer collect `customer_type` or `customer_role`
- Belgaco team assigns these values in Filament admin after quotation intake
- For existing Robaws customers, values can be fetched from Robaws API
- Fields remain in database and Filament for admin use only

## Files Modified

### 1. Public Quotation Form (Prospect Portal)

**resources/views/public/quotations/create.blade.php**
- ✅ Removed customer_type select field
- ✅ Removed customer_role select field

**app/Http/Controllers/ProspectQuotationController.php**
- ✅ Removed `$customerRoles` and `$customerTypes` config loading
- ✅ Removed variables from view compact
- ✅ Removed validation rules for both fields
- ✅ Set default values: `'customer_type' => 'GENERAL'`, `'customer_role' => 'CONSIGNEE'`

### 2. Customer Quotation Form (Customer Portal)

**resources/views/customer/quotations/create.blade.php**
- ✅ Removed customer_type select field
- ✅ Removed customer_role select field

**app/Http/Controllers/CustomerQuotationController.php**
- ✅ Removed `$customerRoles` and `$customerTypes` config loading
- ✅ Removed variables from view compact
- ✅ Removed validation rules for both fields
- ✅ Set default values: `'customer_type' => 'GENERAL'`, `'customer_role' => 'CONSIGNEE'`

### 3. Filament Admin (Unchanged)

**app/Filament/Resources/QuotationRequestResource.php**
- ✅ **NO CHANGES** - Belgaco team still needs these fields
- Fields remain editable for admin users
- Used for pricing calculations and article selection

## Database Impact

**No migration needed** - Columns remain in `quotation_requests` table:
- `customer_type` (nullable) - defaults to 'GENERAL' for new requests
- `customer_role` (nullable) - defaults to 'CONSIGNEE' for new requests

## New Workflow

1. **Quotation Submission**
   - Customer/prospect submits quotation request
   - No customer type/role selection required
   - System stores with defaults: `customer_type='GENERAL'`, `customer_role='CONSIGNEE'`

2. **Admin Review (Filament)**
   - Belgaco team reviews quotation in admin panel
   - Team assigns correct `customer_type` and `customer_role`
   - Options:
     - Manually select from dropdowns
     - Fetch from Robaws API for existing customers

3. **Processing**
   - Pricing calculations use assigned values
   - Article selection filters by customer type
   - Quotation generation includes role-specific content

## Configuration Values

From `config/quotation.php`:

**Customer Types:**
- FORWARDERS
- GENERAL
- CIB
- PRIVATE

**Customer Roles:**
- RORO
- POV
- CONSIGNEE
- FORWARDER

## Testing

### Public Portal Test
1. Visit `/public/quotations/create`
2. Verify customer_type and customer_role fields are NOT visible
3. Submit quotation
4. Verify submission succeeds
5. Check database: should have `customer_type='GENERAL'`, `customer_role='CONSIGNEE'`

### Customer Portal Test
1. Login and visit `/customer/quotations/create`
2. Verify customer_type and customer_role fields are NOT visible
3. Submit quotation
4. Verify submission succeeds
5. Check database: should have `customer_type='GENERAL'`, `customer_role='CONSIGNEE'`

### Filament Admin Test
1. Login to `/admin`
2. Open quotation request
3. Verify customer_type and customer_role fields ARE visible and editable
4. Change values
5. Save and verify changes persist

## Benefits

1. **Better UX**: Simplified form for customers/prospects
2. **Data Quality**: Belgaco team ensures accurate classification
3. **Flexibility**: Can adjust classification based on business knowledge
4. **Integration**: Ready for Robaws API integration to fetch existing customer data
5. **Control**: Centralized management of customer classification

## Related Files

- `config/quotation.php` - Customer types and roles configuration
- `database/migrations/2025_10_11_072407_create_quotation_system_tables.php` - Table structure
- `app/Filament/Components/ArticleSelector.php` - Uses customer_type for filtering
- `app/Filament/Components/PriceCalculator.php` - Uses customer_role for pricing

## Git Commit

```
git commit -m "Remove customer_type and customer_role from public/customer quotation forms

Business Logic Changes:
- Customer type and role are now assigned by Belgaco team in Filament admin
- Removed fields from public quotation form (prospect portal)
- Removed fields from customer quotation form (customer portal)
- Default values set: customer_type='GENERAL', customer_role='CONSIGNEE'

Files Modified:
- resources/views/public/quotations/create.blade.php: Removed 2 form fields
- app/Http/Controllers/ProspectQuotationController.php: Removed validation, set defaults
- resources/views/customer/quotations/create.blade.php: Removed 2 form fields
- app/Http/Controllers/CustomerQuotationController.php: Removed validation, set defaults

Workflow:
1. Customer/prospect submits quotation (no type/role selection)
2. Stored with defaults
3. Belgaco team assigns correct values in Filament admin
4. Can fetch from Robaws API for existing customers

Note: Filament admin form unchanged - team still can edit these fields"
```

## Date
October 14, 2025

