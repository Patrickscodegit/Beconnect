# Article Sync Operations - Complete Audit

## Summary of All Sync Buttons

### ✅ **RECOMMENDED: "Sync Extra Fields"** (Blue Button)
- **Command**: `php artisan robaws:sync-extra-fields`
- **What it does**: Fetches individual article details from Robaws API including ALL extraFields
- **API Calls**: 1,576 (one per article)
- **Duration**: 30-60 minutes
- **Runs in**: Background queue
- **Populates**:
  - ✅ PARENT ITEM (from checkbox)
  - ✅ SHIPPING LINE
  - ✅ SERVICE TYPE
  - ✅ POL TERMINAL
  - ✅ POL (extraFields)
  - ✅ POD (extraFields)
  - ✅ TYPE (extraFields)
  - ✅ commodity_type (via enhancement service)
  - ✅ pod_code (via enhancement service)
  - ✅ UPDATE DATE
  - ✅ VALIDITY DATE
  - ✅ ARTICLE INFO

**✅ USE THIS FOR INITIAL SETUP** - Gets complete data from Robaws

---

### ⚠️ "Sync All Metadata" (Green Sparkles)
- **Command**: `RobawsArticlesSyncService::syncAllMetadata()`
- **What it does**: Fast metadata extraction from article NAMES (no API)
- **API Calls**: 0
- **Duration**: 10-30 seconds
- **Runs**: Synchronously
- **Populates**:
  - ⚠️ shipping_line (parsed from name)
  - ⚠️ service_type (parsed from name)
  - ⚠️ pol_terminal (parsed from name)
  - ⚠️ pol_code (parsed from name)
  - ⚠️ pod_name (parsed from name)
  - ✅ commodity_type (via enhancement service)
  - ✅ pod_code (via enhancement service)
  - ❌ PARENT ITEM (NOT POPULATED - no API)
  - ❌ POL (from Robaws extraFields)
  - ❌ POD (from Robaws extraFields)
  - ❌ TYPE (from Robaws extraFields)

**⚠️ PROBLEM**: This doesn't get Robaws extraFields, only parses names!

---

### "Full Sync (All Articles)" (Orange Button)
- **Command**: `RobawsArticlesSyncService::sync()`
- **What it does**: Fetches all articles from Robaws API (paginated list)
- **API Calls**: ~50-100 (for pagination)
- **Duration**: 3-5 minutes
- **Populates**:
  - ✅ Basic article data (id, name, price, unit, etc.)
  - ✅ commodity_type (via enhancement service)
  - ✅ pod_code (via enhancement service)
  - ❌ extraFields (NOT included in list API response)
  - ❌ PARENT ITEM (needs individual article API call)
  - ❌ POL TERMINAL (needs individual article API call)

**⚠️ ISSUE**: Robaws list API doesn't include extraFields!

---

### "Sync Changed Articles" (Green Button)
- **Command**: `RobawsArticlesSyncService::syncIncremental()`
- **What it does**: Same as Full Sync but only changed articles
- **API Calls**: ~10-50 (depends on changes)
- **Duration**: 1-2 minutes
- **Populates**: Same as Full Sync
- **Use**: Daily/regular updates

---

### "Rebuild Cache" (Red Button)
- **Command**: `RobawsArticlesSyncService::rebuildCache()`
- **What it does**: Deletes all articles and re-syncs from scratch
- **Use**: Emergency only, database corruption

---

## The Real Problem

### What Robaws API Returns

**List API** (`/api/v2/articles`):
```json
{
  "items": [
    {
      "id": 1164,
      "name": "Sallaum...",
      "price": 0,
      "unit": "LM"
      // NO extraFields here!
    }
  ]
}
```

**Individual Article API** (`/api/v2/articles/1164`):
```json
{
  "id": 1164,
  "name": "Sallaum...",
  "extraFields": {
    "PARENT ITEM": { "type": "CHECKBOX", "numberValue": 1 },
    "SHIPPING LINE": { "type": "SELECT", "stringValue": "SALLAUM LINES" },
    "SERVICE TYPE": { "type": "SELECT", "stringValue": "RORO EXPORT" },
    "POL TERMINAL": { "type": "SELECT", "stringValue": "ST 332" },
    "POL": { "type": "SELECT", "stringValue": "Antwerp (ANR), Belgium" },
    "POD": { "type": "SELECT", "stringValue": "Abidjan (ABJ), Ivory Coast" },
    "TYPE": { "type": "SELECT", "stringValue": "Big Van" }
  }
}
```

**The Issue**: Only **"Sync Extra Fields"** fetches individual articles to get extraFields!

---

## Current Status After Our Fixes

### What We've Enhanced

✅ **RobawsArticlesSyncService::processArticle()**
- Now extracts commodity_type and pod_code from article data
- Used by: Full Sync, Incremental Sync
- ⚠️ Still doesn't get extraFields (API limitation)

✅ **RobawsArticleProvider::syncArticleMetadata()**
- Now extracts commodity_type and pod_code
- Used by: "Sync All Metadata" button
- ⚠️ Doesn't call API, so no PARENT ITEM, POL TERMINAL from Robaws

✅ **SyncArticleExtraFields Command**
- Now extracts commodity_type and pod_code
- Fetches individual articles with extraFields
- ✅ Gets PARENT ITEM, POL, POD, TYPE, POL TERMINAL from Robaws
- ✅ This is the ONLY operation that gets complete Robaws data!

---

## Recommendation: Option C - Hybrid Approach

### Initial Setup (One Time)
**Click "Sync Extra Fields"** in production:
- Duration: 30-60 minutes (runs in background)
- API Calls: 1,576 (one-time cost)
- Result: ALL fields populated from Robaws extraFields

### Ongoing Updates (Daily/Automatic)
**Use "Sync Changed Articles"** or webhooks:
- Duration: 1-2 minutes
- API Calls: Only for changed articles (~10-50)
- Result: New/changed articles get basic data + our enhancement

### When to Use Each Button

1. **"Sync Extra Fields"** → One-time initial setup OR when you add new extraFields in Robaws
2. **"Sync Changed Articles"** → Daily updates (webhook-driven)
3. **"Full Sync (All Articles)"** → If webhooks fail, re-sync everything
4. **"Sync All Metadata"** → DON'T USE (doesn't get Robaws extraFields)
5. **"Rebuild Cache"** → Emergency only

---

## Action Plan for Production

### Step 1: Deploy Latest Code
```bash
git pull origin main
php artisan migrate
```

### Step 2: Run "Sync Extra Fields" (One Time)
- Click blue "Sync Extra Fields" button
- Wait 30-60 minutes (background processing)
- This populates ALL extraFields from Robaws

### Step 3: Verify Results
Check a Sallaum article:
- ☑️ Parent Item: TRUE
- 📦 Commodity Type: "Big Van" / "LM Cargo"
- 🏢 POL Terminal: "ST 332"
- 📍 POD: "Abidjan (ABJ), Ivory Coast"
- 🎯 POD Code: "ABJ"

### Step 4: Ongoing Maintenance
- Use "Sync Changed Articles" for daily updates
- Webhooks automatically handle article changes
- Only use "Sync Extra Fields" if you add new custom fields in Robaws

---

## Why "Sync All Metadata" Doesn't Work

The button says "uses name extraction" because:
1. It's designed to be FAST (no API calls)
2. It parses POL/POD/shipping line from article names
3. It CAN'T get extraFields like PARENT ITEM checkbox (not in the name!)
4. It's meant for fixing missing metadata after parser updates, NOT for initial setup

**You need "Sync Extra Fields"** to get the actual Robaws data!

---

## Final Recommendation

**For Production Right Now:**
1. ✅ Deploy latest code (already pushed)
2. ✅ Click "Sync Extra Fields" button (blue)
3. ✅ Wait for completion (~60 minutes)
4. ✅ Smart Article Selection will work perfectly!

**For Future:**
- Use "Sync Changed Articles" for daily updates
- Monitor webhooks to ensure they work
- Only use "Sync Extra Fields" for backfills or new fields

---

**Status**: Ready to execute Option C 🚀
