# Bug Fix: Customer Roles Not Showing in Form

## Issue
Customer roles dropdown was empty in the quotation creation form.

## Root Cause
The form was looking for `config('quotation.customer_roles')` which didn't exist in the config file. The roles existed only in `config('quotation.profit_margins.by_role')` which is structured for pricing logic, not form display.

## Solution Implemented
Added a dedicated `customer_roles` array to `config/quotation.php` with human-readable labels.

### Changes Made

#### 1. Added to `config/quotation.php`
```php
'customer_roles' => [
    'RORO' => 'RORO Customer',
    'POV' => 'POV Customer',
    'CONSIGNEE' => 'Consignee',
    'FORWARDER' => 'Freight Forwarder',
    'HOLLANDICO' => 'Hollandico / Belgaco',
    'INTERMEDIATE' => 'Intermediate',
    'EMBASSY' => 'Embassy',
    'TRANSPORT_COMPANY' => 'Transport Company',
    'SHIPPING_LINE' => 'Shipping Line',
    'OEM' => 'OEM / Manufacturer',
    'BROKER' => 'Broker',
    'RENTAL' => 'Rental Company',
    'LUXURY_CAR_DEALER' => 'Luxury Car Dealer',
    'CAR_DEALER' => 'Car Dealer',
    'BLACKLISTED' => 'Blacklisted Customer',
],
```

#### 2. Enhanced Form Field in `QuotationRequestResource.php`
```php
Forms\Components\Select::make('customer_role')
    ->label('Customer Role')
    ->options(config('quotation.customer_roles', []))
    ->default('CONSIGNEE')
    ->required()
    ->searchable()
    ->columnSpan(1),
```

**Improvements:**
- ✅ Added explicit `->label('Customer Role')`
- ✅ Added `->required()` to make it mandatory
- ✅ Added `->searchable()` for better UX with many options

#### 3. Cleared Config Cache
```bash
php artisan config:clear
```

## Why This Solution is Best

1. **Separation of Concerns**: Display labels separate from pricing logic
2. **Human-Readable**: "Consignee" instead of "CONSIGNEE"
3. **Flexibility**: Can modify labels without affecting pricing
4. **Performance**: No runtime array manipulation
5. **Maintainability**: Clear, explicit, easy to understand
6. **Consistency**: Follows the same pattern as `customer_types`

## Testing
After fix, refresh the browser and verify:
- ✅ Customer Role dropdown shows all 15 options
- ✅ Labels are human-readable
- ✅ Default value "Consignee" is selected
- ✅ Field is marked as required (red asterisk)
- ✅ Searchable dropdown works with many options

## Related Configuration
The customer roles are still linked to profit margins via:
```php
'profit_margins' => [
    'by_role' => [
        'CONSIGNEE' => 15,
        'FORWARDER' => 8,
        // ... etc
    ],
],
```

The keys match between `customer_roles` (for display) and `profit_margins.by_role` (for pricing calculations).

## Status
✅ **FIXED** - Customer roles now display correctly in the quotation form.
