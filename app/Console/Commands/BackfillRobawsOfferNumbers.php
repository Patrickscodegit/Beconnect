<?php

namespace App\Console\Commands;

use App\Models\QuotationRequest;
use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackfillRobawsOfferNumbers extends Command
{
    protected $signature = 'robaws:backfill-offer-numbers {--limit= : Max quotations to process} {--dry-run : Do not persist updates}';
    protected $description = 'Backfill robaws_offer_number using Robaws offer details (logicId/offerNumber/number).';

    public function handle(RobawsApiClient $apiClient): int
    {
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $dryRun = (bool) $this->option('dry-run');

        $query = QuotationRequest::query()
            ->whereNotNull('robaws_offer_id')
            ->where(function ($q) {
                $q->whereNull('robaws_offer_number')->orWhere('robaws_offer_number', '');
            })
            ->orderBy('id');

        if ($limit) {
            $query->limit($limit);
        }

        $count = 0;
        $updated = 0;

        $query->chunkById(50, function ($rows) use ($apiClient, $dryRun, &$count, &$updated) {
            foreach ($rows as $quotation) {
                $count++;
                $offerId = (string) $quotation->robaws_offer_id;
                $result = $apiClient->getOffer($offerId);
                if (empty($result['success']) || empty($result['data'])) {
                    Log::warning('Backfill Robaws offer number failed to fetch offer', [
                        'quotation_id' => $quotation->id,
                        'offer_id' => $offerId,
                        'error' => $result['error'] ?? null,
                    ]);
                    continue;
                }

                $data = $result['data'];
                $number = $data['logicId'] ?? $data['offerNumber'] ?? $data['number'] ?? null;
                if (!$number) {
                    continue;
                }

                if ($dryRun) {
                    $this->line("Would update quotation {$quotation->id} with {$number}");
                    $updated++;
                    continue;
                }

                $quotation->update(['robaws_offer_number' => $number]);
                $updated++;
            }
        });

        $this->info("Processed {$count} quotations; updated {$updated}.");

        return self::SUCCESS;
    }
}
