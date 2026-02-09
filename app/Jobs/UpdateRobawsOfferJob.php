<?php

namespace App\Jobs;

use App\Models\QuotationRequest;
use App\Services\Robaws\RobawsQuotationPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateRobawsOfferJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private int $quotationId) {}

    public function handle(RobawsQuotationPushService $service): void
    {
        $quotation = QuotationRequest::find($this->quotationId);
        if (!$quotation || !$quotation->robaws_offer_id) {
            return;
        }

        $articleCount = $quotation->quotationRequestArticles()->count();
        if ($articleCount < 2) {
            $this->release(30);
            return;
        }

        try {
            $result = $service->push($quotation, [
                'include_attachments' => true,
                'create_new' => false,
            ]);

            if (!($result['success'] ?? false)) {
                Log::warning('Robaws offer update failed', [
                    'quotation_id' => $quotation->id,
                    'offer_id' => $quotation->robaws_offer_id,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Robaws offer update exception', [
                'quotation_id' => $quotation->id,
                'offer_id' => $quotation->robaws_offer_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
