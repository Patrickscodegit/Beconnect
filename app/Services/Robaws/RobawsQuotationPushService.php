<?php

namespace App\Services\Robaws;

use App\Models\QuotationRequest;
use App\Services\Export\Clients\RobawsApiClient;
use App\Services\RobawsFieldGenerator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RobawsQuotationPushService
{
    public function __construct(
        private RobawsApiClient $apiClient,
        private RobawsFieldGenerator $fieldGenerator
    ) {}

    public function push(QuotationRequest $quotation, array $options = []): array
    {
        $quotation->loadMissing([
            'quotationRequestArticles.articleCache',
            'files',
            'selectedSchedule.carrier',
            'polPort',
            'podPort',
        ]);

        if (!$quotation->quotationRequestArticles()->exists()) {
            return [
                'success' => false,
                'error' => 'Quotation has no articles to push.',
            ];
        }

        if (!$quotation->robaws_cargo_field || !$quotation->robaws_dim_field) {
            $this->fieldGenerator->generateAndUpdateFields($quotation);
            $quotation->refresh();
        }

        $clientId = $this->resolveClientId($quotation);
        if (!$clientId) {
            $quotation->update([
                'robaws_sync_status' => 'failed',
                'robaws_synced_at' => now(),
            ]);

            return [
                'success' => false,
                'error' => 'Unable to resolve Robaws client.',
            ];
        }

        $this->syncContact($clientId, $quotation);

        $payload = $this->buildPayload($quotation, $clientId, $options);
        if (!empty($payload['success']) && $payload['success'] === false) {
            return $payload;
        }

        $payloadForRequest = $payload;
        if (!empty($options['minimal_update'])) {
            $fullOptions = $options;
            unset($fullOptions['minimal_update']);
            $payloadForRequest = $this->buildPayload($quotation, $clientId, $fullOptions);
            if (!empty($payloadForRequest['success']) && $payloadForRequest['success'] === false) {
                return $payloadForRequest;
            }
        }

        $idempotencyKey = $options['idempotency_key'] ?? $this->buildIdempotencyKey($quotation, $payloadForRequest);

        $action = $quotation->robaws_offer_id && !($options['create_new'] ?? false) ? 'update' : 'create';
        if ($action === 'update') {
            $result = $this->apiClient->updateQuotation((string) $quotation->robaws_offer_id, $payloadForRequest, $idempotencyKey);
        } else {
            $result = $this->apiClient->createQuotation($payloadForRequest, $idempotencyKey);
        }

        if (!($result['success'] ?? false)) {
            $quotation->update([
                'robaws_sync_status' => 'failed',
                'robaws_synced_at' => now(),
            ]);

            return [
                'success' => false,
                'error' => $result['error'] ?? 'Robaws push failed.',
            ];
        }

        $offerId = $result['quotation_id'] ?? data_get($result, 'data.id') ?? $quotation->robaws_offer_id;
        $offerNumber = data_get($result, 'data.offerNumber')
            ?? data_get($result, 'data.number')
            ?? data_get($result, 'data.offer_number');

        if ($offerId) {
            $updates = [
                'robaws_offer_id' => $offerId,
                'robaws_client_id' => $clientId,
                'robaws_sync_status' => 'synced',
                'robaws_synced_at' => now(),
            ];

            if (empty($offerNumber)) {
                $offerNumber = $this->fetchOfferNumberWithRetry((string) $offerId);
            }

            if (!empty($offerNumber)) {
                $updates['robaws_offer_number'] = $offerNumber;
            } else {
                Log::warning('Robaws offer number missing after retries', [
                    'quotation_id' => $quotation->id,
                    'request_number' => $quotation->request_number,
                    'offer_id' => $offerId,
                    'action' => $action,
                    'status' => data_get($result, 'data.status'),
                ]);
            }

            $quotation->update($updates);
        }

        $attachments = [
            'attempted' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        if (($options['include_attachments'] ?? true) && $offerId) {
            $attachments = $this->attachFiles($quotation, (int) $offerId);
        }

        return [
            'success' => true,
            'offer_id' => $offerId,
            'offer_number' => $offerNumber,
            'attachments' => $attachments,
        ];
    }

    private function fetchOfferNumberWithRetry(string $offerId, int $attempts = 3, int $delaySeconds = 1): ?string
    {
        for ($try = 1; $try <= $attempts; $try++) {
            $result = $this->apiClient->getOffer($offerId);
            if (!empty($result['success']) && !empty($result['data'])) {
                $data = $result['data'];
                $number = $data['logicId'] ?? $data['offerNumber'] ?? $data['number'] ?? null;
                if (!empty($number)) {
                    return $number;
                }
            }

            if ($try < $attempts) {
                sleep($delaySeconds);
            }
        }

        return null;
    }

    private function resolveClientId(QuotationRequest $quotation): ?int
    {
        if ($quotation->robaws_client_id) {
            return (int) $quotation->robaws_client_id;
        }

        $clientData = [
            'name' => $quotation->client_name ?? $quotation->contact_name,
            'email' => $quotation->client_email ?? $quotation->contact_email,
            'tel' => $quotation->client_tel ?? $quotation->contact_phone,
        ];

        $client = $this->apiClient->findOrCreateClient($clientData);

        return isset($client['id']) ? (int) $client['id'] : null;
    }

    private function syncContact(int $clientId, QuotationRequest $quotation): void
    {
        if (!$quotation->contact_name && !$quotation->contact_email) {
            return;
        }

        $contactData = [
            'first_name' => $quotation->contact_name,
            'email' => $quotation->contact_email,
            'tel' => $quotation->contact_phone,
            'function' => $quotation->contact_function,
        ];

        try {
            $contactId = $this->apiClient->findOrCreateClientContactId($clientId, $contactData);
            if (!$contactId) {
                Log::warning('Robaws contact sync returned no contact ID', [
                    'quotation_id' => $quotation->id,
                    'client_id' => $clientId,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Robaws contact sync failed', [
                'quotation_id' => $quotation->id,
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildPayload(QuotationRequest $quotation, int $clientId, array $options = []): array
    {
        $labels = config('services.robaws.labels', []);
        $extraFields = [];

        $put = function (string $key, $value, string $type = 'stringValue') use (&$extraFields, $labels): void {
            if ($value === null || $value === '') {
                return;
            }

            $label = $labels[$key] ?? $key;
            $extraFields[$label] = [$type => $value];
        };

        $pol = $quotation->polPort?->getDisplayName() ?? $quotation->pol;
        $pod = $quotation->podPort?->getDisplayName() ?? $quotation->pod;
        $cargoField = $quotation->robaws_cargo_field ?? $quotation->cargo_description;
        $dimField = $quotation->robaws_dim_field;

        $put('customer', $quotation->client_name ?? $quotation->contact_name);
        $put('contact', $quotation->contact_name);
        $put('customer_reference', $quotation->customer_reference ?? $quotation->request_number);
        $put('por', $quotation->por);
        $put('pol', $pol);
        $put('pod', $pod);
        $put('fdest', $quotation->fdest);
        $put('cargo', $cargoField);
        $put('dim_bef_delivery', $dimField);

        if ($quotation->selectedSchedule) {
            $schedule = $quotation->selectedSchedule;
            if ($schedule->carrier?->name) {
                $put('shipping_line', $schedule->carrier->name);
            }
            if ($schedule->vessel_name) {
                $extraFields['VESSEL'] = ['stringValue' => $schedule->vessel_name];
            }
            if ($schedule->voyage_number) {
                $extraFields['VOYAGE'] = ['stringValue' => $schedule->voyage_number];
            }
            if ($schedule->ets_pol) {
                $extraFields['ETS'] = ['dateValue' => $schedule->ets_pol->format('Y-m-d')];
            }
            if ($schedule->eta_pod) {
                $extraFields['ETA'] = ['dateValue' => $schedule->eta_pod->format('Y-m-d')];
            }
            if ($schedule->transit_days !== null) {
                $extraFields['TRANSIT_TIME'] = ['stringValue' => $schedule->transit_days . ' days'];
            }
        }

        $lineItems = [];
        foreach ($quotation->quotationRequestArticles as $item) {
            $articleId = $item->articleCache?->robaws_article_id;
            if (!$articleId) {
                Log::warning('Robaws push skipped article without robaws_article_id', [
                    'quotation_id' => $quotation->id,
                    'article_cache_id' => $item->article_cache_id,
                ]);
                continue;
            }

            $lineItems[] = [
                'articleId' => (int) $articleId,
                'quantity' => max(1, (int) $item->quantity),
            ];
        }
        
        if (empty($lineItems)) {
            return [
                'success' => false,
                'error' => 'Quotation has no Robaws-mapped articles to push.',
            ];
        }

        if (!empty($options['minimal_update'])) {
            return [
                'companyId' => config('services.robaws.default_company_id', config('services.robaws.company_id', 1)),
                'customerId' => $clientId,
                'clientId' => $clientId,
                'lineItems' => $lineItems,
                'extraFields' => $extraFields,
            ];
        }

        return [
            'title' => $quotation->request_number ?? $quotation->customer_reference,
            'project' => $quotation->request_number,
            'clientReference' => $quotation->customer_reference ?? $quotation->request_number,
            'contactEmail' => $quotation->contact_email,
            'customerId' => $clientId,
            'clientId' => $clientId,
            'companyId' => config('services.robaws.default_company_id', config('services.robaws.company_id', 1)),
            'currency' => $quotation->pricing_currency ?? 'EUR',
            'status' => 'Draft',
            'externalId' => 'bconnect_quotation_' . $quotation->id,
            'lineItems' => $lineItems,
            'extraFields' => $extraFields,
        ];
    }

    private function buildIdempotencyKey(QuotationRequest $quotation, array $payload): string
    {
        $hash = md5(json_encode($payload));
        return 'quotation_' . $quotation->id . '_' . $hash;
    }

    private function attachFiles(QuotationRequest $quotation, int $offerId): array
    {
        $result = [
            'attempted' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($quotation->files as $file) {
            $result['attempted']++;

            $filename = $file->original_filename ?: $file->filename;
            $response = $this->apiClient->attachFileToOffer($offerId, $file->file_path, $filename);

            if ($response['success'] ?? false) {
                $result['succeeded']++;
                continue;
            }

            $result['failed']++;
            $result['errors'][] = $response['error'] ?? 'Unknown attachment error';
        }

        return $result;
    }
}
