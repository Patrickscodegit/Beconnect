<?php

namespace App\Services\Robaws;

use App\Models\QuotationRequest;
use App\Models\QuotationRequestArticle;
use App\Models\RobawsArticleCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RobawsOfferSyncService
{
    public function syncOffer(array $data): void
    {
        $offerId = $data['id'] ?? null;
        if (!$offerId) {
            Log::warning('Robaws offer sync skipped: missing offer id');
            return;
        }

        $quotation = QuotationRequest::where('robaws_offer_id', $offerId)->first();
        if (!$quotation) {
            Log::warning('Robaws offer sync skipped: quotation not found', [
                'offer_id' => $offerId,
            ]);
            return;
        }

        DB::transaction(function () use ($quotation, $data) {
            $updates = $this->buildQuotationUpdates($quotation, $data);
            if (!empty($updates)) {
                $quotation->update($updates);
            }

            if (!empty($data['lineItems']) && is_array($data['lineItems'])) {
                $this->syncLineItems($quotation, $data['lineItems']);
                $quotation->calculateTotals();
            }
        });
    }

    public function softDeleteOffer(array $data): void
    {
        $offerId = $data['id'] ?? null;
        if (!$offerId) {
            Log::warning('Robaws offer delete skipped: missing offer id');
            return;
        }

        $quotation = QuotationRequest::where('robaws_offer_id', $offerId)->first();
        if (!$quotation) {
            Log::warning('Robaws offer delete skipped: quotation not found', [
                'offer_id' => $offerId,
            ]);
            return;
        }

        if (method_exists($quotation, 'trashed') && $quotation->trashed()) {
            return;
        }

        $quotation->delete();

        Log::info('Quotation soft-deleted from Robaws delete webhook', [
            'quotation_id' => $quotation->id,
            'offer_id' => $offerId,
        ]);
    }

    private function buildQuotationUpdates(QuotationRequest $quotation, array $data): array
    {
        $offerNumber = $data['offerNumber'] ?? null;
        $number = $data['number'] ?? null;
        $incomingNumber = $offerNumber ?: $number;

        $updates = [
            'robaws_sync_status' => 'synced',
            'robaws_synced_at' => now(),
        ];

        if (!empty($incomingNumber)) {
            $updates['robaws_offer_number'] = $incomingNumber;
        } else {
            Log::warning('Robaws webhook missing offer number', [
                'quotation_id' => $quotation->id,
                'request_number' => $quotation->request_number,
                'offer_id' => $data['id'] ?? null,
                'status' => $data['status'] ?? null,
            ]);
        }

        if (!empty($data['clientId'])) {
            $updates['robaws_client_id'] = (int) $data['clientId'];
        }

        if (!empty($data['client']['name'])) {
            $updates['client_name'] = $data['client']['name'];
        }

        if (!empty($data['clientReference'])) {
            $updates['customer_reference'] = $data['clientReference'];
        }

        if (!empty($data['contactEmail'])) {
            $updates['contact_email'] = $data['contactEmail'];
        }

        $extraFields = $data['extraFields'] ?? [];
        if (!is_array($extraFields)) {
            $extraFields = [];
        }

        $labels = config('services.robaws.labels', []);
        $por = $this->getExtraFieldValue($extraFields, $labels['por'] ?? 'POR');
        $pol = $this->getExtraFieldValue($extraFields, $labels['pol'] ?? 'POL');
        $pod = $this->getExtraFieldValue($extraFields, $labels['pod'] ?? 'POD');
        $fdest = $this->getExtraFieldValue($extraFields, $labels['fdest'] ?? 'FDEST');
        $cargo = $this->getExtraFieldValue($extraFields, $labels['cargo'] ?? 'CARGO');
        $dim = $this->getExtraFieldValue($extraFields, $labels['dim_bef_delivery'] ?? 'DIM_BEF_DELIVERY');

        if ($por) {
            $updates['por'] = $por;
        }
        if ($pol) {
            $updates['pol'] = $pol;
        }
        if ($pod) {
            $updates['pod'] = $pod;
        }
        if ($fdest) {
            $updates['fdest'] = $fdest;
        }
        if ($cargo) {
            $updates['cargo_description'] = $cargo;
            $updates['robaws_cargo_field'] = $cargo;
        }
        if ($dim) {
            $updates['robaws_dim_field'] = $dim;
        }

        return $updates;
    }

    private function getExtraFieldValue(array $extraFields, string $label)
    {
        if (!array_key_exists($label, $extraFields)) {
            return null;
        }

        $node = $extraFields[$label];
        if (!is_array($node)) {
            return $node;
        }

        foreach (['stringValue', 'dateValue', 'decimalValue', 'integerValue', 'booleanValue'] as $key) {
            if (array_key_exists($key, $node) && $node[$key] !== null && $node[$key] !== '') {
                return $node[$key];
            }
        }

        return null;
    }

    private function syncLineItems(QuotationRequest $quotation, array $lineItems): void
    {
        $aggregated = [];
        foreach ($lineItems as $item) {
            $articleId = $item['articleId'] ?? $item['article']['id'] ?? null;
            if (!$articleId) {
                continue;
            }

            $quantity = (float) ($item['quantity'] ?? 1);
            $unitPrice = $item['unitPrice'] ?? $item['price'] ?? null;
            $currency = $item['currency'] ?? null;

            if (!isset($aggregated[$articleId])) {
                $aggregated[$articleId] = [
                    'quantity' => 0.0,
                    'unit_price' => $unitPrice,
                    'currency' => $currency,
                ];
            }

            $aggregated[$articleId]['quantity'] += $quantity;
            if ($unitPrice !== null) {
                $aggregated[$articleId]['unit_price'] = $unitPrice;
            }
            if ($currency !== null) {
                $aggregated[$articleId]['currency'] = $currency;
            }
        }

        if (empty($aggregated)) {
            return;
        }

        $articleCaches = RobawsArticleCache::whereIn('robaws_article_id', array_keys($aggregated))
            ->get()
            ->keyBy('robaws_article_id');

        QuotationRequestArticle::withoutEvents(function () use ($quotation, $aggregated, $articleCaches) {
            $existing = QuotationRequestArticle::where('quotation_request_id', $quotation->id)
                ->with('articleCache:id,robaws_article_id,unit_type,is_parent_item')
                ->get();

            $existingByRobaws = $existing->filter(fn ($item) => $item->articleCache?->robaws_article_id)
                ->keyBy(fn ($item) => (string) $item->articleCache->robaws_article_id);

            $allowedCacheIds = [];

            foreach ($aggregated as $robawsArticleId => $payload) {
                $cache = $articleCaches->get($robawsArticleId);
                if (!$cache) {
                    Log::warning('Robaws line item skipped: article not cached', [
                        'robaws_article_id' => $robawsArticleId,
                        'quotation_id' => $quotation->id,
                    ]);
                    continue;
                }

                $allowedCacheIds[] = $cache->id;
                $quantity = $payload['quantity'] ?: 1;
                $unitPrice = $payload['unit_price'] ?? null;
                $currency = $payload['currency'] ?? $quotation->pricing_currency ?? 'EUR';

                $existingItem = $existingByRobaws->get((string) $robawsArticleId);
                $attributes = [
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'selling_price' => $unitPrice,
                    'subtotal' => $unitPrice !== null ? $quantity * $unitPrice : null,
                    'currency' => $currency,
                    'unit_type' => $cache->unit_type,
                ];

                if ($existingItem) {
                    QuotationRequestArticle::where('id', $existingItem->id)->update($attributes);
                    continue;
                }

                $itemType = $cache->is_parent_item ? 'parent' : 'standalone';

                QuotationRequestArticle::create(array_merge($attributes, [
                    'quotation_request_id' => $quotation->id,
                    'article_cache_id' => $cache->id,
                    'item_type' => $itemType,
                    'parent_article_id' => null,
                ]));
            }

            if (!empty($allowedCacheIds)) {
                QuotationRequestArticle::where('quotation_request_id', $quotation->id)
                    ->whereNotIn('article_cache_id', $allowedCacheIds)
                    ->whereHas('articleCache', function ($query) {
                        $query->whereNotNull('robaws_article_id');
                    })
                    ->delete();
            }
        });
    }
}
