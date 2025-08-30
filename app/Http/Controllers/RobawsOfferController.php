<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\RobawsClient;
use App\Services\RobawsIntegrationService;
use App\Services\MultiDocumentUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class RobawsOfferController extends Controller
{
    public function __construct(
        private RobawsIntegrationService $robawsService,
        private RobawsClient $robawsClient,
        private MultiDocumentUploadService $uploadService
    ) {}

    /**
     * Create an offer in Robaws with extracted JSON data
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
            
            // Create offer using the RobawsClient directly
            $clientData = $this->extractClientData($extractedData, $request->client_data ?? []);
            $client = $this->robawsClient->findOrCreateClient($clientData);
            
            // Prepare offer data with extracted JSON in extraFields
            $offerData = [
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
                'extraFields' => [
                    'extracted_json' => [
                        'value' => json_encode($extractedData, JSON_PRETTY_PRINT),
                        'type' => 'LONG_TEXT'
                    ]
                ]
            ];
            
            $offer = $this->robawsClient->createOffer($offerData);
            
            Log::info('Robaws offer created successfully via API', [
                'offer_id' => $offer['id'],
                'client_id' => $client['id']
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Offer created successfully in Robaws',
                'data' => [
                    'offer' => $offer,
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
     * Create a Robaws offer from a document
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
            
            // Check if document has extraction data (prefer extraction.raw_json for JSON field support)
            $extraction = $document->extractions()->first();
            $extractionDataSource = null;
            $dataSource = 'unknown';
            
            if ($extraction && $extraction->raw_json) {
                $extractionDataSource = $extraction->raw_json;
                $dataSource = 'extraction.raw_json';
            } elseif ($document->raw_json) {
                $extractionDataSource = $document->raw_json;
                $dataSource = 'document.raw_json';
            } else {
                $extractionDataSource = $document->extraction_data;
                $dataSource = 'document.extraction_data';
            }
            
            if (empty($extractionDataSource)) {
                return response()->json([
                    'message' => 'Document has no extraction data. Please wait for AI processing to complete.'
                ], 400);
            }
            
            // Try using the RobawsClient first, fallback to RobawsIntegrationService
            try {
                $extractedData = is_string($extractionDataSource) 
                    ? json_decode($extractionDataSource, true)
                    : $extractionDataSource;
                
                Log::info('RobawsOfferController: Creating offer with enhanced data', [
                    'document_id' => $document->id,
                    'data_source' => $dataSource,
                    'has_json_field' => isset($extractedData['JSON']),
                    'json_field_length' => strlen($extractedData['JSON'] ?? ''),
                    'field_count' => count($extractedData ?? [])
                ]);
                
                $clientData = $this->extractClientData($extractedData);
                $client = $this->robawsClient->findOrCreateClient($clientData);
                
                $offerData = [
                    'title' => $extractedData['title'] ?? "Offer from {$document->original_filename}",
                    'date' => $extractedData['date'] ?? $document->created_at->format('Y-m-d'),
                    'clientId' => $client['id'],
                    'currency' => $extractedData['currency'] ?? 'EUR',
                    'companyId' => config('services.robaws.default_company_id'),
                    'extraFields' => [
                        'source_document' => [
                            'value' => $document->original_filename,
                            'type' => 'TEXT'
                        ],
                        'JSON' => [
                            'value' => $extractedData['JSON'] ?? '',
                            'type' => 'LONG_TEXT'
                        ],
                        'extracted_json' => [
                            'value' => json_encode($extractedData, JSON_PRETTY_PRINT),
                            'type' => 'LONG_TEXT'
                        ]
                    ]
                ];
                
                Log::info('RobawsOfferController: Sending offer data to API', [
                    'document_id' => $document->id,
                    'has_json_in_extrafields' => !empty($offerData['extraFields']['JSON']['value']),
                    'json_field_length_in_payload' => strlen($offerData['extraFields']['JSON']['value'] ?? ''),
                    'extrafields_count' => count($offerData['extraFields'])
                ]);
                
                $offerData = [
                    'title' => $extractedData['title'] ?? "Offer from {$document->original_filename}",
                    'date' => $extractedData['date'] ?? $document->created_at->format('Y-m-d'),
                    'clientId' => $client['id'],
                    'currency' => $extractedData['currency'] ?? 'EUR',
                    'companyId' => config('services.robaws.default_company_id'),
                    'extraFields' => [
                        'source_document' => [
                            'value' => $document->original_filename,
                            'type' => 'TEXT'
                        ],
                        'JSON' => [
                            'value' => $extractedData['JSON'] ?? '',
                            'type' => 'LONG_TEXT'
                        ],
                        'extracted_json' => [
                            'value' => json_encode($extractedData, JSON_PRETTY_PRINT),
                            'type' => 'LONG_TEXT'
                        ]
                    ]
                ];
                
                $offer = $this->robawsClient->createOffer($offerData);
                
                // Update document with Robaws reference
                $document->update([
                    'robaws_quotation_id' => $offer['id'],
                    'robaws_client_id' => $client['id'] ?? null,
                ]);
                
                // NEW: Upload the document file to Robaws
                $fileUploadResult = null;
                try {
                    $fileUploadResult = $this->uploadService->uploadDocumentToQuotation($document);
                    Log::info('Document file uploaded to Robaws', [
                        'document_id' => $document->id,
                        'upload_result' => $fileUploadResult
                    ]);
                } catch (\Exception $uploadException) {
                    Log::warning('File upload failed, but quotation created successfully', [
                        'document_id' => $document->id,
                        'upload_error' => $uploadException->getMessage()
                    ]);
                    // Don't fail the entire request if file upload fails
                }
                
                return response()->json([
                    'message' => 'Robaws offer created successfully via API' . 
                               ($fileUploadResult && $fileUploadResult['status'] === 'success' ? ' with file attachment' : ''),
                    'offer' => $offer,
                    'file_upload' => $fileUploadResult,
                    'robaws_url' => config('services.robaws.base_url') . '/offers/' . $offer['id']
                ]);
                
            } catch (\Exception $apiException) {
                Log::warning('API approach failed, trying fallback service', [
                    'api_error' => $apiException->getMessage()
                ]);
                
                // Fallback to original service
                $offer = $this->robawsService->createOfferFromDocument($document);
                
                if (!$offer) {
                    throw new \Exception('Both API and service approaches failed');
                }
                
                return response()->json([
                    'message' => 'Robaws offer created successfully via service',
                    'offer' => $offer,
                    'robaws_url' => config('services.robaws.base_url') . '/offers/' . $offer['id']
                ]);
            }
            
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
