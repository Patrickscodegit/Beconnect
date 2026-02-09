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
        $mappedArticleCount = $quotation->quotationRequestArticles()
            ->whereHas('articleCache', function ($query) {
                $query->whereNotNull('robaws_article_id');
            })
            ->count();
        $lastCreatedAt = $quotation->quotationRequestArticles()->max('created_at');
        $articleCountStable = $quotation->quotationRequestArticles()
            ->where('created_at', '<=', now()->subSeconds(2))
            ->count();
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

        if ($mappedArticleCount < 2 && $this->attempts() < $maxAttempts) {
            Log::info('Auto-push delayed: waiting for mapped articles', [
                'quotation_id' => $quotation->id,
                'article_count' => $articleCount,
                'mapped_article_count' => $mappedArticleCount,
                'attempt' => $this->attempts(),
            ]);
            $this->release(10);
            return;
        }

        if ($lastCreatedAt) {
            $lastCreatedUtc = Carbon::parse($lastCreatedAt)->utc();
            $nowUtc = now()->utc();
            $ageSeconds = max(0, $nowUtc->diffInSeconds($lastCreatedUtc, false));

            if ($this->attempts() === 1 && $ageSeconds === 0) {
                $dbNow = \DB::selectOne('select now() as now');
                Log::info('Auto-push timestamp comparison (UTC)', [
                    'quotation_id' => $quotation->id,
                    'db_now' => $dbNow?->now ?? null,
                    'app_now_utc' => $nowUtc->toDateTimeString(),
                    'last_created_utc' => $lastCreatedUtc->toDateTimeString(),
                ]);
            }

            if ($ageSeconds < 5 && $this->attempts() < $maxAttempts && $articleCountStable === 0) {
                Log::info('Auto-push delayed: articles still settling', [
                    'quotation_id' => $quotation->id,
                    'article_count' => $articleCount,
                    'stable_count' => $articleCountStable,
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
