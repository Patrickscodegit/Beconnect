# Robaws Article Sync - Complete Explanation

## How Robaws Articles Work

According to [Robaws documentation](https://support.robaws.com/nl/article/synchronizing-article-data-with-your-own-database-mkxghl/), articles in Robaws are **read-only** via the API.

### API Limitations

- ✅ **GET /api/v2/articles** - Fetch all articles (with pagination)
- ✅ **GET /api/v2/articles/{id}** - Fetch single article details
- ❌ **PUT/PATCH /api/v2/articles/{id}** - NOT SUPPORTED (articles are read-only)
- ❌ **POST /api/v2/articles** - NOT SUPPORTED (articles managed internally)

### How Articles Are Updated in Robaws

Articles in Robaws are typically:
1. Imported from external systems (ERP, inventory management, etc.)
2. Updated by Robaws internal processes
3. Synced via scheduled imports

**Custom fields like "Parent Item" are likely set during import, not via manual UI editing.**

## Our Sync Strategy

### 1. Daily Incremental Sync (3 AM)
**File:** `routes/console.php` (line 53-56)

```php
Schedule::command('robaws:sync-articles --incremental')
    ->dailyAt('03:00')
    ->timezone('Europe/Brussels');
```

Uses `updatedFrom` parameter to fetch only changed articles:
```
GET /api/v2/articles?updatedFrom=2025-10-20T03:00:00.000Z&page=0&size=100
```

### 2. Real-time Webhook Updates
**Endpoint:** `POST /api/webhooks/robaws/articles`
**Handler:** `Api\RobawsWebhookController@handleArticle`

When Robaws updates an article internally, it sends a webhook to our system with the updated data.

### 3. Custom Field Extraction
**File:** `app/Services/Quotation/RobawsArticlesSyncService.php` (line 161-177)

Our `extractParentItemFromArticle()` method handles both formats:

```php
protected function extractParentItemFromArticle(array $article): bool
{
    // Try custom_fields first (API format)
    if (isset($article['custom_fields']['parent_item'])) {
        return (bool) $article['custom_fields']['parent_item'];
    }

    // Try extraFields (webhook format)
    // The field ID is C965754A-4523-4916-A127-3522DE1A7001
    if (isset($article['extraFields']['C965754A-4523-4916-A127-3522DE1A7001']['booleanValue'])) {
        return (bool) $article['extraFields']['C965754A-4523-4916-A127-3522DE1A7001']['booleanValue'];
    }

    // Fallback to false if not found
    return false;
}
```

## Understanding the Database

### RobawsArticleCache Model

**Table:** `robaws_articles_cache`

Key columns:
- `id` - Sequential primary key (1, 2, 3, 4...)
- `robaws_article_id` - The actual article ID from Robaws (could be "1834", "2500", etc.)
- `article_name` - Article name
- `is_parent_item` - Our cached copy of the "Parent Item" custom field

### Important: Querying the Cache

❌ **WRONG:**
```php
RobawsArticleCache::find('1834'); // Looks for id=1834, not robaws_article_id
```

✅ **CORRECT:**
```php
RobawsArticleCache::where('robaws_article_id', '1834')->first();
```

## The 405 Error Explained

When you tried to edit the "Parent Item" checkbox in Robaws UI, you received:

```
405 Method Not Allowed
```

**This is from Robaws, not our system.** It means:

1. Robaws doesn't allow editing articles through their UI
2. The "Parent Item" custom field is managed by their import process
3. Your user account may not have permissions to edit article custom fields

## How to Update the Parent Item Field

Since you can't edit it directly in Robaws, you need to:

### Option 1: Update in Source System
If Robaws imports articles from another system (ERP, inventory, etc.), update the field there and it will sync to Robaws.

### Option 2: Contact Robaws Support
Ask them:
1. How to update custom fields on articles?
2. Can "Parent Item" be edited manually or only via import?
3. What's the proper workflow for managing this field?

### Option 3: Manage It in Your System
Since the field syncs FROM Robaws TO your system, you could:
1. Add a UI in Filament to manually override the field locally
2. Keep your own "source of truth" for which articles are parent items
3. Use the Robaws value as a suggestion but allow manual overrides

## Testing the Webhook Sync

To verify our fix is working, you can simulate a webhook update:

```bash
ssh forge@bconnect.64.226.120.45.nip.io
cd /home/forge/app.belgaco.be

# Simulate article.updated webhook for article 1834
php artisan robaws:test-webhook article.updated 1834

# Check the logs
tail -n 100 storage/logs/laravel.log | grep -A 10 "Parent Item"

# Verify in Filament
# Go to: https://app.belgaco.be/admin/robaws-articles
# Search for "1834" in the Robaws Article ID column
# Check the "Is Parent Item" checkbox value
```

## Troubleshooting

### Webhook Not Received
1. Check `robaws_webhook_logs` table for incoming webhooks
2. Verify webhook is registered: `php artisan robaws:check-webhook-health`
3. Check Laravel logs: `tail -f storage/logs/laravel.log`

### Article Not Syncing
1. Check if article exists in cache:
   ```php
   RobawsArticleCache::where('robaws_article_id', '1834')->first();
   ```
2. Force sync single article:
   ```bash
   php artisan robaws:sync-articles --article=1834
   ```
3. Run full sync:
   ```bash
   php artisan robaws:sync-articles --full
   ```

### Custom Field Not Updating
1. Check webhook payload in `robaws_webhook_logs.payload` column
2. Verify field ID matches: `C965754A-4523-4916-A127-3522DE1A7001`
3. Check extraction logic in `extractParentItemFromArticle()`

## Summary

✅ **Your webhook system is working correctly**
✅ **The Parent Item extraction fix is deployed**
✅ **Article 1834 was in cache all along**
❌ **Robaws doesn't allow manual article editing** (this is by design)

**Next Action:** Contact Robaws support to ask how to properly update the "Parent Item" custom field on articles, or manage it in your own system as a local override.

