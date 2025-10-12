# Robaws Webhook Setup Request

## Email Template for Robaws Support

**To**: support@robaws.be  
**Subject**: Webhook Setup Request for Bconnect Integration

---

Dear Robaws Support Team,

We are building a custom integration for **Belgaco** (customer account: sales@truck-time.com).

We would like to enable webhooks for real-time synchronization between our Bconnect system and Robaws.

**Webhook URL**: `https://bconnect.belgaco.com/api/webhooks/robaws`

**Events we need**:
- `offer.created`
- `offer.updated`
- `offer.status_changed`
- `project.created`
- `project.updated`
- `project.status_changed`
- `invoice.created`
- `document.uploaded`
- `article.updated`

**Integration Type**: Custom Integration  
**Authentication**: HTTP Basic Authentication  
**API User**: sales@truck-time.com (API only user)

This will replace our current polling mechanism and provide real-time updates to our customers.

Thank you for your assistance!

Best regards,  
Belgaco Team

---

## Webhook Endpoint Information

Once Robaws confirms webhook setup, activate with these steps:

### 1. Update .env

```env
ROBAWS_WEBHOOKS_ENABLED=true
ROBAWS_SYNC_METHOD=webhooks
```

### 2. Test Webhook Endpoint

```bash
# Test that webhook endpoint is accessible
curl -X POST https://bconnect.belgaco.com/api/webhooks/robaws \
  -H "Content-Type: application/json" \
  -d '{"event":"test.event","data":{"id":"test"}}'
```

Expected response when webhooks enabled:
```json
{"status":"processed"}
```

Expected response when webhooks disabled:
```json
{
  "status":"webhooks_not_enabled",
  "message":"Webhooks are not yet enabled..."
}
```

### 3. Monitor Webhook Logs

```bash
# Check webhook logs
php artisan tinker
>>> \App\Models\RobawsWebhookLog::latest()->take(10)->get()
```

Or check in Filament Admin:
- Navigate to Robaws Integration section
- View Webhook Logs

### 4. Disable Polling

Once webhooks are working, the scheduled polling will automatically stop.

The system checks `config('quotation.sync.method')` and skips polling when set to 'webhooks'.

## Webhook Event Handling

Current implementation handles these events:

| Event | Action |
|-------|--------|
| `offer.updated` | Syncs offer data to quotation_requests table |
| `offer.status_changed` | Updates quotation status |
| `project.updated` | Syncs project data to shipments table (Phase 11) |
| `article.updated` | Updates cached article in robaws_articles_cache |
| `invoice.created` | Logs event (can be enhanced) |
| `document.uploaded` | Logs event (can be enhanced) |

## Troubleshooting

### Webhook not firing
1. Check .env: `ROBAWS_WEBHOOKS_ENABLED=true`
2. Check URL is accessible from Robaws
3. Check Robaws webhook configuration
4. Check `robaws_webhook_logs` table for errors

### Rate Limiting
Even with webhooks, some operations still use the API. Rate limits are tracked in cache with key `robaws_rate_limit`.

```bash
# Check current rate limit status
php artisan tinker
>>> Cache::get('robaws_rate_limit')
```

## Benefits of Webhooks

✅ Real-time updates (no delays)  
✅ Reduces API calls (avoids rate limiting)  
✅ Better customer experience (instant status updates)  
✅ Complies with Robaws best practices  
✅ More efficient than polling

