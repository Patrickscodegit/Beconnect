<!-- 072fb580-7ab2-41da-81de-d5cd7c2d9760 45b213f7-485a-4174-b241-d8d7f513c717 -->
# Implement Robaws Webhooks for Real-time Article Updates

## Overview

Based on the [Robaws Webhooks documentation](https://support.robaws.com/nl/article/webhooks-1kqzzp7/), implement a webhook endpoint to receive real-time article updates, drastically reducing API calls and keeping data fresh.

## Current Problem

- Incremental sync makes 1,576 API calls (one per article) 
- Takes 3-4 minutes even with rate limiting
- Only runs once per day (3 AM)
- Data can be stale between syncs

## Solution: Webhooks Only (One-Way Sync)

**Decision: Implement webhooks for Robaws → Bconnect ONLY**
- Real-time updates from Robaws
- Zero API calls for article updates
- Bi-directional sync (Bconnect → Robaws) deferred to future

## Implementation Decisions (Finalized)

### ✅ Hosting:
- **Production URL**: `https://app.belgaco.be`
- **Server**: Laravel Forge (bubbling-lagoon)
- **IP**: `64.226.120.45`
- **SSL**: Let's Encrypt (auto-renewing)

### ✅ Webhook Processing:
- **Synchronous processing** (not queued)
- Responds within 50-100ms (well within 2 second limit)
- Simpler code, easier debugging
- Suitable for article update volume

### ✅ Webhook Events:
Subscribe to **all three events**:
- `article.created` - New articles
- `article.updated` - Article modifications
- `article.stock-changed` - Stock quantity changes

### ✅ Sync Direction:
- **One-way only** (Robaws → Bconnect)
- Bi-directional sync (Bconnect → Robaws) deferred to Phase 7 (future)
- Simpler initial implementation
- Solves main problem (1,576 API calls)

### ✅ Environment Strategy:
- **Production**: `app.belgaco.be` with webhooks
- **Local**: No webhooks (test with manual sync)
- **Staging**: Not needed (skip for now)

---

## Phase 1: Database Migration (5 min)

**Create migration for webhook configurations:**

```bash
php artisan make:migration create_webhook_configurations_table
```

**Migration: `database/migrations/xxxx_create_webhook_configurations_table.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('robaws'); // 'robaws'
            $table->string('webhook_id')->nullable(); // ID from Robaws
            $table->text('secret'); // For signature verification
            $table->string('url'); // Our endpoint URL
            $table->json('events'); // ['article.created', 'article.updated', 'article.stock-changed']
            $table->boolean('is_active')->default(true);
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();
            
            $table->index(['provider', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_configurations');
    }
};
```

**Run migration:**
```bash
php artisan migrate
```

---

## Phase 2: Webhook Registration Command (10 min)

**Create command:**

```bash
php artisan make:command RegisterRobawsWebhook
```

**File: `app/Console/Commands/RegisterRobawsWebhook.php`**

```php
<?php

namespace App\Console\Commands;

use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RegisterRobawsWebhook extends Command
{
    protected $signature = 'robaws:register-webhook 
                            {--url= : Webhook URL (defaults to app URL)}';
    
    protected $description = 'Register webhook endpoint with Robaws API';

    public function __construct(
        private RobawsApiClient $apiClient
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $environment = config('app.env');
        $webhookUrl = $this->option('url') ?? config('app.url') . '/api/webhooks/robaws/articles';
        
        $this->info("🔄 Registering webhook for {$environment} environment");
        $this->info("URL: {$webhookUrl}");
        
        // Check if already registered
        $existing = DB::table('webhook_configurations')
            ->where('provider', 'robaws')
            ->where('is_active', true)
            ->first();
            
        if ($existing) {
            $this->warn('⚠️  Webhook already registered!');
            $this->line("Webhook ID: {$existing->webhook_id}");
            $this->line("URL: {$existing->url}");
            
            if (!$this->confirm('Do you want to register a new webhook anyway?')) {
                return 0;
            }
        }
        
        try {
            // Register webhook with Robaws
            $result = $this->apiClient->registerWebhook([
                'url' => $webhookUrl,
                'events' => ['article.created', 'article.updated', 'article.stock-changed']
            ]);
            
            if ($result['success']) {
                $webhookId = $result['data']['id'] ?? null;
                $secret = $result['data']['secret'] ?? null;
                
                // Store in database
                DB::table('webhook_configurations')->insert([
                    'provider' => 'robaws',
                    'webhook_id' => $webhookId,
                    'secret' => $secret,
                    'url' => $webhookUrl,
                    'events' => json_encode(['article.created', 'article.updated', 'article.stock-changed']),
                    'is_active' => true,
                    'registered_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                
                $this->info("✅ Webhook registered successfully!");
                $this->line("Webhook ID: {$webhookId}");
                $this->newLine();
                $this->warn("⚠️  IMPORTANT: Add this to your .env file:");
                $this->line("ROBAWS_WEBHOOK_SECRET={$secret}");
                
                return 0;
            }
            
            $this->error('❌ Failed to register webhook');
            $this->line('Error: ' . ($result['error'] ?? 'Unknown error'));
            return 1;
            
        } catch (\Exception $e) {
            $this->error('❌ Exception: ' . $e->getMessage());
            return 1;
        }
    }
}
```

**Add `registerWebhook()` method to `RobawsApiClient`:**

```php
public function registerWebhook(array $payload): array
{
    try {
        $this->enforceRateLimit();
        
        $response = $this->getHttpClient()->post('/api/v2/webhook-endpoints', $payload);
        
        $this->updateRateLimitsFromResponse($response);

        if ($response->successful()) {
            return [
                'success' => true,
                'data' => $response->json(),
            ];
        }

        return [
            'success' => false,
            'error' => $response->body(),
            'status' => $response->status(),
        ];
    } catch (\Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
    }
}
```

---

## Phase 3: Webhook Controller (15 min)

**Create controller:**

```bash
php artisan make:controller Api/RobawsWebhookController
```

**File: `app/Http/Controllers/Api/RobawsWebhookController.php`**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Quotation\RobawsArticlesSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RobawsWebhookController extends Controller
{
    public function __construct(
        private RobawsArticlesSyncService $syncService
    ) {}
    
    public function handleArticle(Request $request)
    {
        // Step 1: Verify signature (CRITICAL for security)
        if (!$this->verifySignature($request)) {
            Log::warning('Invalid webhook signature', [
                'ip' => $request->ip(),
                'signature' => $request->header('Robaws-Signature'),
                'body' => $request->getContent()
            ]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }
        
        // Step 2: Parse event
        $event = $request->input('event'); // article.created, article.updated, article.stock-changed
        $data = $request->input('data'); // Full article data
        $webhookId = $request->input('id'); // Webhook event ID
        
        Log::info('Webhook received', [
            'event' => $event,
            'webhook_id' => $webhookId,
            'article_id' => $data['id'] ?? null
        ]);
        
        // Step 3: Process article update (synchronous)
        try {
            $this->syncService->processArticleFromWebhook($data, $event);
            
            Log::info('Webhook processed successfully', [
                'event' => $event,
                'article_id' => $data['id'] ?? null
            ]);
            
        } catch (\Exception $e) {
            Log::error('Webhook processing failed', [
                'event' => $event,
                'article_id' => $data['id'] ?? null,
                'error' => $e->getMessage()
            ]);
            
            // Still return 200 to prevent Robaws from retrying
            // Log the error for manual review
        }
        
        // Step 4: Return 2XX within 2 seconds (Robaws requirement)
        return response()->json(['status' => 'success'], 200);
    }
    
    private function verifySignature(Request $request): bool
    {
        $signatureHeader = $request->header('Robaws-Signature');
        
        if (!$signatureHeader) {
            return false;
        }
        
        // Get secret from database
        $config = DB::table('webhook_configurations')
            ->where('provider', 'robaws')
            ->where('is_active', true)
            ->first();
            
        if (!$config) {
            Log::error('No active webhook configuration found');
            return false;
        }
        
        $secret = $config->secret;
        
        // Parse signature header: "t=1674742714,v1=signature"
        parse_str(str_replace(',', '&', $signatureHeader), $parts);
        $timestamp = $parts['t'] ?? null;
        $receivedSignature = $parts['v1'] ?? null;
        
        if (!$timestamp || !$receivedSignature) {
            return false;
        }
        
        // Validate timestamp (reject if older than 5 minutes)
        $age = time() - (int)$timestamp;
        if ($age > 300) { // 5 minutes
            Log::warning('Webhook timestamp too old', ['age_seconds' => $age]);
            return false;
        }
        
        // Build signed payload: timestamp + '.' + body
        $payload = $timestamp . '.' . $request->getContent();
        
        // Compute expected signature
        $expectedSignature = hash_hmac('sha256', $payload, $secret);
        
        // Constant-time comparison
        return hash_equals($expectedSignature, $receivedSignature);
    }
}
```

**Add route in `routes/api.php`:**

```php
use App\Http\Controllers\Api\RobawsWebhookController;

// Robaws webhooks (no auth middleware - verified by signature)
Route::post('/webhooks/robaws/articles', [RobawsWebhookController::class, 'handleArticle'])
    ->name('webhooks.robaws.articles');
```

---

## Phase 4: Process Webhook Events (10 min)

**Add method to `RobawsArticlesSyncService.php`:**

```php
public function processArticleFromWebhook(array $articleData, string $event): void
{
    Log::info('Processing webhook event', [
        'event' => $event,
        'article_id' => $articleData['id'] ?? null,
        'article_name' => $articleData['name'] ?? null
    ]);
    
    // Webhook includes full article data - no API call needed!
    $this->processArticle($articleData, fetchFullDetails: false);
    
    // Extract metadata from the article name
    if (isset($articleData['id'])) {
        try {
            $this->articleProvider->syncArticleMetadata(
                $articleData['id'],
                useApi: false // Use webhook data, not API
            );
        } catch (\Exception $e) {
            Log::warning('Failed to sync metadata from webhook', [
                'article_id' => $articleData['id'],
                'error' => $e->getMessage()
            ]);
        }
    }
    
    Log::info('Webhook event processed successfully', [
        'event' => $event,
        'article_id' => $articleData['id'] ?? null
    ]);
}
```

---

## Phase 5: Optimize Incremental Sync (5 min)

**Update `syncIncremental()` in `RobawsArticlesSyncService.php`:**

Since webhooks handle real-time updates, incremental sync becomes a **safety net** only.

**Change from:**
```php
$this->processArticle($articleData, fetchFullDetails: true); // Makes API call
```

**To:**
```php
$this->processArticle($articleData, fetchFullDetails: false); // No API call
```

**Full updated loop:**
```php
// Process each modified article (NO API calls - webhooks handle real-time)
foreach ($articles as $articleData) {
    try {
        // Only process basic data, skip API call for extraFields
        $this->processArticle($articleData, fetchFullDetails: false);
        
        // Extract metadata from stored data
        $this->articleProvider->syncArticleMetadata(
            $articleData['id'],
            useApi: false
        );
        
        $synced++;
        
        if ($synced % 10 === 0) {
            Log::info('Incremental sync progress', [
                'processed' => $synced,
                'total' => count($articles),
                'note' => 'Fast sync - no API calls (webhooks handle real-time)'
            ]);
        }
    } catch (\RuntimeException $e) {
        // Handle daily quota exceeded
        if (str_contains($e->getMessage(), 'Daily API quota')) {
            Log::critical('Sync stopped: Daily API quota exhausted', [
                'synced' => $synced,
                'remaining' => count($articles) - $synced
            ]);
            break;
        }
        throw $e;
    } catch (\Exception $e) {
        $errors++;
        Log::warning('Failed to process modified article', [
            'article_id' => $articleData['id'] ?? 'unknown',
            'error' => $e->getMessage()
        ]);
    }
}
```

**Result:** Incremental sync becomes instant (no API calls per article)

---

## Phase 6: Deploy & Register (15 min)

### Step 1: Deploy to Production

**In Laravel Forge:**
1. Go to `app.belgaco.be` site
2. Click **"Deploy Now"**
3. Wait for deployment to complete

### Step 2: Register Webhook

**SSH into server or use Forge terminal:**

```bash
cd /home/forge/app.belgaco.be
php artisan robaws:register-webhook
```

**Output will show:**
```
✅ Webhook registered successfully!
Webhook ID: abc-123-def
⚠️  IMPORTANT: Add this to your .env file:
ROBAWS_WEBHOOK_SECRET=your_secret_here
```

### Step 3: Add Secret to .env

**In Laravel Forge → Environment tab:**

Add:
```env
ROBAWS_WEBHOOK_SECRET=your_secret_here
```

Click **"Save"**

### Step 4: Test Webhook

**Option A: Update an article in Robaws**
- Change article name/price in Robaws
- Check logs: `tail -f storage/logs/laravel.log | grep webhook`

**Option B: Use webhook.site for testing**
- Temporarily register webhook to webhook.site
- See what Robaws sends
- Re-register to production URL

---

## Expected Results

### Before Webhooks:
- Daily sync: 1,576 API calls, 3-4 minutes
- Data freshness: Up to 24 hours stale
- API quota usage: ~1,600 requests/day

### After Webhooks:
- Real-time updates: **0 API calls**, instant
- Data freshness: **Real-time (seconds)**
- API quota usage: **~50 requests/day** (backup sync only catches edge cases)
- Nightly sync: **<10 API calls** (only missed webhooks)

---

## Monitoring & Validation

### Check Webhook Activity:

```bash
# View webhook logs
tail -f storage/logs/laravel.log | grep -i webhook

# Count webhook events received today
grep "Webhook received" storage/logs/laravel.log | grep "$(date +%Y-%m-%d)" | wc -l

# Check for webhook errors
grep "Webhook processing failed" storage/logs/laravel.log | tail -n 20
```

### Verify Articles Are Syncing:

```bash
php artisan tinker
```

```php
// Check most recently updated articles
\App\Models\RobawsArticleCache::orderBy('updated_at', 'desc')->limit(5)->get(['article_name', 'updated_at']);

// Check webhook configuration
DB::table('webhook_configurations')->where('is_active', true)->first();
```

---

## Phase 7: Bi-Directional Sync (FUTURE - Not Implementing Now)

**Decision: Implementing webhooks ONLY (Robaws → Bconnect) first.**

Bi-directional sync (Bconnect → Robaws) will be added later if needed when:
- Users frequently edit articles in Filament
- Need to push local changes back to Robaws
- Want full two-way synchronization

**Implementation will include:**
- Model observer pattern
- `sync_source` column tracking
- Auto-push on user edits
- Webhook loop prevention
- Conflict resolution

---

## Implementation Checklist

- [ ] Phase 1: Create webhook_configurations migration
- [ ] Phase 2: Create RegisterRobawsWebhook command
- [ ] Phase 2: Add registerWebhook() to RobawsApiClient
- [ ] Phase 3: Create RobawsWebhookController
- [ ] Phase 3: Add webhook route in routes/api.php
- [ ] Phase 4: Add processArticleFromWebhook() to sync service
- [ ] Phase 5: Update incremental sync to skip API calls
- [ ] Phase 6: Deploy to production
- [ ] Phase 6: Register webhook with Robaws
- [ ] Phase 6: Add secret to .env
- [ ] Phase 6: Test and monitor

**Total Time: ~1 hour**

