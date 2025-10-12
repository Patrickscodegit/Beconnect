# 🚀 Quick Testing Reference

## Start Testing Now!

### 1. Start Your Server (if not running)
```bash
php artisan serve
```

### 2. Access Admin Panel
**URL:** http://localhost:8000/admin

---

## 📍 Direct Links

### Quotation System Resources

1. **Quotations**
   - List: http://localhost:8000/admin/quotation-requests
   - Create: http://localhost:8000/admin/quotation-requests/create

2. **Article Cache**
   - List: http://localhost:8000/admin/robaws-articles
   - **Test Sync:** Click "Sync from Robaws" button in header

3. **Offer Templates**
   - List: http://localhost:8000/admin/offer-templates
   - Create: http://localhost:8000/admin/offer-templates/create

4. **Sync Logs**
   - List: http://localhost:8000/admin/robaws-sync-logs

---

## ⚡ Quick Test Sequence (10 minutes)

### Test 1: Create a Quotation (3 min)
1. Go to Quotations → New
2. Fill in:
   - Name: `Test Customer`
   - Email: `test@example.com`
   - Service: `RORO_EXPORT`
   - Route: `Antwerp → Lagos`
3. Save & View
4. ✅ Works? Continue...

### Test 2: Sync Articles (2 min)
1. Go to Article Cache
2. Click **"Sync from Robaws"** button
3. Confirm
4. Wait 30 seconds
5. ✅ See success notification with count

### Test 3: Browse Articles (2 min)
1. View article list (should show ~276)
2. Click on an article
3. Check parent-child relationships section
4. ✅ Data displays correctly

### Test 4: View Templates (1 min)
1. Go to Offer Templates
2. Should see 8 templates
3. Click one to view
4. ✅ Templates load

### Test 5: Check Sync Logs (2 min)
1. Go to Sync Logs
2. See your recent sync
3. Click to view details
4. ✅ Duration and count displayed

---

## 🎯 What to Look For

### ✅ Success Indicators
- Navigation shows "Quotation System" group
- All 4 resources accessible
- No errors in browser console (F12)
- Forms save successfully
- Lists display data
- Filters work
- Tabs show counts

### ❌ Red Flags
- 404 errors
- Blank pages
- PHP errors
- "Class not found" errors
- Sync fails
- Forms don't submit

---

## 🐛 Quick Troubleshooting

### Issue: "Quotation System" not in navigation
**Fix:**
```bash
php artisan cache:clear
php artisan filament:cache-components
```

### Issue: No articles showing
**Check:**
```bash
php artisan tinker
>>> App\Models\RobawsArticleCache::count()
```
Should return ~276

### Issue: Sync fails
**Check:**
```bash
cat .env | grep QUOTATION_ENABLED
```
Should show: `QUOTATION_ENABLED=true`

### Issue: Templates not showing
**Run seeder:**
```bash
php artisan db:seed --class=OfferTemplateSeeder
```

---

## 📊 Expected Counts

After initial testing:
- **Quotations:** 1-2 (your test quotations)
- **Articles:** 276 (from Phase 6 sync)
- **Templates:** 8 (from seeder)
- **Sync Logs:** 1-2 entries

---

## 🎉 Testing Complete When...

- [ ] Created at least 1 quotation
- [ ] Synced articles successfully
- [ ] Viewed article details
- [ ] Browsed templates
- [ ] Checked sync logs
- [ ] No critical errors
- [ ] All navigation works

**Total Time:** ~10-15 minutes

---

## 📝 Report Back

After testing, let me know:

1. **What worked?** ✅
2. **Any errors?** ❌
3. **UI improvements needed?** 💡
4. **Ready for custom components?** 🚀

---

## 💾 Logs to Check

If you encounter issues:

```bash
# Laravel logs
tail -f storage/logs/laravel.log

# Check last 50 lines
tail -50 storage/logs/laravel.log
```

Browser console: Press **F12** → Console tab

---

**Ready? Start testing now!** 🚀

Open: http://localhost:8000/admin

