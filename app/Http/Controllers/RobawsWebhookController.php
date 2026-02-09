<?php

namespace App\Http\Controllers;

use App\Models\RobawsWebhookLog;
use App\Models\QuotationRequest;
use App\Jobs\SyncRobawsOfferJob;
use App\Services\Robaws\RobawsOfferSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RobawsWebhookController extends Controller
{
    public function __construct(private RobawsOfferSyncService $offerSyncService) {}

    /**
     * Handle incoming webhooks from Robaws
     * Built now, enabled when Robaws approves webhooks
     */
    public function handle(Request $request)
    {
        // Check if webhooks are enabled
        if (!config('quotation.sync.webhooks_enabled', false)) {
            Log::warning('Webhook received but webhooks not enabled', [
                'event' => $request->input('event'),
                'robaws_id' => $request->input('data.id')
            ]);

            return response()->json([
                'status' => 'webhooks_not_enabled',
                'message' => 'Webhooks are not yet enabled. Please enable ROBAWS_WEBHOOKS_ENABLED in .env'
            ], 503);
        }

        // Verify webhook signature
        if (!$this->verifySignature($request)) {
            Log::warning('Invalid offer webhook signature', [
                'ip' => $request->ip(),
                'signature' => $request->header('Robaws-Signature'),
            ]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // Log webhook receipt
        $webhookLog = RobawsWebhookLog::create([
            'event_type' => $request->input('event'),
            'robaws_id' => $request->input('data.id'),
            'payload' => $request->all(),
            'status' => 'received'
        ]);

        try {
            // Update status to processing
            $webhookLog->update(['status' => 'processing']);

            // Handle event
            $event = $request->input('event');
            $data = $request->input('data');

            if (!$event || !is_array($data)) {
                Log::warning('Robaws webhook missing event or data', [
                    'event' => $event,
                    'data_type' => gettype($data),
                ]);
            }

            match($event) {
                'offer.created' => $this->handleOfferCreated($data),
                'offer.updated' => $this->handleOfferUpdated($data),
                'offer.recalculated' => $this->handleOfferUpdated($data),
                'offer.deleted' => $this->handleOfferDeleted($data),
                'offer.status_changed' => $this->handleOfferStatusChanged($data),
                'project.created' => $this->handleProjectCreated($data),
                'project.updated' => $this->handleProjectUpdated($data),
                'project.status_changed' => $this->handleProjectStatusChanged($data),
                'invoice.created' => $this->handleInvoiceCreated($data),
                'document.uploaded' => $this->handleDocumentUploaded($data),
                'article.updated' => $this->handleArticleUpdated($data),
                default => $this->handleUnknownEvent($event, $data)
            };

            // Mark as processed
            $webhookLog->markAsProcessed();

            return response()->json(['status' => 'processed'], 200);

        } catch (\Exception $e) {
            // Mark as failed
            $webhookLog->markAsFailed($e->getMessage());

            Log::error('Webhook processing failed', [
                'webhook_id' => $webhookLog->id,
                'event' => $request->input('event'),
                'error' => $e->getMessage()
            ]);

            // Still return 200 to prevent Robaws from retrying indefinitely
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 200);
        }
    }

    /**
     * Handle offer updated event
     */
    private function handleOfferUpdated(array $data): void
    {
        SyncRobawsOfferJob::dispatch('offer.updated', $data)->afterCommit();

        Log::info('Quotation synced from Robaws webhook', [
            'offer_id' => $data['id'] ?? null
        ]);
    }

    /**
     * Handle offer created event
     */
    private function handleOfferCreated(array $data): void
    {
        SyncRobawsOfferJob::dispatch('offer.created', $data)->afterCommit();

        Log::info('Offer created webhook received', [
            'offer_id' => $data['id']
        ]);

        // Could be used to auto-create quotation request if needed
    }

    /**
     * Handle offer status changed event
     */
    private function handleOfferStatusChanged(array $data): void
    {
        if (($data['status'] ?? null) === 'deleted') {
            SyncRobawsOfferJob::dispatch('offer.deleted', $data)->afterCommit();
        }

        $quotation = QuotationRequest::where('robaws_offer_id', $data['id'])->first();

        if ($quotation) {
            // Update quotation status based on offer status
            // This mapping would need to be defined based on Robaws statuses
            Log::info('Offer status changed', [
                'quotation_id' => $quotation->id,
                'new_status' => $data['status'] ?? 'unknown'
            ]);
        }
    }

    private function handleOfferDeleted(array $data): void
    {
        SyncRobawsOfferJob::dispatch('offer.deleted', $data)->afterCommit();
    }

    /**
     * Handle project updated event (for shipments)
     */
    private function handleProjectUpdated(array $data): void
    {
        // Will be implemented in Phase 11 for shipment tracking
        Log::info('Project updated webhook received', [
            'project_id' => $data['id']
        ]);
    }

    /**
     * Handle project created event
     */
    private function handleProjectCreated(array $data): void
    {
        Log::info('Project created webhook received', [
            'project_id' => $data['id']
        ]);
    }

    /**
     * Handle project status changed event
     */
    private function handleProjectStatusChanged(array $data): void
    {
        Log::info('Project status changed webhook received', [
            'project_id' => $data['id'],
            'new_status' => $data['status'] ?? 'unknown'
        ]);
    }

    /**
     * Handle invoice created event
     */
    private function handleInvoiceCreated(array $data): void
    {
        Log::info('Invoice created webhook received', [
            'invoice_id' => $data['id']
        ]);
    }

    /**
     * Handle document uploaded event
     */
    private function handleDocumentUploaded(array $data): void
    {
        Log::info('Document uploaded webhook received', [
            'document_id' => $data['id']
        ]);
    }

    /**
     * Handle article updated event
     */
    private function handleArticleUpdated(array $data): void
    {
        $article = \App\Models\RobawsArticleCache::where('robaws_article_id', $data['id'])->first();

        if ($article) {
            $article->update([
                'article_code' => $data['code'] ?? $article->article_code,
                'article_name' => $data['name'] ?? $article->article_name,
                'description' => $data['description'] ?? $article->description,
                'unit_price' => $data['unitPrice'] ?? $article->unit_price,
                'is_active' => $data['isActive'] ?? $article->is_active,
                'last_synced_at' => now()
            ]);

            Log::info('Article updated from webhook', [
                'article_id' => $article->id,
                'robaws_article_id' => $data['id']
            ]);
        }
    }

    /**
     * Handle unknown event
     */
    private function handleUnknownEvent(string $event, array $data): void
    {
        Log::warning('Unknown webhook event received', [
            'event' => $event,
            'data' => $data
        ]);
    }

    private function verifySignature(Request $request, string $provider = 'robaws'): bool
    {
        $signatureHeader = $request->header('Robaws-Signature');

        if (!$signatureHeader) {
            return false;
        }

        $config = DB::table('webhook_configurations')
            ->where('provider', $provider)
            ->where('is_active', true)
            ->first();

        if (!$config) {
            Log::error('No active webhook configuration found', ['provider' => $provider]);
            return false;
        }

        $secret = $config->secret;

        parse_str(str_replace(',', '&', $signatureHeader), $parts);
        $timestamp = $parts['t'] ?? null;
        $receivedSignature = $parts['v1'] ?? null;

        if (!$timestamp || !$receivedSignature) {
            return false;
        }

        $age = time() - (int) $timestamp;
        if ($age > 300) {
            Log::warning('Webhook timestamp too old', ['age_seconds' => $age]);
            return false;
        }

        $payload = $timestamp . '.' . $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $receivedSignature);
    }
}
