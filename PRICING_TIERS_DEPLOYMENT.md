# PRICING TIERS DEPLOYMENT INSTRUCTIONS

**Date**: January 27, 2025  
**Feature**: Manageable 3-Tier Pricing System  
**Status**: ✅ Code Deployed (Auto-deploy via Forge)

---

## 🚀 DEPLOYMENT STATUS

✅ **Code Pushed to GitHub**: Commit `53bd331`  
✅ **Auto-Deployed by Forge**: Already on production  
⏳ **Migrations Pending**: Need to run manually

---

## 📋 MANUAL STEPS REQUIRED

### Step 1: SSH into Production

```bash
ssh forge@app.belgaco.be
cd /var/www/app.belgaco.be
```

### Step 2: Verify Code is Deployed

```bash
# Check latest commit
git log --oneline -1
# Should show: 53bd331 feat: Implement manageable 3-tier pricing system

# Verify migration files exist
ls -la database/migrations/ | grep pricing_tier
# Should show:
# 2025_01_27_140000_create_pricing_tiers_table.php
# 2025_01_27_140001_add_pricing_tier_to_quotation_requests.php
```

### Step 3: Run Migrations

```bash
# Run migrations (creates tables and seeds initial tiers)
php artisan migrate --force

# Expected output:
# Running: 2025_01_27_140000_create_pricing_tiers_table
# Migrated: 2025_01_27_140000_create_pricing_tiers_table
# Running: 2025_01_27_140001_add_pricing_tier_to_quotation_requests
# Migrated: 2025_01_27_140001_add_pricing_tier_to_quotation_requests
```

### Step 4: Clear All Caches

```bash
# Clear Laravel caches
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

# Restart queue workers (to load new models)
php artisan horizon:terminate
```

### Step 5: Verify Pricing Tiers Created

```bash
# Check that 3 tiers were seeded
php artisan tinker --execute="echo \App\Models\PricingTier::count() . ' pricing tiers created';"
# Expected: 3 pricing tiers created

# Show tier details
php artisan tinker --execute="
\App\Models\PricingTier::all()->each(function(\$tier) {
    echo 'Tier ' . \$tier->code . ': ' . \$tier->name . ' (' . \$tier->margin_percentage . '%)' . PHP_EOL;
});
"

# Expected output:
# Tier A: Best Price (-5%)
# Tier B: Medium Price (15%)
# Tier C: Expensive Price (25%)
```

---

## ✅ VERIFICATION CHECKLIST

After running migrations, verify the following:

### Admin Panel Access

1. **Login to admin panel**: https://app.belgaco.be/admin
2. **Navigate to**: Quotation System → Pricing Tiers
3. **Verify you see**:
   - 🟢 Tier A - Best Price (-5%)
   - 🟡 Tier B - Medium Price (+15%)
   - 🔴 Tier C - Expensive Price (+25%)

### Test Editing Margins

1. **Click Edit** on Tier A
2. **Change margin** from -5% to -7%
3. **Save**
4. **Verify** the change appears in the table
5. **Change it back** to -5% (optional)

### Test Creating a New Quotation

1. **Navigate to**: Quotations → Create New
2. **Fill in**:
   - Contact info
   - Customer Role: FORWARDER (WHO they are)
   - Pricing Tier: Select Tier A (WHAT pricing they get)
3. **Verify**:
   - Both fields work
   - Tier dropdown shows icons and percentages
   - Pricing calculator uses tier margin

### Test Existing Quotations

1. **Open an existing quotation**
2. **Verify**:
   - pricing_tier_id is NULL (that's correct - backward compatibility)
   - Pricing still works (falls back to customer_role)
   - No errors in pricing calculations

---

## 🎯 WHAT THIS DEPLOYMENT INCLUDES

### New Database Tables

1. **pricing_tiers**
   - 3 initial tiers (A, B, C)
   - Fully editable from admin panel
   - Supports negative margins (discounts)

2. **quotation_requests.pricing_tier_id**
   - New foreign key column
   - Nullable (backward compatible)
   - Indexed for performance

### New Admin Features

1. **Pricing Tiers Menu**
   - Edit margins without code deployment
   - Visual preview calculator
   - Color-coded badges
   - Active/inactive toggle

2. **Quotation Form Enhancement**
   - Customer Role: WHO the customer is
   - Pricing Tier: WHAT pricing they get
   - Maximum flexibility in pricing strategy

### Pricing Logic Updates

1. **QuotationRequest Model**
   - Uses pricing_tier if set
   - Falls back to customer_role if not
   - Backward compatible

2. **RobawsArticleCache Model**
   - New getPriceForTier() method
   - Supports discounts and markups
   - Formula pricing compatible

---

## 🔄 HOW TO USE THE NEW SYSTEM

### Changing Pricing Margins (30 Seconds)

1. Login to admin: https://app.belgaco.be/admin
2. Click **"Pricing Tiers"** in sidebar
3. Click **"Edit"** on any tier
4. Change **"Margin Percentage"** field
   - Negative % = discount (e.g., -5% = 5% off)
   - Positive % = markup (e.g., 15% = 15% extra)
5. Click **"Save"**
6. ✅ **Done!** New quotations use new margin immediately

### Creating Quotations with Tiers

1. Create or edit quotation
2. Set **Customer Role**: FORWARDER, CONSIGNEE, etc.
3. Set **Pricing Tier**: A (Best), B (Medium), or C (Expensive)
4. Articles automatically priced with tier margin
5. Example:
   - Article base price: €1,000
   - Tier A margin: -5%
   - Selling price: €950 (5% discount)

### Adding New Tiers

1. Navigate to **Pricing Tiers**
2. Click **"New Pricing Tier"**
3. Set:
   - Code: D
   - Name: VIP Pricing
   - Margin: -10% (10% discount for VIP customers)
   - Color: blue
   - Icon: 💎
4. Save
5. ✅ Tier D now available in all quotation forms

---

## 🚨 TROUBLESHOOTING

### Issue: "Pricing Tiers menu not showing"

**Solution**:
```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
# Hard refresh browser (Cmd+Shift+R)
```

### Issue: "Migration already ran" error

**Check if tables exist**:
```bash
php artisan tinker --execute="
echo (Schema::hasTable('pricing_tiers') ? '✅' : '❌') . ' pricing_tiers table' . PHP_EOL;
echo (Schema::hasColumn('quotation_requests', 'pricing_tier_id') ? '✅' : '❌') . ' pricing_tier_id column' . PHP_EOL;
"
```

### Issue: "No pricing tiers in dropdown"

**Verify tiers exist**:
```bash
php artisan tinker --execute="\App\Models\PricingTier::all();"
```

**If empty, re-seed**:
```bash
# Re-run the migration
php artisan migrate:refresh --path=database/migrations/2025_01_27_140000_create_pricing_tiers_table.php
```

### Issue: "Pricing calculations wrong"

**Clear cache**:
```bash
php artisan cache:clear
php artisan config:clear
```

**Verify tier margins**:
```bash
php artisan tinker --execute="
\App\Models\PricingTier::all()->each(function(\$t) {
    echo 'Tier ' . \$t->code . ': ' . \$t->margin_percentage . '%' . PHP_EOL;
});
"
```

---

## 📊 EXPECTED RESULTS

### Pricing Tiers Table

After migration, you should have:

| Code | Name | Margin | Type |
|------|------|--------|------|
| A | Best Price | -5% | 💚 DISCOUNT |
| B | Medium Price | +15% | 📈 MARKUP |
| C | Expensive Price | +25% | 📈 MARKUP |

### Quotations Table

The `quotation_requests` table now has:
- `customer_role` (existing): WHO the customer is
- `pricing_tier_id` (NEW): WHAT pricing they get

### Admin Panel

New menu item: **"Pricing Tiers"** in Quotation System group

---

## 🎉 BENEFITS

### Before (Old System)

**To change margin from 10% to 12%**:
1. Edit config/quotation.php
2. Test locally
3. Commit to git
4. Push to GitHub
5. Wait for auto-deploy
6. **Time: 30-60 minutes**

### After (New System)

**To change margin from 10% to 12%**:
1. Login to admin
2. Edit Tier A
3. Change 10% → 12%
4. Save
5. **Time: 30 seconds** ✅

### Advanced Use Cases

**Scenario 1: Seasonal Discount**
- Reduce Tier A from -5% to -10% for summer promotion
- Change in 30 seconds
- Revert in 30 seconds when promotion ends

**Scenario 2: Competitor Response**
- Competitor offers better pricing
- Adjust Tier B from 15% to 12%
- Immediate effect on new quotations

**Scenario 3: VIP Customer Program**
- Create Tier D with -15% margin
- Assign to select VIP customers
- Easy to manage and track

---

## 📝 NEXT STEPS

1. ✅ **Run migrations** (commands above)
2. ✅ **Verify in admin panel** (Pricing Tiers menu)
3. ✅ **Test editing margins** (change and save)
4. ✅ **Create test quotation** (verify tier pricing works)
5. ⏳ **Optional**: Migrate existing quotations to tiers (separate command)
6. ⏳ **Optional**: Train team on new system

---

**The system is deployed and ready to use after running the migrations!** 🚀

