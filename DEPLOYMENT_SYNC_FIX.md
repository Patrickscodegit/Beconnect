# Deployment Instructions: Sync Extra Fields Fix

## What Was Fixed
The "Sync Extra Fields" button was stuck in loading state because it used `Artisan::call()` which blocked the HTTP request for 30-60 minutes. Now it dispatches async jobs to the queue.

---

## Production Deployment Steps

### 1. Deploy Code (2 min)
```bash
cd /var/www/app.belgaco.be
git pull origin main
```

### 2. Verify Queue Worker is Running
```bash
# Check if queue worker is active
ps aux | grep "queue:work"

# OR check with supervisorctl (if using Supervisor)
sudo supervisorctl status

# OR check via Forge dashboard
# Navigate to Servers → Daemons/Queue Workers
```

**Expected output:** You should see a process running `php artisan queue:work`

**If NOT running:**
- Contact server admin to start queue worker
- OR start manually: `php artisan queue:work --daemon`
- OR configure via Laravel Forge: Add Queue Worker daemon

### 3. Clear Failed Jobs (Optional)
```bash
php artisan queue:clear-failed
```

---

## Testing the Fix

### Step 1: Click the Button
1. Go to **Admin Panel → Articles**
2. Click **"Sync Extra Fields"** (blue info button)
3. Confirm the modal

**Expected:**
- ✅ Modal closes immediately
- ✅ Success notification: "Extra fields sync queued!"
- ✅ Message shows: "Queuing 1,576 sync jobs with 2-second delays. Estimated time: ~53 minutes"

**If modal still stuck:**
- Check browser console for JavaScript errors
- Hard refresh page (Cmd+Shift+R / Ctrl+Shift+R)
- Clear browser cache

### Step 2: Monitor Progress
1. Go to **Admin Panel → Sync Progress**

**Expected to see:**
- 🔄 Status: "Sync In Progress"
- 📊 Pending Jobs: ~1,576 (decreases over time)
- ⏱️ Estimated time remaining: ~53 minutes (decreases)
- 📈 Field population percentages increasing

### Step 3: Watch Queue Processing (Optional)
```bash
# Watch queue in real-time
php artisan queue:listen --timeout=120

# OR watch logs
tail -f storage/logs/laravel.log | grep "Syncing metadata"
```

**Expected output:**
```
Syncing metadata for single article {"article_id":1175}
Successfully synced metadata for article {"article_id":1175}
Syncing metadata for single article {"article_id":1176}
...
```

### Step 4: Verify Completion (~53 minutes later)
1. Check Sync Progress page
2. Verify stats:
   - ✅ Pending Jobs: 0
   - ✅ Parent Items: >0 articles
   - ✅ Commodity Type: ~100%
   - ✅ POD Code: >15%
   - ✅ POL Terminal: >0%

---

## How It Works Now

### Architecture
1. **Button Click** → Dispatches `DispatchArticleExtraFieldsSyncJobs`
2. **Dispatcher Job** → Queues 1,576 × `SyncSingleArticleMetadataJob` with delays
3. **Queue Worker** → Processes jobs sequentially with 2-second gaps
4. **Each Job** → Calls `RobawsArticleProvider::syncArticleMetadata()`
5. **Provider** → Fetches from Robaws API, extracts fields, saves to DB

### Rate Limiting
- **Method:** Incremental delays on job dispatch
- **Delay:** 2 seconds between each job
- **Total Time:** 1,576 jobs × 2s = ~3,152 seconds = ~53 minutes
- **API Calls:** 1,576 (one per article)
- **API Quota:** Safe (10,000 daily limit)

### What Gets Synced
Each job syncs these fields via `RobawsArticleProvider::syncArticleMetadata()`:
- ✅ Parent Item status (`is_parent_item`)
- ✅ Shipping Line (`shipping_line`)
- ✅ Service Type (`service_type`)
- ✅ POL Terminal (`pol_terminal`)
- ✅ POL Code (`pol_code`)
- ✅ POD Code (`pod_code`) 🧠
- ✅ Commodity Type (`commodity_type`) 🧠
- ✅ Update/Validity dates
- ✅ Article Info

---

## Troubleshooting

### Issue: "No jobs showing in queue"
**Cause:** Queue worker not running
**Fix:**
```bash
php artisan queue:work --daemon --tries=3 --timeout=120
```

### Issue: "Jobs failing immediately"
**Cause:** API connection or credentials issue
**Fix:**
```bash
# Check failed jobs
php artisan queue:failed

# View specific failure
php artisan queue:failed:show [job-id]

# Retry failed jobs
php artisan queue:retry all
```

### Issue: "Sync stuck at certain percentage"
**Cause:** Some articles might be failing
**Fix:**
```bash
# Check logs for errors
tail -100 storage/logs/laravel.log | grep "Failed to sync"

# Clear failed jobs and restart
php artisan queue:clear-failed
# Click button again
```

### Issue: "Button still stuck after deployment"
**Cause:** Browser cache or old JavaScript
**Fix:**
- Hard refresh (Cmd+Shift+R)
- Clear browser cache
- Try incognito mode

---

## Rollback (If Needed)

If something goes wrong:
```bash
git revert HEAD
git push origin main
```

Then contact support to investigate.

---

## Success Criteria

✅ Button returns immediately (< 2 seconds)
✅ Success notification appears
✅ Jobs appear in queue (check Sync Progress page)
✅ Jobs process over time (pending count decreases)
✅ Fields populate (percentages increase)
✅ After ~53 minutes: All fields populated

---

## Questions?

Check:
1. Sync Progress page for real-time status
2. `storage/logs/laravel.log` for detailed logs
3. Failed jobs table: `php artisan queue:failed`
