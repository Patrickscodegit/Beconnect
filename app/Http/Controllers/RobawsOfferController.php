<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\RobawsClient;
use App\Services\RobawsIntegrationService;
use App\Services\MultiDocumentUploadService;
use App\Services\RobawsIntegration\JsonFieldMapper;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class RobawsOfferController extends Controller
{
    public function __construct(
        private RobawsIntegrationService $robawsService,
        private RobawsClient $robawsClient,
        private MultiDocumentUploadService $uploadService,
        private JsonFieldMapper $fieldMapper
    ) {}

    /**
     * Create an offer in Robaws with extracted JSON data using CREATE → GET → PUT pattern
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'extracted_json' => 'required|json',
            'client_data' => 'sometimes|array',
            'title' => 'string|max:255',
            'date' => 'sometimes|date',
            'currency' => 'sometimes|string|size:3',
            'companyId' => 'sometimes|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $extractedData = json_decode($request->extracted_json, true);
            
            // 1) Map with our JSON Field Mapper
            $mapped = $this->fieldMapper->mapFields($extractedData);

            // Create offer using the two-step pattern
            $clientData = $this->extractClientData($extractedData, $request->client_data ?? []);
            $client = $this->robawsClient->findOrCreateClient($clientData);
            
            // 2) Minimal create (do NOT send extraFields here)
            $createPayload = [
                'title' => $request->title ?? 
                          $extractedData['title'] ?? 
                          $extractedData['description'] ?? 
                          'Offer from extracted document',
                'date' => $request->date ?? 
                         $extractedData['date'] ?? 
                         now()->format('Y-m-d'),
                'clientId' => $client['id'],
                'currency' => $request->currency ?? 
                             $extractedData['currency'] ?? 
                             'EUR',
                'companyId' => $request->companyId ?? config('services.robaws.default_company_id'),
                'status' => 'Draft',
            ];
            
            Log::info('Robaws API Request - Create Offer (store)', ['payload' => $createPayload]);
            $offer = $this->robawsClient->createOffer($createPayload);
            $offerId = $offer['id'] ?? null;
            if (!$offerId) {
                throw new \RuntimeException('Robaws createOffer returned no id');
            }

            // 3) GET → merge extraFields → PUT
            $remote = $this->robawsClient->getOffer($offerId);
            $payload = $this->stripOfferReadOnly($remote);
            $payload['extraFields'] = array_merge(
                $remote['extraFields'] ?? [],
                $this->buildExtraFieldsFromMapped($mapped)
            );

            Log::info('Robaws API Request - Update Offer (store)', [
                'offer_id' => $offerId,
                'labels'   => array_keys($payload['extraFields']),
            ]);

            $this->robawsClient->updateOffer($offerId, $payload);
            
            Log::info('Robaws offer created successfully via API store endpoint', [
                'offer_id' => $offerId,
                'client_id' => $client['id']
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Offer created successfully in Robaws with custom fields',
                'data' => [
                    'offer' => ['id' => $offerId],
                    'client' => $client
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create Robaws offer via API', [
                'error' => $e->getMessage(),
                'extracted_json' => $request->extracted_json
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create offer: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a Robaws offer from a document using CREATE → GET → PUT pattern
     */
    public function createFromDocument(Request $request, Document $document)
    {
        try {
            // Ensure user owns the document
            if ($document->user_id !== auth()->id()) {
                return response()->json([
                    'message' => 'Unauthorized access to document'
                ], 403);
            }
            
            // Check if already has a Robaws offer
            if ($document->robaws_quotation_id) {
                return response()->json([
                    'message' => 'Document already has a Robaws offer',
                    'offer_id' => $document->robaws_quotation_id,
                    'robaws_url' => config('services.robaws.base_url') . '/offers/' . $document->robaws_quotation_id
                ], 409);
            }
            
            // Get extraction data
            $extraction = $document->extractions()->first();
            $extractedData = null;
            
            if ($extraction && $extraction->raw_json) {
                $extractedData = json_decode($extraction->raw_json, true);
            } elseif ($document->raw_json) {
                $extractedData = json_decode($document->raw_json, true);
            } else {
                $extractedData = $document->extraction_data;
            }
            
            if (empty($extractedData)) {
                return response()->json([
                    'message' => 'Document has no extraction data. Please wait for AI processing to complete.'
                ], 400);
            }

            // Ensure data is array format
            if (is_string($extractedData)) {
                $extractedData = json_decode($extractedData, true);
            }

            Log::info('RobawsOfferController: Processing document with field mapper', [
                'document_id' => $document->id,
                'extraction_data_keys' => array_keys($extractedData)
            ]);

            // 1) Map with our JSON Field Mapper
            $mapped = $this->fieldMapper->mapFields($extractedData);

            Log::info('RobawsOfferController: Field mapping completed', [
                'document_id' => $document->id,
                'mapped_fields' => array_keys($mapped),
                'has_routing' => !empty($mapped['por']) || !empty($mapped['pol']) || !empty($mapped['pod']),
                'has_cargo' => !empty($mapped['cargo'])
            ]);

            // Create client
            $clientData = $this->extractClientData($extractedData);
            $client = $this->robawsClient->findOrCreateClient($clientData);

            // 2) Minimal create (do NOT send extraFields here)
            $createPayload = [
                'title'     => $extractedData['title'] ?? "Offer from {$document->original_filename}",
                'date'      => $extractedData['date'] ?? $document->created_at->format('Y-m-d'),
                'clientId'  => $client['id'],
                'currency'  => $extractedData['currency'] ?? 'EUR',
                'companyId' => config('services.robaws.default_company_id'),
                'status'    => 'Draft',
            ];

            Log::info('RobawsOfferController: Create offer (minimal)', ['payload' => $createPayload]);

            $offer = $this->robawsClient->createOffer($createPayload);
            $offerId = $offer['id'] ?? null;
            if (!$offerId) {
                throw new \RuntimeException('Robaws createOffer returned no id');
            }

            // 3) GET → merge extraFields → PUT (this makes custom fields stick)
            $remote = $this->robawsClient->getOffer($offerId);
            $payload = $this->stripOfferReadOnly($remote);
            $payload['extraFields'] = array_merge(
                $remote['extraFields'] ?? [],
                $this->buildExtraFieldsFromMapped($mapped)
            );

            Log::info('RobawsOfferController: Update offer with extraFields', [
                'offer_id' => $offerId,
                'labels'   => array_keys($payload['extraFields']),
            ]);

            $this->robawsClient->updateOffer($offerId, $payload); // 200/204 on success

            // 4) Save refs + upload file
            $document->update([
                'robaws_quotation_id' => $offerId,
                'robaws_client_id'    => $client['id'] ?? null,
            ]);

            // Upload the document file to Robaws
            $fileUploadResult = null;
            try {
                $fileUploadResult = $this->uploadService->uploadDocumentToQuotation($document);
                Log::info('Document file uploaded to Robaws', [
                    'document_id'   => $document->id,
                    'upload_result' => $fileUploadResult
                ]);
            } catch (\Exception $uploadException) {
                Log::warning('File upload failed, but quotation created successfully', [
                    'document_id'  => $document->id,
                    'upload_error' => $uploadException->getMessage()
                ]);
            }

            return response()->json([
                'message'    => 'Robaws offer created and updated with custom fields' . 
                               ($fileUploadResult && $fileUploadResult['status'] === 'success' ? ' with file attachment' : ''),
                'offer'      => ['id' => $offerId],
                'file_upload' => $fileUploadResult,
                'robaws_url' => rtrim(config('services.robaws.base_url'), '/') . '/offers/' . $offerId
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error in createFromDocument', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'An error occurred while creating the Robaws offer: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Build extraFields from the mapper output
     */
    private function buildExtraFieldsFromMapped(array $m): array
    {
        $xf = [];
        $put = function (string $label, $value, string $type = 'stringValue') use (&$xf) {
            if ($value === null || $value === '') return;
            $xf[$label] = [$type => (string)$value];
        };

        // Quotation info
        $put('Customer', $m['customer'] ?? null);
        $put('Contact', $m['contact'] ?? null);
        $put('Endcustomer', $m['endcustomer'] ?? null);
        $put('Customer reference', $m['customer_reference'] ?? null);

        // Routing
        $put('POR', $m['por'] ?? null);
        $put('POL', $m['pol'] ?? null);
        $put('POT', $m['pot'] ?? null);
        $put('POD', $m['pod'] ?? null);
        $put('FDEST', $m['fdest'] ?? null);

        // Cargo
        $put('CARGO', $m['cargo'] ?? null);
        $put('DIM_BEF_DELIVERY', $m['dim_bef_delivery'] ?? null);

        // JSON (use a slim version to avoid nested JSON-in-JSON)
        if (!empty($m['JSON'])) {
            $xf['JSON'] = ['stringValue' => $m['JSON']]; // correct format
        }

        return $xf;
    }

    /**
     * Strip read-only fields from offer for PUT request
     */
    private function stripOfferReadOnly(array $offer): array
    {
        unset($offer['id'], $offer['createdAt'], $offer['updatedAt'], $offer['links'], $offer['number']);
        return $offer;
    }

    /**
     * Extract client data from document data
     */
    private function extractClientData(array $extractedData, array $clientData = []): array
    {
        if (!empty($clientData)) {
            return $clientData;
        }
        
        return [
            'name' => $extractedData['customer_name'] ?? 
                     $extractedData['client_name'] ?? 
                     $extractedData['company'] ?? 
                     'Unknown Client',
            'email' => $extractedData['customer_email'] ?? 
                      $extractedData['client_email'] ?? 
                      $extractedData['email'] ?? null,
            'tel' => $extractedData['customer_phone'] ?? 
                    $extractedData['client_phone'] ?? 
                    $extractedData['phone'] ?? null,
            'address' => [
                'street' => $extractedData['customer_address'] ?? 
                           $extractedData['billing_address'] ?? '',
                'city' => $extractedData['customer_city'] ?? 
                         $extractedData['city'] ?? '',
                'postalCode' => $extractedData['customer_postal_code'] ?? 
                               $extractedData['postal_code'] ?? '',
                'country' => $extractedData['customer_country'] ?? 
                            $extractedData['country'] ?? 'BE'
            ]
        ];
    }

    /**
     * Webhook endpoint for Robaws events
     */
    public function webhook(Request $request)
    {
        $event = $request->input('event');
        $data = $request->input('data');
        
        Log::info('Robaws webhook received', [
            'event' => $event,
            'entity_type' => $data['entityType'] ?? null,
            'entity_id' => $data['entityId'] ?? null
        ]);
        
        try {
            switch ($event) {
                case 'offer.created':
                case 'offer.updated':
                    $this->handleOfferUpdate($data);
                    break;
                    
                case 'offer.accepted':
                    $this->handleOfferAccepted($data);
                    break;
                    
                case 'offer.rejected':
                    $this->handleOfferRejected($data);
                    break;
                    
                default:
                    Log::debug('Unhandled Robaws webhook event', ['event' => $event]);
            }
        } catch (\Exception $e) {
            Log::error('Error processing Robaws webhook', [
                'event' => $event,
                'error' => $e->getMessage()
            ]);
            
            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
        
        return response()->json(['status' => 'ok']);
    }

    private function handleOfferUpdate(array $data): void
    {
        $offerId = $data['entityId'] ?? null;
        if (!$offerId) return;
        
        // Update local quotation record
        $quotation = \App\Models\Quotation::where('robaws_id', $offerId)->first();
        if ($quotation) {
            $quotation->update([
                'status' => strtolower($data['status'] ?? $quotation->status),
                'robaws_data' => array_merge($quotation->robaws_data ?? [], $data),
            ]);
            
            Log::info('Updated quotation from webhook', [
                'quotation_id' => $quotation->id,
                'robaws_id' => $offerId,
                'status' => $quotation->status
            ]);
        }
    }

    private function handleOfferAccepted(array $data): void
    {
        $offerId = $data['entityId'] ?? null;
        if (!$offerId) return;
        
        $quotation = \App\Models\Quotation::where('robaws_id', $offerId)->first();
        if ($quotation) {
            $quotation->update([
                'status' => 'accepted',
                'accepted_at' => now(),
            ]);
            
            Log::info('Offer accepted', [
                'quotation_id' => $quotation->id,
                'robaws_id' => $offerId
            ]);
            
            // Trigger additional business logic here
            // e.g., create shipment, notify user, etc.
        }
    }

    /**
     * Upload documents for an existing quotation
     */
    public function uploadDocuments(Request $request, string $quotationId): JsonResponse
    {
        $quotation = \App\Models\Quotation::where('robaws_id', $quotationId)->first();
        
        if (!$quotation) {
            return response()->json([
                'message' => 'Quotation not found'
            ], 404);
        }

        try {
            $uploadResults = $this->uploadService->uploadQuotationDocuments($quotation);
            
            return response()->json([
                'message' => 'Document upload process completed',
                'quotation_id' => $quotationId,
                'upload_results' => $uploadResults,
                'total_files' => count($uploadResults),
                'successful' => count(array_filter($uploadResults, fn($r) => $r['status'] === 'success')),
                'failed' => count(array_filter($uploadResults, fn($r) => $r['status'] === 'error'))
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Document upload failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get upload status for a quotation
     */
    public function getUploadStatus(string $quotationId): JsonResponse
    {
        $quotation = \App\Models\Quotation::where('robaws_id', $quotationId)->first();
        
        if (!$quotation) {
            return response()->json([
                'message' => 'Quotation not found'
            ], 404);
        }

        $status = $this->uploadService->getUploadStatus($quotation);
        
        return response()->json([
            'quotation_id' => $quotationId,
            'upload_status' => $status
        ]);
    }

    /**
     * Retry failed uploads for a quotation
     */
    public function retryFailedUploads(string $quotationId): JsonResponse
    {
        $quotation = \App\Models\Quotation::where('robaws_id', $quotationId)->first();
        
        if (!$quotation) {
            return response()->json([
                'message' => 'Quotation not found'
            ], 404);
        }

        try {
            $retryResults = $this->uploadService->retryFailedUploads($quotation);
            
            return response()->json([
                'message' => 'Retry process completed',
                'quotation_id' => $quotationId,
                'retry_results' => $retryResults,
                'total_retried' => count($retryResults),
                'successful' => count(array_filter($retryResults, fn($r) => $r['status'] === 'success')),
                'failed' => count(array_filter($retryResults, fn($r) => $r['status'] === 'error'))
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Retry failed: ' . $e->getMessage()
            ], 500);
        }
    }

    private function handleOfferRejected(array $data): void
    {
        $offerId = $data['entityId'] ?? null;
        if (!$offerId) return;
        
        $quotation = \App\Models\Quotation::where('robaws_id', $offerId)->first();
        if ($quotation) {
            $quotation->update([
                'status' => 'rejected',
                'rejected_at' => now(),
            ]);
            
            Log::info('Offer rejected', [
                'quotation_id' => $quotation->id,
                'robaws_id' => $offerId
            ]);
        }
    }
}
