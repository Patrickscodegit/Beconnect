# Production Deployment Instructions

## Current Status
✅ All code fixes committed and pushed to main
✅ Local testing: 100% success rate
⏳ Production: Needs deployment and re-sync

## Step-by-Step Deployment

### 1. Deploy Latest Code to Production
```bash
# Via Forge or SSH to production
cd /path/to/production
git pull origin main
```

### 2. Run Migration (If Not Already Run)
```bash
php artisan migrate
```

### 3. Re-run "Sync All Metadata"
- Go to: https://app.belgaco.be/admin/robaws-articles
- Click: **"Sync All Metadata"** button (green with sparkles icon)
- Wait: ~10-30 seconds
- Result: All 1,576 articles will be updated with:
  - ✅ Parent items correctly identified (numberValue: 1 → TRUE)
  - ✅ Commodity types extracted (Big Van, Car, LM Cargo, etc.)
  - ✅ POD codes populated (ABJ, CKY, COO, etc.)
  - ✅ POL Terminal populated (ST 332, etc.)

### 4. Verify One Sallaum Article
Open any Sallaum article (e.g., Article #1164) and verify:
- ☑️ **Parent Item**: Should be checked
- 📦 **Commodity Type**: Should show "LM Cargo" or "Big Van" or "Car"
- 📍 **POD Code**: Should show "ABJ" or "CKY"
- 🚢 **Shipping Line**: Should show "SALLAUM LINES"
- 🏢 **POL Terminal**: Should show "ST 332"

### 5. Test Smart Article Selection
- Open a quotation
- Set POL: Antwerp (ANR)
- Set POD: Abidjan (ABJ)
- Set Commodity: Big Van
- Check if Sallaum articles appear in smart suggestions

## What Changed in Latest Deployment

### Commit e3dfcbc
- Integrated ArticleSyncEnhancementService into RobawsArticleProvider
- Now extracts commodity_type and pod_code during metadata sync

### Commit ab62cae
- Added Smart Article Selection fields to Filament UI
- Fields now visible in detail view and table

### Commit 41b739c
- Fixed parent item checkbox extraction (numberValue vs booleanValue)
- Added POL, POD, TYPE extraction from Robaws extraFields
- Created diagnostic tools for troubleshooting

## Why Production Is Different from Local

**Local**: Already synced with latest code → fields populated
**Production**: Synced with old code (Oct 23) → fields empty

**Solution**: Re-run sync with new code!

## Troubleshooting

### If Parent Items Still Empty After Sync
```bash
# Check Robaws API response
php artisan articles:diagnose-robaws 1164

# Manually mark Sallaum as parent (if needed)
php artisan articles:mark-sallaum-parent --dry-run
php artisan articles:mark-sallaum-parent
```

### If Fields Still Not Showing in UI
- Clear browser cache
- Hard refresh (Cmd+Shift+R)
- Check Filament cache: `php artisan filament:cache-components`

## Expected Results

After deployment and sync:
- **Parent Articles tab**: Should show ~50-100 articles (not 0)
- **Sallaum articles**: All marked as parent items
- **Commodity types**: Populated for all articles with clear types
- **POD codes**: Populated for all articles with destinations
- **Smart Article Selection**: Fully functional

---

**Status**: Ready for production deployment 🚀
**Estimated Time**: 5 minutes deployment + 30 seconds sync
