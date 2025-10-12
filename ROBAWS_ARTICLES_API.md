# Robaws Articles API Integration

## Overview
Direct integration with Robaws Articles API (`/api/v2/articles`) for real-time article synchronization. This replaces the previous offer-based extraction approach.

## API Endpoints

### GET /api/v2/articles
Fetch articles with pagination and filtering.

**Parameters:**
- `page` (int): Page number (0-based)
- `size` (int): Items per page (max 100)
- `sort` (string): Sort order (e.g., "name:asc")
- `filter` (string): Filter criteria (optional)

**Response:**
```json
{
  "items": [
    {
      "id": 1441,
      "name": "20ft FR Flatrack seafreight (head)",
      "code": "FLATRACK20",
      "price": 1250.00,
      "currency": "EUR",
      "unit": "piece",
      "category": "seafreight",
      "active": true,
      "createdAt": "2023-01-15T10:30:00Z",
      "updatedAt": "2024-10-01T14:20:00Z"
    }
  ],
  "totalItems": 1576,
  "page": 0,
  "size": 100
}
```

### GET /api/v2/articles/{id}
Fetch a single article by ID.

**Parameters:**
- `include` (string): Related data to include (comma-separated)

## Synchronization

### Automatic Sync
Articles are automatically synced daily at **2:00 AM** via scheduled command:
```bash
php artisan robaws:sync-articles
```

### Manual Sync
Trigger sync manually via command:
```bash
# Sync (update existing articles)
php artisan robaws:sync-articles

# Rebuild cache (clear and re-fetch all)
php artisan robaws:sync-articles --rebuild
```

### Filament Admin
Use the admin interface for manual operations:
1. Navigate to **Robaws Articles** in Filament
2. Click **"Sync from Robaws API"** to update articles
3. Click **"Rebuild Cache"** to clear and rebuild

## Data Mapping

### Robaws API → Local Cache
```php
[
    'robaws_article_id' => $article['id'],
    'article_code' => $article['code'] ?? $article['articleNumber'] ?? $article['id'],
    'article_name' => $article['name'] ?? 'Unnamed Article',
    'description' => $article['description'] ?? $article['notes'],
    'category' => $article['category'] ?? 'general',
    'unit_price' => $article['price'] ?? $article['unitPrice'] ?? 0,
    'currency' => $article['currency'] ?? 'EUR',
    'unit_type' => $article['unit'] ?? 'piece',
    'is_active' => $article['active'] ?? true,
]
```

### Smart Detection

#### Service Type Detection
Automatically detects service types from article codes and descriptions:
- **RORO**: RORO_IMPORT, RORO_EXPORT
- **FCL**: FCL_IMPORT, FCL_EXPORT, FCL_IMPORT_CONSOL, FCL_EXPORT_CONSOL
- **LCL**: LCL_IMPORT, LCL_EXPORT
- **BB**: BB_IMPORT, BB_EXPORT (Break Bulk)
- **AIR**: AIR_IMPORT, AIR_EXPORT

#### Carrier Detection
Detects carriers from codes:
- GANRLAG (Grimaldi)
- GRIMALDI
- MSC
- CMA
- MAERSK
- COSCO

#### Customer Type Detection
Detects customer segments:
- CIB
- FORWARDERS
- PRIVATE
- HOLLANDICO
- GENERAL

## Statistics

### Current Data (as of Oct 2025)
- **Total Articles**: 1,576
- **Sync Frequency**: Daily at 2:00 AM
- **Last Sync**: Check widget in Filament dashboard
- **Error Rate**: 0% (1,576/1,576 successful)

### Performance
- **Sync Duration**: ~10-15 seconds for all articles
- **API Calls**: ~16 requests (100 articles per page)
- **Database Operations**: Efficient upserts using `updateOrCreate()`

## Troubleshooting

### Check Sync Status
```bash
php artisan tinker
>>> App\Models\RobawsArticleCache::count()
>>> App\Models\RobawsArticleCache::max('last_synced_at')
```

### View Sync Logs
```bash
tail -f storage/logs/laravel.log | grep "articles API sync"
```

### Common Issues

#### 1. API Connection Failed
**Error**: Failed to fetch articles from Robaws API
**Solution**: Check `ROBAWS_BASE_URL` and `ROBAWS_API_KEY` in `.env`

#### 2. Rate Limiting
**Error**: HTTP 429 Too Many Requests
**Solution**: Robaws API has rate limits. The sync service respects pagination limits (max 100 per page).

#### 3. Zero Articles Synced
**Error**: Synced 0 articles, all errors
**Solution**: Check field mapping in `RobawsArticlesSyncService::processArticle()`. Ensure required database fields are populated.

## API Documentation References

- [Robaws API Docs](https://app.robaws.com/public/api-docs/robaws)
- [API Filtering, Paging, Sorting](https://support.robaws.com/nl/article/api-filtering-paging-sorting-10u87mi/)
- [API Rate Limiting](https://support.robaws.com/nl/article/api-rate-limiting-1c0ti0o/)
- [Request Idempotency](https://support.robaws.com/nl/article/request-idempotency-6gesln/)

## Integration Points

### Article Selection in Quotations
Articles synced from the API are automatically available in:
- Filament admin quotation forms
- Customer quotation portal
- Prospect quotation portal

### Pricing Calculations
Article prices from the API are used as base prices in the quotation system, with customer-specific profit margins applied.

## Future Enhancements

1. **Webhook Integration**: Listen for real-time article updates from Robaws
2. **Incremental Sync**: Only sync changed articles (requires `updatedAt` filtering)
3. **Article Categories**: Enhanced categorization based on Robaws taxonomy
4. **Price History**: Track price changes over time
5. **Parent-Child Detection**: Automatically detect article bundles from API data

