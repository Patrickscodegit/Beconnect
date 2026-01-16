# Articles Sync Audit

## Scope
Full-stack audit of Robaws article sync: UI triggers, jobs, CLI, API client, DB/cache, and progress UI.

## Entry Points
- UI: `Full Sync` / `Quick Sync` / `Sync Extra Fields` in `ListRobawsArticles`.
- CLI: `php artisan robaws:sync-articles` with `--incremental`, `--metadata-only`, `--sync-now`.
- Webhooks: `RobawsArticlesSyncService::processArticleFromWebhook`.

## Core Data Flow
1. **Full Sync (UI)**
   - Dispatches `RobawsArticlesFullSyncJob`.
   - Job runs `RobawsArticlesSyncService::sync()` (page-by-page).
   - Builds metadata chunk jobs (`SyncArticlesMetadataChunkJob`).
   - Dispatches extra-fields dispatcher (`DispatchArticleExtraFieldsSyncJobs`), which in turn enqueues `SyncSingleArticleMetadataJob` per article.
2. **Quick Sync (UI)**
   - Runs `syncIncremental()` synchronously (still in-request).
3. **Extra Fields (UI)**
   - Queues `DispatchArticleExtraFieldsSyncJobs`.

## Data Storage / Tables
- `robaws_articles_cache`: article cache with derived metadata (pol/pod, shipping_line, etc.).
- `jobs` / `failed_jobs`: queue state (database driver).
- `job_batches`: batch tracking (full sync).
- `cache` (database): `robaws:articles:full_sync_running` and `robaws:articles:full_sync_batch_id`.

## Key Components
- **UI**: `ListRobawsArticles`, `ArticleSyncProgress`.
- **Sync Service**: `RobawsArticlesSyncService`.
- **API Client**: `RobawsApiClient`.
- **Metadata Provider**: `RobawsArticleProvider`.
- **Jobs**: `RobawsArticlesFullSyncJob`, `SyncArticlesMetadataChunkJob`, `DispatchArticleExtraFieldsSyncJobs`, `SyncSingleArticleMetadataJob`.

## Rate Limiting / API Behavior
`RobawsApiClient` enforces rate limits and retries 429s. Full sync uses list API and conditional detail fetch if `extraFields` missing. Metadata jobs call API only if needed or forced.

## Progress Tracking
`ArticleSyncProgress` uses:
- `job_batches` for batch stats (full sync).
- `jobs` table counts for queued items.
- Field population metrics from `robaws_articles_cache`.

## Observed Issues
1. **Progress page error when cache is empty**
   - Division by zero in percentage calculations when `robaws_articles_cache` is empty.
2. **“Full sync is running” with no progress**
   - Running flag set without an active batch or queued jobs (stale cache).
   - `job_batches` rows may exist with `total_jobs = 0` (stale entry), which keeps UI in “running” state with no progress.
3. **No job activity even after Full Sync**
   - Full sync job may not be running if queue worker is not active.
4. **Progress visibility gap**
   - Batch status only visible when batch jobs exist; no explicit “worker not running” state.

## Recommendations (Solution Proposal)
1. **Make sync state robust**
   - Clear stale running flag when no batch/jobs are present.
   - Treat `job_batches.total_jobs = 0` as “not running”.
2. **Harden progress UI**
   - Guard all percentage calculations when `total = 0`.
   - Add status text when no jobs are queued but running flag is set.
3. **Add worker visibility**
   - Add a small “Queue Worker Status” card (last job processed time, queue size).
4. **Write explicit sync logs**
   - Add/update `RobawsSyncLog` entries for start/end and errors.
5. **Fail fast when queue is disabled**
   - Detect `QUEUE_CONNECTION=sync` or missing `jobs` table and warn in UI.
6. **Reduce UI blocking for Quick Sync**
   - Optionally queue `syncIncremental()` for large changes, keeping UI responsive.

## Suggested Next Steps
- Apply the above recommendations, focusing on progress UI resilience and queue/worker diagnostics.
- Add a “Reset Sync State” admin action (clears running flag + batch ID).

