# Phase 8: Manual Testing Guide

## 🚀 Getting Started

> **Testing Database**  
> PHPUnit now targets `database/testing.sqlite`. If migrations change, regenerate it with `php artisan migrate fresh --env=testing` before running the suite.

> **Pipeline Test Suite**  
> Integration-heavy ingestion specs are tagged `@group pipeline` and are skipped by default. Set `PIPELINE_TESTS=true` (for example `PIPELINE_TESTS=true php artisan test --group=pipeline`) when you need to exercise the full document/OCR/email pipeline.

### 1. Start Your Development Server

```bash
php artisan serve
```

Then navigate to: **http://localhost:8000/admin**

### 2. Login to Filament Admin

Use your admin credentials to access the Filament panel.

---

## 📋 Test Plan

### ✅ TEST 1: QuotationRequestResource

**URL:** `/admin/quotation-requests`

#### Step 1.1: List View
- [ ] Navigate to Quotations
- [ ] Verify the page loads without errors
- [ ] Check navigation badge (should show pending review count or be empty)
- [ ] Verify tabs are visible: All, Draft, Pending Review, Sent, Approved, Rejected
- [ ] Click each tab and verify they load
- [ ] Check if tab badges show counts (will be 0 if no data)

#### Step 1.2: Create Quotation
- [ ] Click "New Quotation" button
- [ ] Fill in **Customer Information**:
  - Customer Name: `John Doe`
  - Customer Email: `john@example.com`
  - Customer Phone: `+1234567890`
  - Customer Company: `Test Company BV`
  - Customer Reference: `TEST-001`
  - Customer Type: Select `GENERAL`
  - Customer Role: Select `CONSIGNEE`

- [ ] Fill in **Route & Service**:
  - Service Type: Select `RORO_EXPORT`
  - POR: `Antwerp`
  - POL: `Antwerp Port`
  - POD: `Lagos Port`
  - FDEST: `Lagos`
  - Commodity Type: Select `cars`
  - Cargo Description: `Test cargo - 2 vehicles`

- [ ] **Pricing & Status**:
  - Status: Leave as `draft`
  - Discount: `5`
  - VAT Rate: Leave default `21`
  - Valid Until: Pick a date 30 days from now

- [ ] **Templates** (optional):
  - Select an introduction template (if available)
  - Select an end template (if available)

- [ ] Click **Save**

**Expected Result:** Quotation created successfully, redirected to view page

#### Step 1.3: View Quotation
After creating, you should see:
- [ ] Customer Information section
- [ ] Route & Service section
- [ ] Articles section (will be empty for now)
- [ ] Pricing section (showing 0 amounts)
- [ ] Status & Dates section
- [ ] Templates section
- [ ] Internal Notes section (collapsed)

#### Step 1.4: Edit Quotation
- [ ] Click "Edit" button
- [ ] Change customer name to `Jane Doe`
- [ ] Add internal notes: `Test note`
- [ ] Click **Save**
- [ ] Verify changes are saved

#### Step 1.5: Duplicate Quotation
- [ ] From view page, click "Duplicate" action
- [ ] Verify new quotation created with "(Copy)" suffix
- [ ] Verify status is `draft`

#### Step 1.6: Test Filters
- [ ] Go back to list view
- [ ] Test **Status filter**: Select "Draft"
- [ ] Test **Service Type filter**: Select "RORO_EXPORT"
- [ ] Test **Date filter**: Pick date range
- [ ] Verify filtering works

#### Step 1.7: Test Search
- [ ] Use global search to find customer name
- [ ] Verify quotation appears in results

---

### ✅ TEST 2: RobawsArticleResource

**URL:** `/admin/robaws-articles`

#### Step 2.1: List View
- [ ] Navigate to Article Cache
- [ ] Verify **276 articles** are displayed (or current count)
- [ ] Check navigation badge (should show review count if any)
- [ ] Verify tabs: All, Parent, Surcharges, Review, Seafreight, Customs
- [ ] Check "All Articles" tab shows count badge (276)

#### Step 2.2: View Article Details
- [ ] Click on any article to view details
- [ ] Verify sections display:
  - Article Information
  - Classification
  - Quantity & Pricing
  - Parent-Child Relationships
  - Metadata

#### Step 2.3: Edit Article
- [ ] Click "Edit" button
- [ ] Try changing:
  - Unit price
  - Category
  - Service types (check some boxes)
  - Customer type
- [ ] Click **Save**
- [ ] Verify changes saved

#### Step 2.4: Test Sync Action
- [ ] Go back to list view
- [ ] Click **"Sync from Robaws"** button in header
- [ ] Verify confirmation modal appears
- [ ] Click "Start Sync"
- [ ] Wait for sync to complete (may take 30 seconds)
- [ ] Verify success notification appears
- [ ] Check article count updated if new articles found

**Expected Result:** Success notification showing count of synced articles

#### Step 2.5: Test Filters
- [ ] Test **Category filter**: Select "Seafreight"
- [ ] Test **Is Parent Article filter**: Select "Only parent articles"
- [ ] Test **Is Surcharge filter**: Select "Only surcharges"
- [ ] Verify filtering works

#### Step 2.6: Test Tabs
- [ ] Click **"Parent Articles"** tab
  - Should show articles where `is_parent_article = true`
- [ ] Click **"Surcharges"** tab
  - Should show articles where `is_surcharge = true`
- [ ] Click **"Needs Review"** tab
  - Should show articles with `requires_manual_review = true`

---

### ✅ TEST 3: OfferTemplateResource

**URL:** `/admin/offer-templates`

#### Step 3.1: List View
- [ ] Navigate to Offer Templates
- [ ] Verify **8 templates** are displayed (from seeding)
- [ ] Check columns: Name, Type, Language, Service Types, Active, Updated

#### Step 3.2: View Existing Template
- [ ] Click on "RORO Export - Introduction (EN)"
- [ ] View template content

#### Step 3.3: Create New Template
- [ ] Click "New Template"
- [ ] Fill in:
  - Name: `Test Introduction`
  - Type: `Introduction Text`
  - Language: `English`
  - Service Types: Check `RORO_EXPORT`
  - Active: Toggle ON
  - Content: 
    ```
    Dear {customerName},
    
    Thank you for your request for {serviceType} service.
    We are pleased to provide you with the following quotation.
    
    Route: {por} → {pol} → {pod} → {fdest}
    ```
- [ ] Click **Save**

**Expected Result:** Template created successfully

#### Step 3.4: Test Duplicate
- [ ] From list, click duplicate action on any template
- [ ] Verify new template created with "(Copy)" suffix
- [ ] Verify it's set to inactive by default

#### Step 3.5: Test Toggle Active
- [ ] From list view, toggle the "Active" column for a template
- [ ] Verify icon changes

#### Step 3.6: Test Filters
- [ ] Filter by **Type**: Select "Introduction"
- [ ] Filter by **Language**: Select "English"
- [ ] Filter by **Active Status**: Select "Active only"

---

### ✅ TEST 4: RobawsSyncLogResource

**URL:** `/admin/robaws-sync-logs`

#### Step 4.1: List View
- [ ] Navigate to Sync Logs
- [ ] Verify sync entries are displayed
- [ ] Check columns: Sync Type, Status, Items Synced, Started At, Duration, Error Message
- [ ] Verify logs from article sync you just ran appear

#### Step 4.2: View Sync Log Details
- [ ] Click on the most recent sync log
- [ ] Verify sections:
  - Sync Information (type, status, count)
  - Timeline (started, completed, duration)
  - Error Details (if any failures)

#### Step 4.3: Test Auto-Refresh
- [ ] Stay on the list page for 30+ seconds
- [ ] Verify page auto-refreshes (watch for brief reload)

#### Step 4.4: Test Tabs
- [ ] Click **"Successful"** tab
- [ ] Click **"Failed"** tab (if any)
- [ ] Click **"Articles"** tab
- [ ] Verify each tab shows relevant logs

#### Step 4.5: Test Filters
- [ ] Filter by **Sync Type**: Select "Articles"
- [ ] Filter by **Status**: Select "Success"
- [ ] Verify filtering works

---

## 🐛 Common Issues to Watch For

### Potential Issues

1. **Navigation Not Showing**
   - Check if "Quotation System" group appears in sidebar
   - Verify all 4 resources are listed

2. **Empty Lists**
   - Quotations will be empty until you create some
   - Articles should show 276 (or check database)
   - Templates should show 8 from seeding

3. **Sync Errors**
   - Check `.env` has correct Robaws credentials
   - Verify `QUOTATION_ENABLED=true`
   - Check `ROBAWS_ARTICLE_EXTRACTION_LIMIT` (currently 500)

4. **Form Validation Errors**
   - Customer name and email are required
   - Service type is required
   - Check error messages appear correctly

5. **Performance Issues**
   - Article list should load quickly (<2s for 276 articles)
   - Sync should complete in <30 seconds

---

## 📊 Expected Results Summary

### Counts After Testing

- **Quotations:** 2-3 (test quotations you created)
- **Articles:** 276 (unchanged unless you re-synced)
- **Templates:** 9 (8 seeded + 1 you created)
- **Sync Logs:** 2-3 (initial sync + your test sync)

### Navigation Badges

- **Quotations:** Shows count of `pending_review` status
- **Article Cache:** Shows count of `requires_manual_review = true`

### Performance Benchmarks

- List page load: <2 seconds
- Article sync: <30 seconds for 500 offers
- Form save: <1 second
- Auto-refresh: Every 30 seconds (sync logs only)

---

## 📝 Report Issues

As you test, note any issues you encounter:

### Issue Template

```
**Resource:** QuotationRequestResource (or other)
**Action:** Create/Edit/View/Delete/Filter
**Steps to Reproduce:**
1. 
2. 
3. 

**Expected:** 
**Actual:** 
**Error Message (if any):** 
**Screenshots:** (if helpful)
```

---

## ✅ Testing Checklist

### Quick Checklist

- [ ] All 4 resources accessible from navigation
- [ ] Can create quotation
- [ ] Can view/edit/duplicate quotation
- [ ] Can sync articles from Robaws
- [ ] Can view/edit article details
- [ ] Can create/edit templates
- [ ] Can view sync logs
- [ ] All filters work
- [ ] All tabs work
- [ ] Navigation badges accurate
- [ ] No console errors
- [ ] No PHP errors in logs

---

## 🚀 Next Steps After Testing

Once testing is complete:

1. **Report any bugs** you found
2. **Document any UX improvements** needed
3. **Confirm everything works** as expected
4. **Ready to proceed** to building custom components

---

## 💡 Tips

- **Clear cache** if you see old data: `php artisan cache:clear`
- **Check logs** if errors occur: `tail -f storage/logs/laravel.log`
- **Inspect browser console** for JavaScript errors (F12)
- **Use incognito mode** if you see caching issues

Good luck with testing! Let me know what you find! 🎉

