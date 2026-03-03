# Robaws Webhook Full Audit & Fix Plan

**Date:** March 2, 2026  
**Context:** Robaws → Bconnect sync (especially pricing) does not update automatically when changes are made in Robaws. User expects webhooks to handle this. Other webhooks (articles) are believed to be working.

---

## 1. Webhook Architecture Overview

### 1.1 Routes & Controllers

| Route | Controller | Method | Provider (for signature) |
|-------|------------|--------|--------------------------|
| `POST /api/webhooks/robaws/articles` | `Api\RobawsWebhookController` | `handleArticle` | `robaws` |
| `POST /api/webhooks/robaws/customers` | `Api\RobawsWebhookController` | `handleCustomer` | `robaws_customers` |
| `POST /api/webhooks/robaws/suppliers` | `Api\RobawsWebhookController` | `handleSupplier` | `robaws_suppliers` |

**Note:** There is also a legacy `RobawsWebhookController` (offers/projects/invoices) in `App\Http\Controllers` but its route is **commented out** in `routes/web.php`. The active webhooks are the API ones above.

### 1.2 Signature Verification

Each handler looks up `webhook_configurations` by `provider`:

- Articles → `provider = 'robaws'`
- Customers → `provider = 'robaws_customers'`
- Suppliers → `provider = 'robaws_suppliers'`

If no active config exists for that provider, `verifySignature()` returns `false` → **401 response** and **no webhook log is created**. This makes debugging silent failures difficult.

---

## 2. What Works vs What Doesn’t

### 2.1 Articles Webhooks (believed working)

| Component | Status | Notes |
|-----------|--------|-------|
| Route | ✅ | `/api/webhooks/robaws/articles` |
| Handler | ✅ | `handleArticle()` → `processArticleFromWebhook()` |
| Register command | ✅ | `php artisan robaws:register-webhook` |
| DB config | ✅ | Inserts `provider='robaws'` |
| Test command | ✅ | `php artisan robaws:test-webhook` (articles only) |
| List command | ✅ | `php artisan robaws:list-webhooks` |
| Events | ✅ | `article.created`, `article.updated`, `article.stock-changed` |

### 2.2 Customer Webhooks (not updating Bconnect)

| Component | Status | Notes |
|-----------|--------|-------|
| Route | ✅ | `/api/webhooks/robaws/customers` |
| Handler | ✅ | `handleCustomer()` → `processCustomerFromWebhook()` → `syncPricingToLinkedUsers()` |
| Register command | ❌ | **No command** – only articles supported |
| DB config | ⚠️ | Depends on manual setup; `CUSTOMER_WEBHOOKS_REGISTERED.md` suggests it was done once |
| Test command | ❌ | **No support** – `robaws:test-webhook` is articles-only |
| List command | ⚠️ | `robaws:list-webhooks` only shows `provider='robaws'` – **does not list robaws_customers** |
| Events | ✅ | Robaws supports `client.created`, `client.updated` |
| Staging vs prod | ⚠️ | Customer webhook may be registered to production URL only |

### 2.3 Supplier Webhooks

| Component | Status | Notes |
|-----------|--------|-------|
| Route | ✅ | `/api/webhooks/robaws/suppliers` |
| Handler | ✅ | `handleSupplier()` |
| Register command | ❌ | No command |
| DB config | ❌ | Likely no `robaws_suppliers` row |
| Test/List | ❌ | No support |

---

## 3. Root Cause Analysis: Why Customer Sync Fails

### Most likely causes (in order)

1. **Customer webhook not registered with Robaws**  
   - `robaws:register-webhook` only registers article webhooks.  
   - Customer webhooks must be registered via Robaws API (or a new command), not via the current command.

2. **No `webhook_configurations` row for `robaws_customers`**  
   - Handler requires `provider = 'robaws_customers'` for signature verification.  
   - If missing or inactive → 401, no log entry, no sync.

3. **Environment mismatch (staging vs production)**  
   - Customer webhook may be registered to `https://app.belgaco.be/...` (production).  
   - If testing on staging (`staging.app.belgaco.be` / Forge URL), Robaws will not send to staging.

4. **Robaws not firing `client.updated` for extraFields**  
   - Some systems only fire on “main” field changes.  
   - PRICING (custom field) changes might not trigger `client.updated`.  
   - Needs confirmation with Robaws.

### Verification commands

```bash
# 1. Check for robaws_customers config (required for signature)
php artisan tinker --execute="
\$c = DB::table('webhook_configurations')->where('provider', 'robaws_customers')->first();
echo \$c ? \"Found: {\$c->url} (active: {\$c->is_active})\" : 'MISSING robaws_customers config';
"

# 2. Check for any client.* webhook logs (proves Robaws is sending)
php artisan tinker --execute="
\$n = \App\Models\RobawsWebhookLog::where('event_type', 'like', 'client.%')->count();
echo \"Client webhooks received: \$n\";
"

# 3. Compare: article webhooks vs client webhooks
php artisan tinker --execute="
\$articles = \App\Models\RobawsWebhookLog::where('event_type', 'like', 'article.%')->count();
\$clients = \App\Models\RobawsWebhookLog::where('event_type', 'like', 'client.%')->count();
echo \"Articles: \$articles | Clients: \$clients\";
"
```

---

## 4. Fix Plan

### Phase 1: Add Customer Webhook Registration (Critical)

1. **Create `robaws:register-customer-webhook` command**  
   - Calls Robaws API `POST /api/v2/webhook-endpoints` with:
     - `url`: `config('app.url') . '/api/webhooks/robaws/customers'`
     - `events`: `['client.created', 'client.updated']`
   - Stores result in `webhook_configurations` with `provider = 'robaws_customers'`.
   - Supports `--url=` for staging/custom URLs.

2. **Alternative: Extend `robaws:register-webhook`**  
   - Add `--type=articles|customers|suppliers`.  
   - Map `--type` to provider, URL path, and events.

### Phase 2: Improve Visibility (Diagnostics)

3. **Update `robaws:list-webhooks`**  
   - Query all providers: `robaws`, `robaws_customers`, `robaws_suppliers`.  
   - Show provider and events clearly.  
   - Or add `--provider=` filter.

4. **Create `robaws:test-customer-webhook` (or extend test command)**  
   - Simulate `client.updated` with realistic payload (including `extraFields.PRICING`).  
   - Use `robaws_customers` secret for signing.  
   - POST to `/api/webhooks/robaws/customers`.

5. **Log rejected webhooks**  
   - On 401 (invalid signature or missing config), create a minimal log entry (e.g. status `rejected`) so we can see that Robaws is sending but verification fails.

### Phase 3: Staging & Production Setup

6. **Document URL handling**  
   - Production: `https://app.belgaco.be/api/webhooks/robaws/customers`  
   - Staging: `https://staging.app.belgaco.be/api/webhooks/robaws/customers` or Forge URL  
   - Ensure webhooks are registered per environment.

7. **Run diagnostics on target environment**  
   - Run the verification commands above.  
   - If `robaws_customers` config is missing, run the new register command.  
   - If Robaws API does not support `client.*` events or extraFields, follow up with Robaws support.

### Phase 4: Optional – Supplier Webhooks

8. **Add `robaws:register-supplier-webhook`** (or extend `--type` to suppliers) if supplier sync is needed.

---

## 5. Implementation Checklist

| # | Task | Effort | Priority |
|---|------|--------|----------|
| 1 | Create `RegisterCustomerWebhook` command (or extend existing) | Medium | P0 |
| 2 | Update `ListRobawsWebhooks` to show all providers | Small | P1 |
| 3 | Add customer webhook test (command or option) | Small | P1 |
| 4 | Add rejected-webhook logging (401 case) | Small | P2 |
| 5 | Verify `webhook_configurations` in production/staging | Small | P0 |
| 6 | Register customer webhook with Robaws (production/staging) | Manual | P0 |
| 7 | Confirm with Robaws that `client.updated` fires for extraFields | External | P0 |

---

## 6. Immediate Manual Steps

Before coding, run:

1. **Check DB**  
   ```bash
   php artisan tinker --execute="DB::table('webhook_configurations')->get(['provider','url','is_active'])->each(fn(\$r) => print_r(\$r));"
   ```

2. **Check webhook logs**  
   ```bash
   php artisan tinker --execute="
   \App\Models\RobawsWebhookLog::selectRaw('event_type, count(*) as c')->groupBy('event_type')->get()->each(fn(\$r) => print(\$r->event_type . ': ' . \$r->c . PHP_EOL));
   "
   ```

3. **If `robaws_customers` config is missing**  
   - Register via Robaws API (or Postman) and insert into `webhook_configurations`.  
   - Or implement Phase 1 and run the new command.

4. **If config exists but no `client.*` logs**  
   - Robaws is not sending customer webhooks (URL or registration issue).  
   - Or Robaws does not fire `client.updated` for PRICING changes.

---

## 7. Summary

- **Articles webhooks:** Fully supported and likely working.
- **Customer webhooks:** Handler and sync logic exist, but:
  - No registration command.
  - List/test commands ignore `robaws_customers`.
  - Config and registration status are unclear.
- **Fix focus:** Add customer webhook registration, improve diagnostics, and verify configuration and Robaws behaviour for `client.updated` (including extraFields).
