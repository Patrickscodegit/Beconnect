<?php

namespace App\Jobs;

use App\Services\Robaws\RobawsOfferSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncRobawsOfferJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private string $event,
        private array $data
    ) {}

    public function handle(RobawsOfferSyncService $syncService): void
    {
        if (in_array($this->event, ['offer.updated', 'offer.recalculated', 'offer.created'], true)) {
            $syncService->syncOffer($this->data);
            return;
        }

        if ($this->event === 'offer.deleted') {
            $syncService->softDeleteOffer($this->data);
            return;
        }

        Log::warning('SyncRobawsOfferJob received unsupported event', [
            'event' => $this->event,
        ]);
    }
}
