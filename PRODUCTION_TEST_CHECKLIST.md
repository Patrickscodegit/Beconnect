# 🧪 Production Deployment Test Checklist

## Test Date: October 9, 2025
## Production URL: http://bconnect.64.226.120.45.nip.io/schedules

---

## ✅ Phase 1: Basic Site Accessibility

- [ ] Site loads without 500 error
- [ ] Login page renders correctly
- [ ] CSS and assets load properly

---

## ✅ Phase 2: Authentication

### Login Credentials:
- **Email:** `patrick@belgaco.be`
- **Password:** `password`

**Test Steps:**
1. [ ] Navigate to http://bconnect.64.226.120.45.nip.io/schedules
2. [ ] Enter email: patrick@belgaco.be
3. [ ] Enter password: password
4. [ ] Click "Log in"
5. [ ] Verify successful login and redirect to schedules page

---

## ✅ Phase 3: Schedule Search Functionality

### Test Route: Antwerp → Conakry

**Test Steps:**
1. [ ] POL dropdown shows ports in "City, Country (Code)" format
2. [ ] Select POL: "Antwerp, Belgium (ANR)"
3. [ ] POD dropdown shows ports in "City, Country (Code)" format
4. [ ] Select POD: "Conakry, Guinea (CKY)"
5. [ ] Click "Search Schedules" button
6. [ ] Schedules display successfully

---

## ✅ Phase 4: Schedule Display Features

**Verify Schedule Card Shows:**
- [ ] Vessel name with voyage number (e.g., "Ocean Breeze (25OB09)")
- [ ] Frequency display as "Bi-weekly service" (NOT "1.5x/month")
- [ ] Transit time in days
- [ ] Next sailing date in "Month Day, Year" format
- [ ] Departure Terminal: 332 (for Sallaum only)
- [ ] POL: "Antwerp, Belgium (ANR)"
- [ ] ETS: Date in readable format
- [ ] POD: "Conakry, Guinea (CKY)"
- [ ] ETA: Date in readable format

**Layout Check:**
- [ ] Fields are organized in 4-column grid
- [ ] POL and ETS are grouped together
- [ ] POD and ETA are positioned below POL/ETS
- [ ] Layout is balanced and readable

---

## ✅ Phase 5: Navigation Features

**Test Steps:**
1. [ ] Schedule counter shows (e.g., "1 of 3")
2. [ ] "Next" button is visible
3. [ ] Click "Next" button
4. [ ] Schedule changes to next vessel
5. [ ] Counter updates (e.g., "2 of 3")
6. [ ] Click "Previous" button
7. [ ] Returns to previous schedule
8. [ ] Navigation works smoothly

---

## ✅ Phase 6: Copy to Clipboard

**Test Steps:**
1. [ ] "Copy to Clipboard" button is visible
2. [ ] Click "Copy to Clipboard"
3. [ ] Success message appears
4. [ ] Paste clipboard content
5. [ ] Verify format matches:
   ```
   Vessel: [Vessel Name] ([Voyage])
   Frequency: [Professional Text]
   Transit Time: [X] days
   Next Sailing: [Date]
   Departure Terminal: 332
   POL: [City, Country (Code)]
   ETS: [Date]
   POD: [City, Country (Code)]
   ETA: [Date]
   ```

---

## ✅ Phase 7: Multiple Routes Testing

### Additional Routes to Test:

**Test Route 2: Antwerp → Dakar**
- [ ] Select POL: Antwerp (ANR)
- [ ] Select POD: Dakar (DKR)
- [ ] Schedules display correctly
- [ ] Frequency shows professional text

**Test Route 3: Zeebrugge → Cotonou**
- [ ] Select POL: Zeebrugge (ZEE)
- [ ] Select POD: Cotonou (COO)
- [ ] Schedules display correctly
- [ ] Multiple vessels show if available

---

## ✅ Phase 8: Frequency Display Verification

**Expected Frequency Formats:**
- [ ] "Weekly service" (for 4.0+ sailings/month)
- [ ] "2-3x/month" (for 2.5-3.9 sailings/month)
- [ ] "Bi-weekly service" (for 1.5-2.4 sailings/month)
- [ ] "Monthly service" (for 0.8-1.4 sailings/month)
- [ ] "~1x/month" (for 0.5-0.7 sailings/month)
- [ ] "Irregular service" (for <0.5 sailings/month)

**NO decimal frequencies should appear (e.g., no "1.5x/month")**

---

## ✅ Phase 9: Mobile Responsiveness

**Test on Mobile View:**
- [ ] Login page is mobile-friendly
- [ ] Schedule search form is usable
- [ ] Schedule cards are readable
- [ ] Navigation buttons work
- [ ] Layout adapts to smaller screens

---

## ✅ Phase 10: Error Handling

**Test Edge Cases:**
1. [ ] Search with no results shows appropriate message
2. [ ] Invalid login shows error
3. [ ] Empty POL/POD shows validation message
4. [ ] Browser back button works correctly

---

## 🎯 Test Results Summary

### Overall Status: ⬜ PASS / ⬜ FAIL

**Issues Found:**
```
[List any issues discovered during testing]
```

**Performance Notes:**
```
[Note page load times, responsiveness, etc.]
```

**Browser Tested:**
- [ ] Chrome
- [ ] Firefox
- [ ] Safari
- [ ] Mobile Browser

---

## 📸 Screenshots to Capture

1. [ ] Login page
2. [ ] Schedules page with search form
3. [ ] Schedule results for ANR → CKY
4. [ ] Schedule card detail view
5. [ ] Navigation in action
6. [ ] Mobile view

---

## ✅ Deployment Verification

**Check Production Logs:**
```bash
# SSH into production server
ssh forge@bconnect.64.226.120.45.nip.io

# Check latest logs
tail -f storage/logs/laravel.log

# Look for:
# - No migration errors
# - Successful schedule syncs
# - No 500 errors
```

**Database Verification:**
```bash
# Check schedules exist
php artisan tinker --execute="
echo 'Total schedules: ' . \App\Models\ShippingSchedule::count() . PHP_EOL;
echo 'ANR→CKY schedules: ' . \App\Models\ShippingSchedule::whereHas('polPort', fn(\$q) => \$q->where('code', 'ANR'))
    ->whereHas('podPort', fn(\$q) => \$q->where('code', 'CKY'))->count() . PHP_EOL;
"
```

---

## 🎊 Success Criteria

**Deployment is considered successful if:**
- ✅ All Phase 1-6 tests pass
- ✅ No migration errors in logs
- ✅ Schedules display with professional frequency text
- ✅ Navigation works smoothly
- ✅ No JavaScript errors in console
- ✅ Layout is balanced and readable

---

**Tested By:** _________________
**Date:** _________________
**Time:** _________________
**Result:** ⬜ PASS / ⬜ FAIL
