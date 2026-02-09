<?php

namespace App\Jobs;

use App\Models\QuotationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AuditRobawsOfferLinksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $missing = QuotationRequest::query()
            ->whereNull('robaws_offer_id')
            ->where('created_at', '>=', now()->subDay())
            ->whereIn('status', ['pending', 'processing', 'quoted'])
            ->get(['id', 'request_number', 'status', 'created_at']);

        if ($missing->isEmpty()) {
            Log::info('Robaws audit: no missing offers in last 24h');
            return;
        }

        Log::warning('Robaws audit: missing offers detected', [
            'count' => $missing->count(),
            'quotations' => $missing->map(fn ($item) => [
                'id' => $item->id,
                'request_number' => $item->request_number,
                'status' => $item->status,
                'created_at' => $item->created_at?->toDateTimeString(),
            ])->all(),
        ]);
    }
}
