# Robaws Webhook Management Guide

## Overview

This guide explains how to manage Robaws webhook registrations to prevent duplicate webhook calls and ensure proper integration.

## The Problem: Duplicate Webhooks

If you register the same webhook URL multiple times with Robaws, they will send **multiple webhook calls** for each event. For example, if you have 3 registered webhooks for `article.updated`, Robaws will send 3 separate HTTP requests to your endpoint when an article is updated.

**Symptoms:**
- Database shows multiple webhook configurations with same URL
- Webhook logs show duplicate `robaws_id` entries
- Increased server load from processing duplicate events

## Available Commands

### 1. List All Webhooks

View all registered webhooks in your database:

```bash
php artisan robaws:list-webhooks
```

**Options:**
- `--active-only` - Show only active webhooks

**Example output:**
```
🔗 Robaws Webhooks:

+-------+--------------------------------------+-----------+-------------------------------------------+
| DB ID | Webhook ID                           | Status    | URL                                       |
+-------+--------------------------------------+-----------+-------------------------------------------+
| 1     | 3c2e1ba7-18df-40fd-9f75-c500a53291c5 | ✓ ACTIVE  | https://app.belgaco.be/api/webhooks/...   |
| 2     | ece80636-db3a-498a-ad18-31cabacafe0c | ✗ INACTIVE| https://app.belgaco.be/api/webhooks/...   |
+-------+--------------------------------------+-----------+-------------------------------------------+

📊 Summary:
  Active:   1
  Inactive: 1
  Total:    2
```

### 2. Register a New Webhook

Register a webhook with Robaws:

```bash
php artisan robaws:register-webhook
```

**Options:**
- `--url=<url>` - Custom webhook URL (defaults to app URL)

**Safety Features:**
- ✅ Shows all existing webhooks before registering
- ✅ Warns if active webhooks already exist
- ✅ Requires double confirmation to prevent accidental duplicates
- ✅ Defaults to CANCEL if duplicates detected

**Example:**
```bash
# This will now show warnings and require confirmation:
php artisan robaws:register-webhook

⚠️  EXISTING WEBHOOKS FOUND:

  [✓ ACTIVE] ID: 3c2e1ba7-18df-40fd-9f75-c500a53291c5
  URL: https://app.belgaco.be/api/webhooks/robaws/articles
  Registered: 2025-10-20 18:59:13
  ---

❌ You already have 1 ACTIVE webhook(s) registered!
Registering another webhook will create DUPLICATES.

💡 Use 'php artisan robaws:list-webhooks' to see all webhooks
💡 Use 'php artisan robaws:deactivate-webhook' to deactivate duplicates

 Are you ABSOLUTELY SURE you want to create another webhook? (yes/no) [no]:
```

### 3. Deactivate a Webhook

Deactivate one or more webhooks:

```bash
# Interactive mode (will ask which to deactivate)
php artisan robaws:deactivate-webhook

# Deactivate specific webhook by DB ID
php artisan robaws:deactivate-webhook 2

# Deactivate all except one (keep ID 1, deactivate all others)
php artisan robaws:deactivate-webhook --all-except=1
```

**Example:**
```bash
php artisan robaws:deactivate-webhook --all-except=1

⚠️  This will deactivate 2 webhook(s), keeping only ID 1 active.

 Continue? (yes/no) [no]:
 > yes

✅ Deactivated 2 webhook(s).
💡 Only webhook ID 1 is now active.
```

## Best Practices

### ✅ DO:
1. **Run `robaws:list-webhooks`** before registering new webhooks
2. **Keep only ONE active webhook** per event type
3. **Deactivate duplicates immediately** if accidentally created
4. **Contact Robaws support** to delete inactive webhooks from their system

### ❌ DON'T:
1. **Don't register webhooks multiple times** - it creates duplicates
2. **Don't manually update** webhook configurations in the database
3. **Don't delete** webhook records - deactivate them instead

## Fixing Duplicate Webhooks

If you discover duplicate webhooks:

### Step 1: Deactivate Duplicates Locally

```bash
# Keep the oldest webhook (usually ID 1)
php artisan robaws:deactivate-webhook --all-except=1

# Verify only one is active
php artisan robaws:list-webhooks --active-only
```

### Step 2: Contact Robaws Support

Email support@robaws.be with:

```
Subject: Request to Delete Duplicate Webhooks

Hi Robaws Support,

We have duplicate webhook registrations. Please delete the following webhooks:

DELETE:
- Webhook ID: ece80636-db3a-498a-ad18-31cabacafe0c
- Webhook ID: e83097a8-8376-447c-b9af-ffa2fb8f4b54

KEEP:
- Webhook ID: 3c2e1ba7-18df-40fd-9f75-c500a53291c5
- URL: https://app.belgaco.be/api/webhooks/robaws/articles
- Events: article.created, article.updated, article.stock-changed

Thank you!
```

## Webhook Configuration Structure

Webhooks are stored in the `webhook_configurations` table:

```sql
id                  - Auto-incrementing database ID
provider            - 'robaws' or 'robaws_customers'
webhook_id          - UUID from Robaws
secret              - Signing secret for signature verification
url                 - Webhook endpoint URL
events              - JSON array of event types
is_active           - Boolean flag
registered_at       - Timestamp when registered
created_at          - Record creation timestamp
updated_at          - Record update timestamp
```

## Events Handled

### Article Webhooks
Endpoint: `/api/webhooks/robaws/articles`

Events:
- `article.created` - New article created in Robaws
- `article.updated` - Article modified in Robaws
- `article.stock-changed` - Article stock quantity changed

### Customer Webhooks
Endpoint: `/api/webhooks/robaws/customers`

Events:
- `client.created` - New client/customer created
- `client.updated` - Client information updated

## Monitoring Webhooks

### Check Recent Webhook Activity

```bash
php artisan tinker --execute="
\$recent = \App\Models\RobawsWebhookLog::where('created_at', '>=', now()->subHours(24))
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get(['event_type', 'status', 'created_at']);
foreach (\$recent as \$log) {
    echo \$log->created_at . ' | ' . \$log->event_type . ' | ' . \$log->status . PHP_EOL;
}
"
```

### Check Webhook Health

```bash
php artisan webhook:health
```

This command (if available) checks:
- Number of active webhook configurations
- Recent webhook success rates
- Failed webhook calls
- Duplicate webhook detection

## Troubleshooting

### "Invalid signature" errors

**Cause:** Secret mismatch between your database and Robaws

**Solution:**
1. Check your `webhook_configurations` table for the correct secret
2. Ensure the secret hasn't changed in Robaws
3. Re-register the webhook if needed

### Duplicate webhook calls

**Cause:** Multiple webhook registrations in Robaws

**Solution:**
1. Run `php artisan robaws:list-webhooks`
2. Deactivate duplicates: `php artisan robaws:deactivate-webhook --all-except=1`
3. Contact Robaws support to delete duplicates on their end

### Webhooks not being received

**Cause:** Inactive webhook or URL unreachable

**Solution:**
1. Verify webhook is active: `php artisan robaws:list-webhooks --active-only`
2. Check webhook URL is publicly accessible
3. Check logs for signature verification failures
4. Verify firewall/security rules allow Robaws IP addresses

## Security

### Signature Verification

All webhooks are verified using HMAC SHA256:

1. Robaws sends header: `Robaws-Signature: t=<timestamp>,v1=<signature>`
2. Our app reconstructs the signed payload: `<timestamp>.<body>`
3. We compute HMAC SHA256 using the webhook secret
4. We compare signatures using constant-time comparison
5. We reject webhooks older than 5 minutes

**Never disable signature verification in production!**

## Support

For webhook issues:
- **Robaws Support**: support@robaws.be
- **Internal Docs**: Check `ROBAWS_API_REFERENCE.md`
- **Webhook Logs**: `app/Models/RobawsWebhookLog.php`

---

**Last Updated**: October 23, 2025  
**Version**: 1.0

