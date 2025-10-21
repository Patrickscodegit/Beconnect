# Deploy Customer Sync to Production

## 🚀 **Deployment Steps**

### Step 1: Deploy via Laravel Forge

The code is already pushed to `main` branch. Laravel Forge will auto-deploy.

**OR manually trigger deployment**:
```bash
ssh forge@app.belgaco.be
cd /home/forge/app.belgaco.be
git pull origin main
```

---

### Step 2: Run Migration on Production

```bash
ssh forge@app.belgaco.be
cd /home/forge/app.belgaco.be
php artisan migrate --force
```

**Expected output**:
```
2025_10_21_182733_create_robaws_customers_cache_table ......... DONE
```

---

### Step 3: Clear Caches

```bash
php artisan config:cache
php artisan route:clear
php artisan view:clear
php artisan filament:cache-components
```

---

### Step 4: Run Initial Customer Sync

```bash
# Full sync (all 4,017 customers) - will take 5-10 minutes
php artisan robaws:sync-customers --full
```

**Expected output**:
```
Performing full sync...
+-------------------+-------+
| Metric            | Count |
+-------------------+-------+
| Total Fetched     | 4017  |
| Created           | 4017  |
| Updated           | 0     |
| Skipped (dry-run) | 0     |
| Errors            | 0     |
+-------------------+-------+
✅ Customer sync completed successfully
```

---

### Step 5: Verify Sync

```bash
# Check total count
php artisan tinker --execute="echo 'Total: ' . App\Models\RobawsCustomerCache::count();"

# Verify Aeon Shipping LLC
php artisan tinker --execute="echo App\Models\RobawsCustomerCache::where('name', 'like', '%Aeon%')->first()?->name . ' → ' . App\Models\RobawsCustomerCache::where('name', 'like', '%Aeon%')->first()?->role;"
```

**Expected output**:
```
Total: 4017
Aeon Shipping LLC → FORWARDER
```

---

### Step 6: Register Customer Webhooks with Robaws

You need to register two webhooks with Robaws for customer events:

#### **Webhook 1: client.created**
- **Event**: `client.created`
- **URL**: `https://app.belgaco.be/api/webhooks/robaws/customers`
- **Method**: POST
- **Authentication**: HMAC-SHA256 signature (using stored secret)

#### **Webhook 2: client.updated**
- **Event**: `client.updated`
- **URL**: `https://app.belgaco.be/api/webhooks/robaws/customers`
- **Method**: POST
- **Authentication**: HMAC-SHA256 signature (using stored secret)

**Note**: These webhooks will use the **same webhook secret** as the article webhooks that are already registered.

---

### Step 7: Test Webhook (Optional)

Create or update a test customer in Robaws and check the logs:

```bash
# Monitor webhook logs in real-time
tail -f storage/logs/laravel.log | grep -i customer

# Check webhook log table
php artisan tinker --execute="App\Models\RobawsWebhookLog::where('event_type', 'like', 'client.%')->latest()->first();"
```

---

### Step 8: Verify Filament UI

1. Log in to Filament: `https://app.belgaco.be/admin`
2. Navigate to **Robaws Data → Robaws Customers**
3. Verify:
   - ✅ All 4,017 customers are visible
   - ✅ Role badges are showing correctly
   - ✅ Search works
   - ✅ Filters work
   - ✅ "Sync All Customers" button is visible

4. Test intake form:
   - Go to **Intakes → Create Intake**
   - Click on "Customer" dropdown
   - Search for "Aeon"
   - Select "Aeon Shipping LLC"
   - ✅ Verify customer details auto-fill

---

### Step 9: Monitor Scheduled Tasks

The following schedules are now active:

```
03:30 - Daily incremental customer sync
04:00 - Weekly full customer sync (Sunday)
22:00 - Daily push pending customer changes to Robaws
```

Check if scheduler is running:
```bash
php artisan schedule:list
```

---

## 🔍 **Troubleshooting**

### Issue: Migration fails
```bash
# Check if table already exists
php artisan tinker --execute="Schema::hasTable('robaws_customers_cache');"
```

### Issue: Sync fails with API rate limit
```bash
# Use smaller batches
php artisan robaws:sync-customers --limit=1000
# Wait 5 minutes
php artisan robaws:sync-customers --limit=1000
```

### Issue: Customers not showing in Filament
```bash
# Clear Filament cache
php artisan filament:cache-components
php artisan optimize:clear
```

### Issue: Webhook signature verification fails
```bash
# Check webhook configuration in database
php artisan tinker --execute="DB::table('webhook_configurations')->where('provider', 'robaws')->first();"
```

---

## ✅ **Deployment Checklist**

- [ ] Code deployed to production
- [ ] Migration run successfully
- [ ] Caches cleared
- [ ] Initial sync completed (4,017 customers)
- [ ] Aeon Shipping LLC verified as FORWARDER
- [ ] Webhooks registered with Robaws
- [ ] Filament UI tested and working
- [ ] Intake customer select tested
- [ ] Webhook test successful
- [ ] Scheduled tasks verified

---

## 🎉 **Post-Deployment**

Once deployed, the system will:
- ✅ Automatically sync new customers via webhooks
- ✅ Run daily incremental sync at 03:30
- ✅ Run weekly full sync on Sunday at 04:00
- ✅ Push local changes to Robaws daily at 22:00

**Customer sync is now production-ready! 🚀**

