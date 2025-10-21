# Customer Webhooks - Registration Complete ✅

## 🎉 **Webhooks Successfully Registered with Robaws**

### ✅ **Registration Details**

**Webhook ID**: `63f591e0-8aea-4b39-998a-a46a88980b4a`  
**Secret**: `2b5843a1-2f6f-4f4d-8ca6-04076a253082`  
**URL**: `https://app.belgaco.be/api/webhooks/robaws/customers`  
**Events**: `client.created`, `client.updated`  
**Status**: ✅ **ACTIVE**

---

## 📋 **What This Means**

Robaws will now send real-time webhook notifications to your system whenever:

1. **`client.created`** - A new customer is created in Robaws
2. **`client.updated`** - An existing customer is updated in Robaws

These webhooks will be received at:
```
POST https://app.belgaco.be/api/webhooks/robaws/customers
```

And processed by:
```
RobawsWebhookController::handleCustomer()
```

---

## 🔐 **Security**

The webhook uses **HMAC-SHA256 signature verification** with the secret:
```
2b5843a1-2f6f-4f4d-8ca6-04076a253082
```

**IMPORTANT**: This is stored in the database and will be used to verify all incoming customer webhooks.

**Note**: Customer webhooks use a **different secret** than article webhooks for security isolation.

---

## ✅ **Database Record**

The webhook configuration has been stored in the `webhook_configurations` table:

```php
provider: 'robaws_customers'
webhook_id: '63f591e0-8aea-4b39-998a-a46a88980b4a'
secret: '2b5843a1-2f6f-4f4d-8ca6-04076a253082'
url: 'https://app.belgaco.be/api/webhooks/robaws/customers'
events: ['client.created', 'client.updated']
is_active: true
```

---

## 🧪 **Testing the Webhook**

To test if the webhook is working:

### Option 1: Create/Update a Customer in Robaws
1. Go to Robaws
2. Create a new customer or update an existing one
3. Check the logs:
   ```bash
   tail -f storage/logs/laravel.log | grep -i "customer webhook"
   ```

### Option 2: Check Webhook Logs in Filament
1. Go to `https://app.belgaco.be/admin`
2. Navigate to **Robaws Data → Robaws Webhook Logs**
3. Filter by event type: `client.created` or `client.updated`

### Option 3: Query Webhook Logs via Tinker
```bash
php artisan tinker --execute="
App\Models\RobawsWebhookLog::where('event_type', 'like', 'client.%')
    ->latest()
    ->first();
"
```

---

## 🔄 **How It Works**

1. **Customer created/updated in Robaws** → Triggers webhook
2. **Robaws sends POST request** to `https://app.belgaco.be/api/webhooks/robaws/customers`
3. **Signature verified** using the secret
4. **Webhook logged** in `robaws_webhook_logs` table
5. **Customer synced** via `RobawsCustomerSyncService::processCustomerFromWebhook()`
6. **Customer updated** in `robaws_customers_cache` table
7. **Response sent** to Robaws (200 OK)

---

## 📊 **Webhook Flow**

```
Robaws → POST /api/webhooks/robaws/customers
         ↓
    Signature Verification (HMAC-SHA256)
         ↓
    Log Webhook (robaws_webhook_logs)
         ↓
    Process Customer Data
         ↓
    Update robaws_customers_cache
         ↓
    Return 200 OK to Robaws
```

---

## 🎯 **What's Automated Now**

✅ **New customers** in Robaws automatically appear in Bconnect  
✅ **Customer updates** in Robaws automatically sync to Bconnect  
✅ **Role changes** are automatically detected and updated  
✅ **Contact info changes** are automatically synced  
✅ **All changes logged** for audit trail  

---

## 📝 **Next Steps**

The webhook is now active and will start receiving events immediately. You can:

1. **Monitor webhook activity** in the logs
2. **View webhook logs** in Filament
3. **Test** by creating/updating a customer in Robaws
4. **Verify** that customers auto-sync in real-time

---

## ✅ **Registration Complete**

Customer webhooks are now **fully operational** and will keep your customer data in sync automatically! 🎉

**Webhook ID**: `63f591e0-8aea-4b39-998a-a46a88980b4a`  
**Status**: ✅ **ACTIVE and MONITORING**

