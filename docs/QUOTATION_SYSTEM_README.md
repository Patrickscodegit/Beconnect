# Bconnect Quotation System

## Overview

The Bconnect Quotation System is an independent module that provides quotation request management and Robaws integration for Belgaco's logistics operations.

## What's Been Built (Phase 1-2 Complete)

### ✅ Database Schema
- `quotation_requests` - Main quotation request table
- `quotation_request_files` - File attachments
- `robaws_articles_cache` - Cached Robaws articles for offline suggestions
- `schedule_offer_links` - Links between schedules and Robaws offers
- `robaws_webhook_logs` - Webhook event tracking
- `robaws_sync_logs` - Sync operation logging

### ✅ Models
- `QuotationRequest` - With automatic request number generation, relationships, scopes
- `QuotationRequestFile` - With file size formatting, auto-delete from storage
- `RobawsArticleCache` - With carrier/service filtering scopes
- `ScheduleOfferLink` - Links schedules to offers
- `RobawsWebhookLog` - Webhook event tracking
- `RobawsSyncLog` - Sync operation tracking

### ✅ Services
- `RobawsArticleProvider` - Article sync with rate limiting and idempotency
- `ArticleSelectionService` - Business logic for article suggestions

### ✅ Console Commands
- `robaws:sync-articles` - Sync articles from Robaws API

### ✅ Configuration
- `config/quotation.php` - Comprehensive configuration with feature flags
- Email safety configuration for testing with live Robaws
- Development mode support

### ✅ Webhook Infrastructure
- `RobawsWebhookController` - Ready for when Robaws approves webhooks
- Webhook route: `/api/webhooks/robaws`
- Event handling for offers, projects, articles, invoices, documents

## Setup Instructions

### 1. Run Migrations

```bash
php artisan migrate
```

### 2. Configure Environment

Add variables to `.env` (see `docs/QUOTATION_SYSTEM_ENV_VARIABLES.md`):

```env
# Feature flags
QUOTATION_SYSTEM_ENABLED=true

# Email safety (IMPORTANT for testing)
QUOTATION_EMAIL_MODE=safe
QUOTATION_TEST_EMAIL=your-test-email@belgaco.com
QUOTATION_EMAIL_WHITELIST=test@belgaco.com,patrick@belgaco.com

# Sync configuration
ROBAWS_SYNC_METHOD=polling
ROBAWS_WEBHOOKS_ENABLED=false
```

### 3. Sync Articles from Robaws

```bash
php artisan robaws:sync-articles
```

This command:
- Fetches articles from Robaws API
- Caches them locally for fast suggestions
- Respects rate limits
- Uses idempotency to prevent duplicates

### 4. Schedule Daily Article Sync

The command will be scheduled automatically once task scheduling is set up.

## Email Safety

**CRITICAL**: Since Robaws is a live environment, the system has built-in email safety:

### Email Modes

**'safe' mode** (Recommended for testing):
- Emails to whitelisted addresses → Sent normally
- Emails to customers/prospects → Redirected to `QUOTATION_TEST_EMAIL`
- All redirected emails logged with original recipient
- Subject prefixed with `[TEST MODE]`

**'log' mode**:
- All emails logged to `storage/logs/laravel.log`
- No emails actually sent

**'live' mode**:
- All emails sent to actual recipients
- Use only in production!

### Whitelist Configuration

Add team email addresses to the whitelist in `.env`:

```env
QUOTATION_EMAIL_WHITELIST=test@belgaco.com,patrick@belgaco.com,sales@truck-time.com
```

## Architecture Principles

### Complete Independence
- ✅ All new tables (no modifications to existing tables)
- ✅ Separate controllers (no modifications to ScheduleController or IntakeController)
- ✅ Separate services namespace (`App\Services\Robaws\`)
- ✅ Feature-flagged integration points
- ✅ Can be disabled anytime via config

### Non-Breaking Design
- Read-only references to `shipping_schedules` and `intakes` tables
- Foreign keys use `SET NULL` or `CASCADE` (safe deletions)
- IntakeCreationService: single addition at end (wrapped in try-catch)
- Optional includes in views or AJAX loading

## Usage

### For Developers

**Test article sync:**
```bash
php artisan robaws:sync-articles
```

**Check sync logs:**
```bash
php artisan tinker
>>> \App\Models\RobawsSyncLog::latest()->first()
```

**Check rate limit status:**
```bash
>>> Cache::get('robaws_rate_limit')
```

### For Belgaco Team

Once Filament resources are built (Phase 7), you'll be able to:
- View all quotation requests
- Link requests to schedules
- Get suggested Robaws articles
- Update Robaws offers with selected articles
- Track request status

### For Customers/Prospects

Once portals are built (Phases 5-6), they'll be able to:
- View shipping schedules
- Request quotations
- Upload cargo specifications
- Track request status

## Robaws API Integration

### Current Status
- ✅ Article sync working
- ✅ Rate limiting implemented
- ✅ Idempotency keys used
- ⏳ Webhooks ready but not enabled (waiting for Robaws approval)

### Webhook Activation

When Robaws approves webhooks:
1. Update `.env`: `ROBAWS_WEBHOOKS_ENABLED=true`
2. Update `.env`: `ROBAWS_SYNC_METHOD=webhooks`
3. System automatically switches from polling to real-time updates

See `docs/ROBAWS_WEBHOOK_REQUEST.md` for details.

## Next Steps

### Phase 3: Schedule Integration
- Create `RobawsScheduleIntegrationController`
- Build article suggestion UI
- Implement offer update functionality

### Phase 4: Public Schedule Section
- Public schedule viewing
- Anonymized carrier information
- Request quote from schedule

### Phase 5-6: Quotation Portals
- Prospect quotation request form
- Customer quotation portal
- File upload functionality

### Phase 7: Filament Admin
- Quotation request management
- Article management
- Team workflow

See the full plan in `/pricing-service-integration.plan.md`

## Support

For questions or issues:
- Check logs in `storage/logs/laravel.log`
- Check Robaws integration status in Filament Admin
- Review configuration in `config/quotation.php`
- Consult this documentation and the plan file

