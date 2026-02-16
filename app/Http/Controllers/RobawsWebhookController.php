<?php

namespace App\Http\Controllers;

use App\Models\RobawsWebhookLog;
use App\Models\QuotationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RobawsWebhookController extends Controller
{
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

            match($event) {
                'offer.created' => $this->handleOfferCreated($data),
                'offer.updated' => $this->handleOfferUpdated($data),
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
        $quotation = QuotationRequest::where('robaws_offer_id', $data['id'])->first();

        if ($quotation) {
            $offerNumber = $data['offerNumber'] ?? null;
            $number = $data['number'] ?? null;
            $logicId = $data['logicId'] ?? null;
            $incomingNumber = $offerNumber ?: $number ?: $logicId;

            $quotation->update([
                'robaws_offer_number' => $incomingNumber ?? $quotation->robaws_offer_number,
                'robaws_sync_status' => 'synced',
                'robaws_synced_at' => now()
            ]);

            Log::info('Quotation synced from Robaws webhook', [
                'quotation_id' => $quotation->id,
                'offer_id' => $data['id']
            ]);
        }
    }

    /**
     * Handle offer created event
     */
    private function handleOfferCreated(array $data): void
    {
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
}
