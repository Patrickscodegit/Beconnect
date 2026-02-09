<?php

namespace App\Jobs;

use App\Models\QuotationRequest;
use App\Services\Robaws\RobawsQuotationPushService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushQuotationToRobawsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private int $quotationId) {}

    public function handle(RobawsQuotationPushService $service): void
    {
        $quotation = QuotationRequest::find($this->quotationId);
        if (!$quotation) {
            return;
        }

        if ($quotation->robaws_offer_id) {
            return;
        }

        $articleCount = $quotation->quotationRequestArticles()->count();
        $lastCreatedAt = $quotation->quotationRequestArticles()->max('created_at');
        $maxAttempts = 5;

        if ($articleCount === 0) {
            if ($this->attempts() < $maxAttempts) {
                Log::info('Auto-push delayed: no articles yet', [
                    'quotation_id' => $quotation->id,
                    'attempt' => $this->attempts(),
                ]);
                $this->release(10);
            }
            return;
        }

        if ($lastCreatedAt) {
            $ageSeconds = now()->diffInSeconds(Carbon::parse($lastCreatedAt));
            if ($ageSeconds < 5 && $this->attempts() < $maxAttempts) {
                Log::info('Auto-push delayed: articles still settling', [
                    'quotation_id' => $quotation->id,
                    'article_count' => $articleCount,
                    'age_seconds' => $ageSeconds,
                    'attempt' => $this->attempts(),
                ]);
                $this->release(10);
                return;
            }
        }

        try {
            $result = $service->push($quotation, ['include_attachments' => true]);

            if (!($result['success'] ?? false)) {
                $error = $result['error'] ?? 'Robaws push failed';
                Log::warning('Auto-push returned an error', [
                    'quotation_id' => $quotation->id,
                    'error' => $error,
                ]);

                if (str_contains(strtolower($error), 'no articles')) {
                    $this->release(120);
                    return;
                }
            }
        } catch (\Throwable $e) {
            Log::error('Auto-push to Robaws failed', [
                'quotation_id' => $quotation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
