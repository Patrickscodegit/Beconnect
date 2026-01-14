<?php

namespace App\Jobs;

use App\Models\QuotationCommodityItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecalculateQuotationArticles implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $quotationRequestId;
    public ?int $triggeringItemId;
    public int $uniqueFor;

    public function __construct(int $quotationRequestId, ?int $triggeringItemId = null)
    {
        $this->quotationRequestId = $quotationRequestId;
        $this->triggeringItemId = $triggeringItemId;
        $this->uniqueFor = (int) env('QUOTE_RECALC_DEBOUNCE_SECONDS', 0);
    }

    public function uniqueId(): string
    {
        return 'quotation-recalc-' . $this->quotationRequestId;
    }

    public function handle(): void
    {
        QuotationCommodityItem::recalculateQuotationArticles($this->quotationRequestId, $this->triggeringItemId);
    }
}
