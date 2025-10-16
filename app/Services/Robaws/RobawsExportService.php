<?php

namespace App\Services\Robaws;

use App\Models\Intake;
use App\Models\RobawsDocument;
use App\Services\Export\Mappers\RobawsMapper;
use App\Services\Export\Clients\RobawsApiClient;
use App\Services\RobawsClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class RobawsExportService
{
    private RobawsMapper $mapper;
    private RobawsApiClient $apiClient;
    private ?RobawsClient $legacyClient = null;

    public function __construct(
        RobawsMapper $mapper,
        RobawsApiClient $apiClient,
        ?RobawsClient $legacyClient = null
    ) {
        $this->mapper = $mapper;
        $this->apiClient = $apiClient;
        $this->legacyClient = $legacyClient;
    }

    /**
     * Prefer injected, but always re-check the container in case tests bind later.
     */
    private function legacy(): ?RobawsClient
    {
        if ($this->legacyClient instanceof RobawsClient) {
            return $this->legacyClient;
        }
        return app()->bound(RobawsClient::class) ? app(RobawsClient::class) : null;
    }

    /**
     * Resolve client ID from extraction data for reliable export
     * Updated to use unified ClientResolver (supports name-only resolution)
     */
    public function resolveClientId(array $extractionData): ?string
    {
        $contactEmail = data_get($extractionData, 'contact.email');
        $contactPhone = data_get($extractionData, 'contact.phone');
        $contactName = data_get($extractionData, 'contact.name');
        
        // Build hints for unified ClientResolver
        $hints = array_filter([
            'email' => $contactEmail,
            'phone' => $contactPhone,
            'name' => $contactName,
        ]);
        
        if (empty($hints)) {
            Log::warning('Cannot resolve client - no contact info', [
                'extraction_data_keys' => array_keys($extractionData)
            ]);
            return null;
        }
        
        try {
            $resolver = app(\App\Services\Robaws\ClientResolver::class);
            $result = $resolver->resolve($hints);
            
            if ($result) {
                Log::info('Client resolution successful via unified resolver', [
                    'hints' => $hints,
                    'client_id' => $result['id'],
                    'confidence' => $result['confidence'],
                ]);
                
                return (string) $result['id'];
            }
            
            Log::warning('Client not found via unified resolver', [
                'hints' => $hints
            ]);
            
            return null;
            
        } catch (\Exception $e) {
            Log::error('Exception during client resolution', [
                'error' => $e->getMessage(),
                'hints' => $hints,
            ]);
            return null;
        }
    }

    /**
     * Export intake to Robaws with comprehensive error handling
     */
    public function exportIntake(Intake $intake, array $options = []): array
    {
        $startTime = microtime(true);
        $exportId = uniqid('export_', true);
        
        try {
            Log::info('Starting Robaws export', [
                'export_id' => $exportId,
                'intake_id' => $intake->id,
                'customer' => $intake->customer_name,
                'options' => $options,
            ]);

            // Ensure relations are loaded
            $intake->loadMissing(['extraction', 'documents.extraction', 'documents.extractions']);

            // Check if already exported recently (unless forced)
            if (!($options['force'] ?? false) && $this->wasRecentlyExported($intake)) {
                return [
                    'success' => false,
                    'error' => 'Intake was already exported recently. Use force=true to re-export.',
                    'quotation_id' => $intake->robaws_offer_id,
                ];
            }

            // Get extraction data
            $extractionData = $this->getExtractionData($intake);
            
            Log::info('Extraction data summary', [
                'export_id' => $exportId,
                'intake_id' => $intake->id,
                'has_vehicle' => !empty($extractionData['vehicle']),
                'has_shipping' => !empty($extractionData['shipping']),
                'has_contact' => !empty($extractionData['contact']),
                'data_size' => strlen(json_encode($extractionData)),
            ]);

            // Resolve client ID before mapping
            $customerName = data_get($extractionData, 'document_data.contact.name')
                         ?? data_get($extractionData, 'contact.name')
                         ?? $intake->customer_name
                         ?? null;
            $customerEmail = data_get($extractionData, 'document_data.contact.email')
                         ?? data_get($extractionData, 'contact.email')
                         ?? $intake->customer_email
                         ?? null;
            $customerPhone = data_get($extractionData, 'contact.phone')
                          ?? data_get($extractionData, 'document_data.contact.phone')
                          ?? data_get($extractionData, 'shipping.phone')
                          ?? data_get($extractionData, 'document_data.shipping.phone')
                          ?? data_get($extractionData, 'contact.telephone')
                          ?? data_get($extractionData, 'document_data.contact.telephone')
                          ?? null;

            // Map to Robaws format first
            $mapped = $this->mapper->mapIntakeToRobaws($intake, $extractionData);

            // Build type-safe payload with proper client ID handling
            $payload = $this->buildTypeSafePayload($intake, $extractionData, $mapped, $exportId);
            
            // Block export if payload is empty
            if (empty($payload)) {
                return [
                    'success' => false,
                    'status' => 422,
                    'error' => 'Mapped payload is empty - no data to export',
                ];
            }
            
            // Generate payload hash for idempotency
            $payloadHash = hash('sha256', json_encode($payload, \JSON_PARTIAL_OUTPUT_ON_ERROR|\JSON_UNESCAPED_UNICODE));
            
            Log::info('Robaws payload (api shape)', [
                'export_id' => $exportId,
                'intake_id' => $intake->id,
                'top' => array_keys($payload),
                'xf_keys' => isset($payload['extraFields']) ? array_keys($payload['extraFields']) : [],
                'payload_size' => strlen(json_encode($payload)),
                'payload_hash' => substr($payloadHash, 0, 16),
            ]);

            // Generate idempotency key
            $idempotencyKey = $options['idempotency_key'] ?? $this->generateIdempotencyKey($intake, $payloadHash);

            // Export to Robaws
            if ($intake->robaws_offer_id && !($options['create_new'] ?? false)) {
                // Update existing quotation
                $result = $this->apiClient->updateQuotation(
                    $intake->robaws_offer_id, 
                    $payload, 
                    $idempotencyKey
                );
                $action = 'updated';
            } else {
                // Create new quotation
                $result = $this->apiClient->createQuotation($payload, $idempotencyKey);
                $action = 'created';
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($result['success']) {
                // Verify the offer was stored correctly
                if ($id = $result['quotation_id'] ?? null) {
                    $verify = $this->apiClient->getOffer($id, ['client']); // Include client object
                    $verifyData = $verify['data'] ?? [];
                    
                    $stored = [];
                    if (!empty($verifyData['extraFields'])) {
                        // Pull key fields from extraFields
                        foreach (['POR','POL','POD','FDEST','CARGO','DIM_BEFORE_DELIVERY','METHOD','VESSEL','ETA','JSON'] as $code) {
                            if (isset($verifyData['extraFields'][$code])) {
                                $node = $verifyData['extraFields'][$code];
                                $stored[$code] = $node['stringValue'] ?? ($node['booleanValue'] ?? null);
                            }
                        }
                    }
                    
                    // Improved client linking verification
                    $linkedIdFromField = (int)($verifyData['clientId'] ?? 0);
                    $linkedIdFromObject = (int)($verifyData['client']['id'] ?? 0);
                    $expectedClientId = (int) (
                        $payload['clientId']
                        ?? $payload['customerId']
                        ?? data_get($payload, 'client.id', 0)
                        ?? 0
                    );
                    
                    // Consider it linked if either the field or object matches the intended clientId
                    $isLinked = ($linkedIdFromField === $expectedClientId) || ($linkedIdFromObject === $expectedClientId);
                    
                    Log::info('Robaws offer verification', [
                        'export_id' => $exportId,
                        'intake_id' => $intake->id,
                        'offer_id' => $id,
                        'verification_success' => $verify['success'] ?? false,
                        'client_linking' => [
                            'expected_client_id' => $expectedClientId,
                            'clientId_field' => $linkedIdFromField ?: null,
                            'client_object_id' => $linkedIdFromObject ?: null,
                            'is_linked' => $isLinked,
                            'client_name' => $verifyData['client']['name'] ?? null,
                        ],
                        'verify_top' => [
                            'clientId'       => data_get($verifyData, 'clientId'),
                            'clientPresent'  => !empty(data_get($verifyData, 'client')),
                            'clientReference'=> data_get($verifyData, 'clientReference'),
                            'contactEmail'   => data_get($verifyData, 'contactEmail'),
                        ],
                        'legacy_compat' => [
                            'clientId' => data_get($verifyData, 'clientId'),
                            'hasClientObj' => !empty(data_get($verifyData, 'client')),
                        ],
                        'top' => array_intersect_key($verifyData, array_flip([
                            'clientId','clientReference','title','contactEmail'
                        ])),
                        'xf' => $stored,
                    ]);
                }

                // Update intake with export info atomically
                $offerId = (int)($result['quotation_id'] ?? 0);
                
                if ($offerId > 0) {
                    // Write all fields atomically to avoid race conditions
                    Intake::whereKey($intake->id)->update([
                        'robaws_offer_id' => $offerId,
                        'robaws_exported_at' => now(),
                        'robaws_export_status' => 'exported',
                        'export_payload_hash' => hash('sha256', json_encode($payload)),
                    ]);

                    // Safely increment attempt count
                    Intake::whereKey($intake->id)->increment('export_attempt_count');

                    // Refresh in-memory model
                    $intake->refresh();
                }

                Log::info("Successfully {$action} Robaws quotation", [
                    'export_id' => $exportId,
                    'intake_id' => $intake->id,
                    'quotation_id' => $result['quotation_id'],
                    'action' => $action,
                    'duration_ms' => $duration,
                    'idempotency_key' => $result['idempotency_key'],
                ]);

                // Link contact person to the quotation if we have both client ID and contact data
                $this->linkContactPersonToQuotation($offerId, $payload, $extractionData, $intake, $exportId);

                // Attach documents after successful offer creation/update
                $this->attachDocumentsToOffer($intake, $offerId, $exportId);

                return [
                    'success' => true,
                    'action' => $action,
                    'quotation_id' => $result['quotation_id'],
                    'idempotency_key' => $result['idempotency_key'],
                    'duration_ms' => $duration,
                    'data' => $result['data'],
                ];
            }

            // Handle API failure
            $intake->increment('export_attempt_count');
            $intake->update(['last_export_error' => $result['error']]);

            Log::error('Robaws API failure', [
                'intake_id' => $intake->id,
                'status' => $result['status'] ?? null,
                'error' => $result['error'] ?? 'unknown',
                'request_id' => $result['request_id'] ?? null,
                'headers' => array_intersect_key(($result['headers'] ?? []), ['x-ratelimit-remaining'=>1,'retry-after'=>1]),
            ]);

            Log::error('Robaws export failed', [
                'export_id' => $exportId,
                'intake_id' => $intake->id,
                'error' => $result['error'],
                'status' => $result['status'] ?? null,
                'duration_ms' => $duration,
                'attempt_count' => $intake->export_attempt_count + 1,
            ]);

            return [
                'success' => false,
                'error' => $result['error'],
                'status' => $result['status'] ?? null,
                'duration_ms' => $duration,
                'details' => $result['data'] ?? [],
            ];

        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $intake->increment('export_attempt_count');
            $intake->update(['last_export_error' => $e->getMessage()]);

            Log::error('Unexpected error during Robaws export', [
                'export_id' => $exportId,
                'intake_id' => $intake->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'duration_ms' => $duration,
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'Unexpected error: ' . $e->getMessage(),
                'duration_ms' => $duration,
            ];
        }
    }

    /**
     * Build a type-safe payload with comprehensive validation and enhanced client creation
     */
    private function buildTypeSafePayload($intake, $extractionData, $mapped, $exportId): array
    {
        if (config('extraction.export.use_normalizer', true)) {
            // 1) Always normalize once, then drive everything from it
            $customer = app(\App\Support\CustomerNormalizer::class)->normalize($extractionData, [
                'default_country' => 'BE',
            ]);

            Log::info('Customer normalized for export', [
                'export_id' => $exportId,
                'intake_id' => $intake->id,
                'normalized_name' => $customer['name'] ?? null,
                'normalized_email' => $customer['email'] ?? null,
                'normalized_phone' => $customer['phone'] ?? null,
                'normalized_vat' => $customer['vat'] ?? null,
                'normalized_website' => $customer['website'] ?? null,
            ]);

            // 2) Resolve/create the client with a contacts array
            $clientPayload = app(\App\Services\Export\Clients\RobawsApiClient::class)
                ->buildRobawsClientPayload($customer); // includes contacts[0]{name,email,phone}

            // IMPORTANT: null-only filtering; do NOT deep-filter arrays
            $clientPayload = array_filter($clientPayload, static fn($v) => $v !== null);

            Log::info('Client payload for Robaws', [
                'export_id' => $exportId,
                'intake_id' => $intake->id,
                'payload_keys' => array_keys($clientPayload),
                'has_contacts' => !empty($clientPayload['contacts']),
                'contacts_count' => count($clientPayload['contacts'] ?? []),
                'first_contact' => $clientPayload['contacts'][0] ?? null,
            ]);

            // v2 preferred; fallback to legacy findOrCreate
            $clientId = null;
            try {
                if (method_exists($this->apiClient, 'createOrFindClient')) {
                    $client = $this->apiClient->createOrFindClient($clientPayload);
                    $clientId = $client['id'] ?? null;
                } else {
                    // Fallback: try to find existing client first
                    $clientId = $this->apiClient->findClientId(
                        $customer['name'] ?? null,
                        $customer['email'] ?? null,
                        $customer['phone'] ?? null
                    );
                    
                    // If not found and we have enough data, create new client
                    if (!$clientId && !empty($clientPayload)) {
                        try {
                            $createdClient = $this->apiClient->findOrCreateClient($clientPayload);
                            $clientId = $createdClient['id'] ?? null;
                            Log::info('Client created successfully', [
                                'export_id' => $exportId,
                                'client_id' => $clientId,
                                'customer_name' => $customer['name'] ?? null,
                            ]);
                        } catch (\Throwable $e) {
                            Log::warning('Client creation failed', [
                                'export_id' => $exportId,
                                'error' => $e->getMessage(),
                                'customer_name' => $customer['name'] ?? null,
                            ]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Client resolve/create failed', [
                    'e' => $e->getMessage(), 
                    'payload' => $clientPayload
                ]);
            }

            // 3) Guarantee a CONTACT exists on an existing client
            if ($clientId) {
                $this->ensureClientContact($clientId, $customer);
                $intake->robaws_client_id = $clientId;
                $intake->save();
            }

            // Type-safe client ID casting with validation
            $validatedClientId = $this->validateClientId($clientId, $exportId);

            Log::info('Enhanced client resolution', [
                'export_id' => $exportId,
                'intake_id' => $intake->id,
                'display_name' => $customer['name'] ?? 'Unknown',
                'contact_email' => $customer['email'] ?? null,
                'contact_phone' => $customer['phone'] ?? null,
                'resolved_client_id' => $clientId,
                'validated_client_id' => $validatedClientId,
                'binding_status' => $validatedClientId ? 'will_bind_to_client' : 'no_client_binding',
                'normalizer_used' => true,
            ]);

            // 4) Feed placeholders + dimensions into the offer payload
            $mapped['customer_normalized'] = $customer; // ensure it's present
            $mapped['client_placeholders'] = app(\App\Services\Export\Mappers\RobawsMapper::class)
                ->buildClientDisplayPlaceholders($customer);

            // Inject client ID and contact email into mapped data
            if ($validatedClientId) {
                $mapped['client_id'] = $validatedClientId;  // Already validated as integer
                
                // Use normalized email or intake fallback
                $finalContactEmail = $customer['email'] ?? $intake->contact_email;
                if ($finalContactEmail) {
                    $mapped['contact_email'] = $finalContactEmail;
                }
                
                Log::info('Client ID injected into payload', [
                    'export_id' => $exportId,
                    'client_id' => $validatedClientId,
                    'customer_name' => $customer['name'] ?? 'Unknown',
                    'contact_email' => $finalContactEmail,
                ]);
            } else {
                Log::warning('No unique client match found - export will proceed without client binding', [
                    'export_id' => $exportId,
                    'customer_name' => $customer['name'] ?? 'Unknown',
                    'customer_email' => $customer['email'] ?? null,
                ]);
            }
            
            // Remove customer_data from mapped data before building payload (it's only for client creation)
            unset($mapped['customer_data']);
            
            // Build final payload with comprehensive logging
            $payload = $this->mapper->toRobawsApiPayload($mapped);
            
            // Merge placeholders if mapper doesn't already:
            $payload['extraFields'] = array_merge($payload['extraFields'] ?? [], $mapped['client_placeholders'] ?? []);

            // 5) Stop pruning the contacts array - null-only filter
            $payload = array_filter($payload, static fn($v) => $v !== null);
            
            Log::info('Enhanced Robaws payload (api shape)', [
                'export_id' => $exportId,
                'intake_id' => $intake->id,
                'top' => array_keys($payload),
                'client_id_debug' => $payload['clientId'] ?? null,
                'contact_email_debug' => $payload['contactEmail'] ?? null,
                'client_reference_debug' => $payload['clientReference'] ?? null,
                'extraFields_keys' => array_keys($payload['extraFields'] ?? []),
                'has_client_placeholders' => !empty($mapped['client_placeholders']),
                'payload_size' => strlen(json_encode($payload)),
                'enhanced_client_creation' => true,
                'customer_normalizer_used' => true,
            ]);

            return $payload;
        } else {
            // Legacy fallback path
            return $this->buildLegacyTypeSafePayload($intake, $extractionData, $mapped, $exportId);
        }
    }

    /**
     * Legacy payload building method (pre-normalizer)
     */
    private function buildLegacyTypeSafePayload($intake, $extractionData, $mapped, $exportId): array
    {
        // Extract enhanced customer data
        $customerData = $mapped['customer_data'] ?? [];
        
        $customerName = trim($customerData['name'] ?? $extractionData['customerName'] ?? $extractionData['customer_name'] ?? $intake->customer_name ?? 'Unknown Customer');
        $customerEmail = trim($customerData['email'] ?? $extractionData['contactEmail'] ?? $extractionData['customer_email'] ?? $intake->contact_email ?? '');
        $customerPhone = trim($customerData['phone'] ?? $extractionData['customerPhone'] ?? $extractionData['customer_phone'] ?? $intake->contact_phone ?? '');

        // Validate and sanitize email
        $customerEmail = $this->validateAndSanitizeEmail($customerEmail);

        // Use pre-resolved client ID if available, otherwise try to find or create client
        $clientId = $intake->robaws_client_id;
        
        if (!$clientId) {
            // Try to find existing client first
            $clientId = $this->apiClient->findClientId($customerName, $customerEmail, $customerPhone);
            
            // If no existing client found and we have enough data, create a new one
            if (!$clientId && !empty($customerData)) {
                Log::info('Attempting to create new client with enhanced data (legacy)', [
                    'export_id' => $exportId,
                    'customer_name' => $customerName,
                    'has_email' => !empty($customerEmail),
                    'has_phone' => !empty($customerPhone),
                ]);
                
                $createdClient = $this->apiClient->findOrCreateClient($customerData);
                if ($createdClient && isset($createdClient['id'])) {
                    $clientId = $createdClient['id'];
                    
                    // Store the client ID for future use
                    $intake->update(['robaws_client_id' => $clientId]);
                    
                    Log::info('Successfully created new client (legacy)', [
                        'export_id' => $exportId,
                        'client_id' => $clientId,
                        'customer_name' => $customerName,
                    ]);
                } else {
                    Log::warning('Failed to create new client (legacy)', [
                        'export_id' => $exportId,
                        'customer_name' => $customerName
                    ]);
                }
            }
        }
        
        // Type-safe client ID casting with validation
        $validatedClientId = $this->validateClientId($clientId, $exportId);

        // Inject client ID and contact email into mapped data
        if ($validatedClientId) {
            $mapped['client_id'] = $validatedClientId;  // Already validated as integer
            
            // Use validated email or intake fallback
            $finalContactEmail = $customerEmail ?: ($intake->contact_email ?: null);
            if ($finalContactEmail) {
                $mapped['contact_email'] = $finalContactEmail;
            }
        }
        
        // Remove customer_data from mapped data before building payload (it's only for client creation)
        unset($mapped['customer_data']);
        
        // Build final payload with comprehensive logging
        $payload = $this->mapper->toRobawsApiPayload($mapped);
        
        Log::info('Legacy Robaws payload (api shape)', [
            'export_id' => $exportId,
            'intake_id' => $intake->id,
            'top' => array_keys($payload),
            'client_id_debug' => $payload['clientId'] ?? null,
            'payload_size' => strlen(json_encode($payload)),
            'legacy_mode' => true,
        ]);

        return $payload;
    }

    /**
     * Validate and sanitize email address
     */
    private function validateAndSanitizeEmail(?string $email): string
    {
        if (empty($email)) {
            return '';
        }

        $email = trim(strtolower($email));
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::warning('Invalid email format detected', [
                'original_email' => $email,
                'will_use_fallback' => true,
            ]);
            return '';
        }

        return $email;
    }

    /**
     * Validate and cast client ID to integer with comprehensive logging
     */
    private function validateClientId($clientId, string $exportId): ?int
    {
        if ($clientId === null || $clientId === '') {
            return null;
        }

        // Handle string representations
        if (is_string($clientId) && !is_numeric($clientId)) {
            Log::error('Non-numeric client ID detected', [
                'export_id' => $exportId,
                'client_id' => $clientId,
                'type' => gettype($clientId),
            ]);
            return null;
        }

        // Cast to integer
        $intClientId = (int) $clientId;

        // Validate positive integer
        if ($intClientId <= 0) {
            Log::error('Invalid client ID - must be positive integer', [
                'export_id' => $exportId,
                'original_client_id' => $clientId,
                'cast_client_id' => $intClientId,
            ]);
            return null;
        }

        // Log successful validation
        Log::info('Client ID validation successful', [
            'export_id' => $exportId,
            'original_client_id' => $clientId,
            'original_type' => gettype($clientId),
            'validated_client_id' => $intClientId,
            'validated_type' => gettype($intClientId),
        ]);

        return $intClientId;
    }

    /**
     * Guarantee a CONTACT exists on an existing client
     */
    private function ensureClientContact(int $clientId, array $customer): void
    {
        $email = $customer['contact']['email'] ?? $customer['email'] ?? null;
        $phone = $customer['contact']['phone'] ?? $customer['phone'] ?? null;
        $name  = $customer['contact']['name']  ?? null;

        if (!$email && !$phone) return;

        try {
            if ($email && method_exists($this->apiClient, 'findContactByEmail')) {
                $contact = $this->apiClient->findContactByEmail($email);
                if (!empty($contact['id'])) return; // already present
            }
            if (method_exists($this->apiClient, 'createContact')) {
                $this->apiClient->createContact($clientId, array_filter([
                    'name'  => $name,
                    'email' => $email,
                    'phone' => $phone,
                ], fn($v) => $v !== null));
            }
        } catch (\Throwable $e) {
            Log::info('ensureClientContact failed', ['client_id' => $clientId, 'e' => $e->getMessage()]);
        }
    }

    /**
     * Bulk export multiple intakes
     */
    public function bulkExport(array $intakeIds, array $options = []): array
    {
        $results = [];
        $successCount = 0;
        $failureCount = 0;
        $startTime = microtime(true);

        Log::info('Starting bulk Robaws export', [
            'intake_count' => count($intakeIds),
            'options' => $options,
        ]);

        foreach ($intakeIds as $intakeId) {
            $intake = Intake::find($intakeId);
            
            if (!$intake) {
                $results[$intakeId] = [
                    'success' => false,
                    'error' => 'Intake not found',
                ];
                $failureCount++;
                continue;
            }

            $result = $this->exportIntake($intake, $options);
            $results[$intakeId] = $result;

            if ($result['success']) {
                $successCount++;
            } else {
                $failureCount++;
            }

            // Add delay between requests to avoid rate limiting
            if (count($intakeIds) > 1) {
                usleep(($options['delay_ms'] ?? 100) * 1000);
            }
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        Log::info('Bulk export completed', [
            'total' => count($intakeIds),
            'success' => $successCount,
            'failure' => $failureCount,
            'duration_ms' => $duration,
        ]);

        return [
            'success' => $failureCount === 0,
            'total' => count($intakeIds),
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'duration_ms' => $duration,
            'results' => $results,
        ];
    }

    /**
     * Test connection to Robaws API
     */
    public function testConnection(): array
    {
        Log::info('Testing Robaws API connection');
        
        $configCheck = $this->apiClient->validateConfig();
        if (!$configCheck['valid']) {
            return [
                'success' => false,
                'error' => 'Configuration invalid',
                'issues' => $configCheck['issues'],
            ];
        }

        $result = $this->apiClient->testConnection();
        
        Log::info('Robaws connection test result', [
            'success' => $result['success'],
            'status' => $result['status'] ?? null,
            'response_time' => $result['response_time'] ?? null,
        ]);

        return $result;
    }

    /**
     * Get audit information for export troubleshooting
     */
    public function getExportAudit(Intake $intake): array
    {
        $extractionData = $this->getExtractionData($intake);
        $payload = $this->mapper->mapIntakeToRobaws($intake, $extractionData);
        
        return [
            'intake' => [
                'id' => $intake->id,
                'customer_name' => $intake->customer_name,
                'robaws_offer_id' => $intake->robaws_offer_id,
                'exported_at' => $intake->robaws_exported_at ?? $intake->exported_at,
                'export_attempt_count' => $intake->export_attempt_count ?? 0,
                'last_export_error' => $intake->last_export_error,
            ],
            'extraction_summary' => [
                'total_size' => strlen(json_encode($extractionData)),
                'sections' => array_keys($extractionData),
                'has_vehicle' => !empty($extractionData['vehicle']),
                'has_shipping' => !empty($extractionData['shipping']),
                'has_contact' => !empty($extractionData['contact']),
            ],
            'payload_summary' => [
                'total_size' => strlen(json_encode($payload)),
                'sections' => array_keys($payload),
                'has_json_field' => !empty($payload['automation']['json']),
                'json_size' => strlen($payload['automation']['json'] ?? ''),
            ],
            'mapping_completeness' => $this->analyzeMapping($payload),
        ];
    }

    // Private helper methods

    /**
     * Enhanced extraction value resolver with database fallback + AI fallback
     */
    private function extractionValue(Intake $intake, string $path, ?string $regexHint = null): ?string
    {
        // A) document-level structured data first
        foreach ($intake->documents as $doc) {
            $data = $doc->extraction->extracted_data ?? $doc->extraction->data ?? null;
            $val = data_get($data, $path);
            if ($val) return is_string($val) ? trim($val) : $val;
        }

        // B) intake-level structured data
        $val = data_get($intake->extraction->extracted_data ?? $intake->extraction->data ?? null, $path);
        if ($val) return is_string($val) ? trim($val) : $val;

        // C) heuristic (regex) on plain text as a last resort
        if ($regexHint) {
            foreach ($intake->documents as $doc) {
                $txt = $doc->plain_text ?? '';
                if ($txt && preg_match($regexHint, $txt, $m)) {
                    return trim($m[1] ?? $m[0]);
                }
            }
        }

        return null;
    }

    /**
     * Attach documents to Robaws offer
     */
    public function attachDocumentsToOffer(Intake $intake, int $offerId, string $exportId): void
    {
        // For multi-document intakes, use only IntakeFiles to avoid duplicates
        // For single-document intakes, use Document models
        if ($intake->is_multi_document) {
            // Multi-document intake: use only IntakeFiles (Document models are duplicates)
            $intakeFiles = $intake->files()->whereIn('mime_type', [
                'message/rfc822',  // .eml files
                'application/pdf',  // PDFs
                'image/png',        // Images
                'image/jpeg',       // Images
            ])->get();
            $docs = collect();
            
            Log::info('Multi-document intake: using only IntakeFiles for attachment', [
                'intake_id' => $intake->id,
                'intake_files_count' => $intakeFiles->count()
            ]);
        } else {
            // Single-document intake: use Document models (approved, pending, or no status set)
            $docs = $intake->documents()->where(function ($q) {
                $q->whereIn('status', ['approved', 'pending'])->orWhereNull('status');
            })->get();
            $intakeFiles = collect(); // Empty collection for single-file intakes
            
            Log::info('Single-document intake: using Document models for attachment', [
                'intake_id' => $intake->id,
                'documents_count' => $docs->count()
            ]);
        }

        // Debug logging
        Log::info('attachDocumentsToOffer: File discovery', [
            'export_id' => $exportId,
            'offer_id' => $offerId,
            'intake_id' => $intake->id,
            'docs_found' => $docs->count(),
            'intake_files_found' => $intakeFiles->count(),
            'all_intake_files' => $intake->files()->count(),
            'all_documents' => $intake->documents()->count(),
            'doc_details' => $docs->map(function($doc) {
                return [
                    'id' => $doc->id,
                    'filename' => $doc->filename,
                    'status' => $doc->status,
                    'file_path' => $doc->file_path,
                    'filepath' => $doc->filepath ?? 'null'
                ];
            })->toArray(),
            'intake_file_details' => $intakeFiles->map(function($file) {
                return [
                    'id' => $file->id,
                    'filename' => $file->filename,
                    'mime_type' => $file->mime_type,
                    'storage_path' => $file->storage_path,
                    'storage_disk' => $file->storage_disk
                ];
            })->toArray()
        ]);

        $allFilesToUpload = collect();

        // Add Document models to upload list
        foreach ($docs as $doc) {
            $path = $doc->filepath ?? $doc->file_path ?? null;
            if ($path && Storage::exists($path)) {
                $allFilesToUpload->push([
                    'type' => 'document',
                    'id' => $doc->id,
                    'path' => $path,
                    'filename' => $doc->filename, // Use filename directly (already contains original name)
                ]);
            }
        }

        // Add IntakeFile models to upload list
        foreach ($intakeFiles as $file) {
            if (Storage::disk($file->storage_disk)->exists($file->storage_path)) {
                $allFilesToUpload->push([
                    'type' => 'intake_file',
                    'id' => $file->id,
                    'path' => $file->storage_path,
                    'disk' => $file->storage_disk,
                    'filename' => $file->filename,
                ]);
            }
        }

        // Deduplicate files by filename and path to prevent attaching the same file multiple times
        $allFilesToUpload = $allFilesToUpload->unique(function ($file) {
            // Use filename + path as unique key, but also consider the storage path
            $path = $file['path'] ?? '';
            if (isset($file['disk']) && isset($file['path'])) {
                // For IntakeFiles, use storage_disk + storage_path
                $path = $file['disk'] . ':' . $file['path'];
            }
            return $file['filename'] . '_' . $path;
        });

        if ($allFilesToUpload->isEmpty()) {
            Log::info('No files to attach', [
                'export_id' => $exportId, 
                'offer_id' => $offerId,
                'documents_count' => $docs->count(),
                'intake_files_count' => $intakeFiles->count()
            ]);
            return;
        }

        Log::info('Attaching files to offer', [
            'export_id' => $exportId,
            'offer_id' => $offerId,
            'total_files' => $allFilesToUpload->count(),
            'documents' => $docs->count(),
            'intake_files' => $intakeFiles->count()
        ]);

        foreach ($allFilesToUpload as $fileInfo) {
            try {
                if ($fileInfo['type'] === 'intake_file') {
                    // Handle IntakeFile
                    $absolutePath = Storage::disk($fileInfo['disk'])->path($fileInfo['path']);
                } else {
                    // Handle Document
                    $absolutePath = Storage::path($fileInfo['path']);
                }

                $result = $this->apiClient->attachFileToOffer($offerId, $absolutePath, $fileInfo['filename']);
                
                Log::info('Attached file to offer', [
                    'export_id' => $exportId,
                    'offer_id' => $offerId,
                    'file_type' => $fileInfo['type'],
                    'file_id' => $fileInfo['id'],
                    'filename' => $fileInfo['filename'],
                    'result' => $result
                ]);
            } catch (\Throwable $e) {
                Log::error('File attach failed', [
                    'export_id' => $exportId,
                    'offer_id' => $offerId,
                    'file_type' => $fileInfo['type'],
                    'file_id' => $fileInfo['id'],
                    'filename' => $fileInfo['filename'],
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Load extraction data for intake (alias for compatibility)
     */
    public function loadExtractionForIntake(Intake $intake): array
    {
        return $this->getExtractionData($intake);
    }

    private function getExtractionData(Intake $intake): array
    {
        // Normalize to array even if DB stores a JSON string
        $normalize = function ($raw) {
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                return is_array($decoded) ? $decoded : [];
            }
            return is_array($raw) ? $raw : [];
        };

        $base = $normalize($intake->extraction?->data ?? []);

        foreach ($intake->documents as $doc) {
            // Prefer singular extraction (latest) but fall back to last of extractions
            $docExtraction = $doc->extraction ?? $doc->extractions->last();
            $raw = $docExtraction?->data ?? $docExtraction?->extracted_data ?? null;
            $base = array_replace_recursive($base, $normalize($raw));
        }

        return $base;
    }

    private function wasRecentlyExported(Intake $intake): bool
    {
        $ts = $intake->robaws_exported_at ?? $intake->exported_at;
        if (!$ts) return false;
        
        $threshold = \Illuminate\Support\Carbon::parse($ts)->addMinutes(5);
        return now()->lt($threshold);
    }

    private function generateIdempotencyKey(Intake $intake, string $payloadHash): string
    {
        return sprintf('bconnect_%d_%s', $intake->id, substr($payloadHash, 0, 16));
    }

    private function analyzeMapping(array $payload): array
    {
        $analysis = [];
        
        // Check each section for completeness
        foreach ($payload as $section => $data) {
            $analysis[$section] = $this->analyzeMappingSection($data);
        }
        
        return $analysis;
    }

    private function analyzeMappingSection(array $data): array
    {
        $total = count($data);
        $filled = 0;
        $empty = 0;
        
        foreach ($data as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $filled++;
            } elseif (is_array($value) && !empty($value)) {
                $filled++;
            } elseif (!is_null($value) && $value !== '' && $value !== []) {
                $filled++;
            } else {
                $empty++;
            }
        }
        
        return [
            'total_fields' => $total,
            'filled_fields' => $filled,
            'empty_fields' => $empty,
            'completeness_percent' => $total > 0 ? round(($filled / $total) * 100, 1) : 0,
        ];
    }

    /**
     * Backwards-compatible document upload used by tests and old callers.
     * - Checks local ledger by SHA-256 first (returns "exists")
     * - If not found, uses legacy client when available
     * - Otherwise falls back to the new API flow
     * Always returns a normalized shape expected by tests.
     */
    public function uploadDocumentToOffer(int $offerId, string $relativePath): array
    {
        // Resolve absolute file path and sanity-check
        $abs = $this->resolveAbsolutePath($relativePath);
        if (!$abs || !is_file($abs)) {
            return [
                'status'   => 'error',
                'error'    => 'File not found',
                'document' => ['id' => null, 'mime' => null, 'sha256' => null, 'size' => null],
            ];
        }

        // Compute hash & size (tests assert we preserve these on errors)
        [$sha256, $size, $mime] = $this->shaSizeMime($abs);

        // 1) Local ledger: return "exists" if we've seen this file before
        if ($ledger = RobawsDocument::where('sha256', $sha256)->first()) {
            return [
                'status'   => 'exists',
                'reason'   => 'local ledger',
                'document' => [
                    'id'     => (int) $ledger->robaws_document_id,
                    'mime'   => $ledger->mime_type ?? $mime,
                    'sha256' => $sha256,
                    'size'   => $size,
                ],
            ];
        }

        // 2) Try the legacy client if it exists (what the tests expect)
        Log::debug('BC method debug', [
            'has_legacy_client' => $this->legacy() !== null,
            'legacy_client_class' => $this->legacy() ? get_class($this->legacy()) : null,
            'app_bound_robaws_client' => app()->bound(RobawsClient::class),
        ]);
        
        if ($client = $this->legacy()) {
            $stream = fopen($abs, 'rb');
            try {
                $fileSpec = [
                    'filename' => basename($relativePath),
                    'mime'     => $mime ?: 'application/octet-stream',
                    'size'     => $size,
                    'stream'   => $stream,   // tests expect this
                ];

                $resp = $client->uploadDocument((string) $offerId, $fileSpec);

                // STRICTLY pull the *remote* id from the legacy response:
                $remoteId   = (int) (data_get($resp, 'document.id') ?? data_get($resp, 'id'));
                $remoteMime = data_get($resp, 'document.mime') ?? data_get($resp, 'mime') ?? $mime;

                Log::debug('Legacy upload debug', [
                    'remote_id' => $remoteId,
                    'resp' => $resp,
                    'data_get_document_id' => data_get($resp, 'document.id'),
                    'data_get_id' => data_get($resp, 'id'),
                ]);

                // Persist ledger entry but don't reuse the local model ID
                $ledgerEntry = RobawsDocument::create([
                    'robaws_offer_id'    => (string) $offerId,
                    'robaws_document_id' => $remoteId,     // <- remote id (77)
                    'sha256'             => $sha256,
                    'filename'           => basename($relativePath),
                    'filesize'           => $size,
                ]);

                Log::debug('Ledger entry created', [
                    'ledger_id' => $ledgerEntry->id,
                    'remote_id_stored' => $ledgerEntry->robaws_document_id,
                ]);

                return [
                    'status'   => 'uploaded',
                    'document' => [
                        'id'     => $remoteId,   // <- return remote id (77)
                        'mime'   => $remoteMime,
                        'sha256' => $sha256,
                        'size'   => $size,
                    ],
                    'meta'     => $resp,
                ];
            } catch (\Throwable $e) {
                return [
                    'status'   => 'error',
                    'error'    => $e->getMessage(),
                    'document' => [
                        'id'     => null,
                        'mime'   => null,
                        'sha256' => $sha256,
                        'size'   => $size,
                    ],
                ];
            } finally {
                if (is_resource($stream)) fclose($stream);
            }
        }

        // 3) Fallback: use the new API flow (temp bucket + patch offer)
        try {
            $this->apiClient->attachFileToOffer($offerId, $abs, basename($relativePath));

            // If your app keeps a local ledger, write it here too
            RobawsDocument::create([
                'robaws_offer_id'    => (string) $offerId,
                'robaws_document_id' => null, // set if the new flow returns an id
                'sha256'             => $sha256,
                'filename'           => basename($relativePath),
                'filesize'           => $size,
            ]);

            return [
                'status'   => 'uploaded',
                'document' => [
                    'id'     => null, // set when your client returns the created doc id
                    'mime'   => $mime,
                    'sha256' => $sha256,
                    'size'   => $size,
                ],
                'meta'     => ['flow' => 'new_api_temp_bucket'],
            ];
        } catch (\Throwable $e) {
            return [
                'status'   => 'error',
                'error'    => $e->getMessage(),
                'document' => [
                    'id'     => null,
                    'mime'   => null,
                    'sha256' => $sha256,
                    'size'   => $size,
                ],
            ];
        }
    }

    // === helpers ===========================================================

    private function resolveAbsolutePath(string $path, ?string $hintDisk = null): ?string
    {
        // 1) Already absolute on the filesystem?
        if (is_file($path)) {
            return $path;
        }

        // 2) disk://relative or disk:relative
        if (preg_match('/^([a-z0-9_]+):\/\/(.+)$/i', $path, $m) ||
            preg_match('/^([a-z0-9_]+):(.+)$/i', $path, $m)) {
            $disk = $m[1];
            $rel  = ltrim($m[2], '/');
            if (config("filesystems.disks.$disk") && Storage::disk($disk)->exists($rel)) {
                return Storage::disk($disk)->path($rel);
            }
        }

        $disksTried = [];

        // 3) If the first path segment equals a configured disk, use it
        if (str_contains($path, '/')) {
            $first = strtok($path, '/');
            if ($first && config("filesystems.disks.$first")) {
                $rel = substr($path, strlen($first) + 1);
                if (Storage::disk($first)->exists($rel)) {
                    return Storage::disk($first)->path($rel);
                }
                $disksTried[] = $first;
            }
        }

        // 4) Try hint disk, then default disk, then common disks
        $candidates = [];
        if ($hintDisk && config("filesystems.disks.$hintDisk")) $candidates[] = $hintDisk;

        $default = config('filesystems.default', 'local');
        if (!in_array($default, $disksTried, true)) $candidates[] = $default;

        foreach (['documents','local','public'] as $d) {
            if (!in_array($d, $candidates, true) && config("filesystems.disks.$d")) $candidates[] = $d;
        }

        foreach ($candidates as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->path($path);
            }
        }

        // 5) Last resort: storage_path
        $fallback = storage_path('app/' . ltrim($path, '/'));
        return is_file($fallback) ? $fallback : null;
    }

    private function shaSizeMime(string $absPath): array
    {
        $body = file_get_contents($absPath);
        $sha  = hash('sha256', $body);
        $size = strlen($body);

        $mime = function_exists('mime_content_type') ? mime_content_type($absPath) : null;
        if (!$mime || $mime === 'text/plain') {
            $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'eml'        => 'message/rfc822',
                'pdf'        => 'application/pdf',
                'jpg','jpeg' => 'image/jpeg',
                'png'        => 'image/png',
                default      => $mime ?: 'application/octet-stream',
            };
        }

        return [$sha, $size, $mime];
    }

    private function attachViaNewFlow(int $offerId, string $absPath, string $filename): void
    {
        // Reuse your new API client's "temp bucket + patch offer" flow.
        // Assumes you already implemented something like attachFileToOffer().
        $this->apiClient->attachFileToOffer($offerId, $absPath, $filename);
    }

    /**
     * Detect MIME type of file
     */
    private function detectMime(string $file): ?string
    {
        if (!is_readable($file)) {
            return null;
        }
        
        $fi = new \finfo(FILEINFO_MIME_TYPE);
        return $fi->file($file) ?: null;
    }

    /**
     * Link contact person to quotation after successful creation
     */
    private function linkContactPersonToQuotation(int $offerId, array $payload, array $extractionData, Intake $intake, string $exportId): void
    {
        // Derive the client id from multiple possible shapes
        $clientId = (int) (
            $payload['clientId']
            ?? $payload['customerId']
            ?? data_get($payload, 'client.id', 0)
            ?? 0
        );

        // Fallback: read it back from the just-created offer
        if (!$clientId && $offerId) {
            $offer = $this->apiClient->getOffer((string)$offerId, ['client']);
            $clientId = (int) data_get($offer, 'data.client.id', 0);
        }

        if (!$clientId) {
            Log::debug('Skipping contact person linking - no client ID', [
                'export_id'    => $exportId,
                'offer_id'     => $offerId,
                'payload_keys' => array_keys($payload),
            ]);
            return;
        }

        try {
            // Extract contact person data from various sources
            $contactData = $this->extractContactPersonData($extractionData, $intake);
            
            if (empty($contactData)) {
                Log::debug('Skipping contact person linking - no contact data found', [
                    'export_id' => $exportId,
                    'offer_id' => $offerId,
                    'client_id' => $clientId
                ]);
                return;
            }

            // Find or create the contact person and get their ID
            $contactId = $this->apiClient->findOrCreateClientContactId($clientId, $contactData);
            
            if (!$contactId) {
                Log::warning('Failed to resolve contact person for quotation linking', [
                    'export_id' => $exportId,
                    'offer_id' => $offerId,
                    'client_id' => $clientId,
                    'contact_data' => $contactData
                ]);
                return;
            }

            // Set the contact person on the offer
            $success = $this->apiClient->setOfferContact($offerId, $contactId);
            
            if ($success) {
                Log::info('Successfully linked contact person to quotation', [
                    'export_id' => $exportId,
                    'offer_id' => $offerId,
                    'client_id' => $clientId,
                    'contact_id' => $contactId,
                    'contact_email' => $contactData['email'] ?? null
                ]);
            } else {
                Log::warning('Failed to link contact person to quotation', [
                    'export_id' => $exportId,
                    'offer_id' => $offerId,
                    'client_id' => $clientId,
                    'contact_id' => $contactId
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error linking contact person to quotation', [
                'export_id' => $exportId,
                'offer_id' => $offerId,
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Extract contact person data from extraction data with intake fallback
     */
    private function extractContactPersonData(array $extractionData, Intake $intake = null): array
    {
        // Try to extract contact person from various data sources
        $contact = [];
        
        // Primary: use contact data from document_data
        if (!empty($extractionData['document_data']['contact'])) {
            $contact = $extractionData['document_data']['contact'];
        }
        // Fallback: use top-level contact data  
        elseif (!empty($extractionData['contact'])) {
            $contact = $extractionData['contact'];
        }
        // Last resort: use intake data if no extraction data available
        elseif ($intake && ($intake->contact_email || $intake->customer_name)) {
            $contact = [
                'name' => $intake->customer_name,
                'email' => $intake->contact_email,
                'phone' => $intake->contact_phone,
            ];
        }
        
        // Extract and normalize contact person data
        $contactPerson = [];
        
        if (!empty($contact['email'])) {
            $contactPerson['email'] = $contact['email'];
        }
        
        // Use pre-parsed first/last names if available, otherwise fall back to splitting full name
        if (!empty($contact['first_name']) || !empty($contact['last_name'])) {
            $contactPerson['first_name'] = $contact['first_name'] ?? null;
            $contactPerson['last_name'] = $contact['last_name'] ?? null;
            $contactPerson['name'] = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''));
        } elseif (!empty($contact['name'])) {
            $contactPerson['name'] = $contact['name'];
            
            // Try to split name into first/last as fallback
            $nameParts = explode(' ', trim($contact['name']), 2);
            $contactPerson['first_name'] = $nameParts[0] ?? null;
            $contactPerson['last_name'] = $nameParts[1] ?? null;
        }
        
        if (!empty($contact['phone'])) {
            $contactPerson['phone'] = $contact['phone'];
        }
        
        if (!empty($contact['mobile'])) {
            $contactPerson['mobile'] = $contact['mobile'];
        }
        
        if (!empty($contact['function']) || !empty($contact['title'])) {
            $contactPerson['function'] = $contact['function'] ?? $contact['title'];
        }
        
        // Auto-detect country from available data
        $countryInfo = $this->detectCountryFromContactData($contact);
        if ($countryInfo) {
            $contactPerson['country'] = $countryInfo['country'];
            $contactPerson['country_code'] = $countryInfo['country_code'];
        }
        
        // Set defaults for new contacts
        if (!empty($contactPerson)) {
            $contactPerson['receives_quotes'] = true;
            $contactPerson['receives_invoices'] = false;
        }
        
        return $contactPerson;
    }

    /**
     * Detect country information from contact data using phone numbers and other clues
     */
    private function detectCountryFromContactData(array $contact): ?array
    {
        // Try phone number first (most reliable)
        if (!empty($contact['phone'])) {
            $country = $this->detectCountryFromPhoneNumber($contact['phone']);
            if ($country) return $country;
        }
        
        // Try mobile number
        if (!empty($contact['mobile'])) {
            $country = $this->detectCountryFromPhoneNumber($contact['mobile']);
            if ($country) return $country;
        }
        
        // Try email domain for country-specific domains
        if (!empty($contact['email'])) {
            $country = $this->detectCountryFromEmailDomain($contact['email']);
            if ($country) return $country;
        }
        
        // Try company name or address if available
        if (!empty($contact['company'])) {
            $country = $this->detectCountryFromCompanyName($contact['company']);
            if ($country) return $country;
        }
        
        return null;
    }

    /**
     * Detect country from phone number prefix
     */
    private function detectCountryFromPhoneNumber(string $phone): ?array
    {
        // Clean phone number
        $cleanPhone = preg_replace('/[^\d+]/', '', $phone);
        
        // Common international prefixes - focusing on transport/logistics hubs
        $countryPrefixes = [
            // Europe
            '+32' => ['country' => 'Belgium', 'country_code' => 'BE'],
            '+31' => ['country' => 'Netherlands', 'country_code' => 'NL'],
            '+33' => ['country' => 'France', 'country_code' => 'FR'],
            '+49' => ['country' => 'Germany', 'country_code' => 'DE'],
            '+44' => ['country' => 'United Kingdom', 'country_code' => 'GB'],
            '+34' => ['country' => 'Spain', 'country_code' => 'ES'],
            '+39' => ['country' => 'Italy', 'country_code' => 'IT'],
            '+41' => ['country' => 'Switzerland', 'country_code' => 'CH'],
            '+43' => ['country' => 'Austria', 'country_code' => 'AT'],
            '+45' => ['country' => 'Denmark', 'country_code' => 'DK'],
            '+46' => ['country' => 'Sweden', 'country_code' => 'SE'],
            '+47' => ['country' => 'Norway', 'country_code' => 'NO'],
            '+48' => ['country' => 'Poland', 'country_code' => 'PL'],
            '+351' => ['country' => 'Portugal', 'country_code' => 'PT'],
            
            // Africa (major ports/logistics)
            '+212' => ['country' => 'Morocco', 'country_code' => 'MA'],
            '+216' => ['country' => 'Tunisia', 'country_code' => 'TN'],
            '+218' => ['country' => 'Libya', 'country_code' => 'LY'],
            '+220' => ['country' => 'Gambia', 'country_code' => 'GM'],
            '+221' => ['country' => 'Senegal', 'country_code' => 'SN'],
            '+223' => ['country' => 'Mali', 'country_code' => 'ML'],
            '+224' => ['country' => 'Guinea', 'country_code' => 'GN'],
            '+225' => ['country' => 'Ivory Coast', 'country_code' => 'CI'],
            '+226' => ['country' => 'Burkina Faso', 'country_code' => 'BF'],
            '+227' => ['country' => 'Niger', 'country_code' => 'NE'],
            '+228' => ['country' => 'Togo', 'country_code' => 'TG'],
            '+229' => ['country' => 'Benin', 'country_code' => 'BJ'],
            '+230' => ['country' => 'Mauritius', 'country_code' => 'MU'],
            '+231' => ['country' => 'Liberia', 'country_code' => 'LR'],
            '+232' => ['country' => 'Sierra Leone', 'country_code' => 'SL'],
            '+233' => ['country' => 'Ghana', 'country_code' => 'GH'],
            '+234' => ['country' => 'Nigeria', 'country_code' => 'NG'],
            '+235' => ['country' => 'Chad', 'country_code' => 'TD'],
            '+236' => ['country' => 'Central African Republic', 'country_code' => 'CF'],
            '+237' => ['country' => 'Cameroon', 'country_code' => 'CM'],
            '+238' => ['country' => 'Cape Verde', 'country_code' => 'CV'],
            '+239' => ['country' => 'São Tomé and Príncipe', 'country_code' => 'ST'],
            '+240' => ['country' => 'Equatorial Guinea', 'country_code' => 'GQ'],
            '+241' => ['country' => 'Gabon', 'country_code' => 'GA'],
            '+242' => ['country' => 'Republic of the Congo', 'country_code' => 'CG'],
            '+243' => ['country' => 'Democratic Republic of the Congo', 'country_code' => 'CD'],
            '+244' => ['country' => 'Angola', 'country_code' => 'AO'],
            '+245' => ['country' => 'Guinea-Bissau', 'country_code' => 'GW'],
            '+246' => ['country' => 'Diego Garcia', 'country_code' => 'IO'],
            '+248' => ['country' => 'Seychelles', 'country_code' => 'SC'],
            '+249' => ['country' => 'Sudan', 'country_code' => 'SD'],
            '+250' => ['country' => 'Rwanda', 'country_code' => 'RW'],
            '+251' => ['country' => 'Ethiopia', 'country_code' => 'ET'],
            '+252' => ['country' => 'Somalia', 'country_code' => 'SO'],
            '+253' => ['country' => 'Djibouti', 'country_code' => 'DJ'],
            '+254' => ['country' => 'Kenya', 'country_code' => 'KE'],
            '+255' => ['country' => 'Tanzania', 'country_code' => 'TZ'],
            '+256' => ['country' => 'Uganda', 'country_code' => 'UG'],
            '+257' => ['country' => 'Burundi', 'country_code' => 'BI'],
            '+258' => ['country' => 'Mozambique', 'country_code' => 'MZ'],
            '+260' => ['country' => 'Zambia', 'country_code' => 'ZM'],
            '+261' => ['country' => 'Madagascar', 'country_code' => 'MG'],
            '+262' => ['country' => 'Réunion', 'country_code' => 'RE'],
            '+263' => ['country' => 'Zimbabwe', 'country_code' => 'ZW'],
            '+264' => ['country' => 'Namibia', 'country_code' => 'NA'],
            '+265' => ['country' => 'Malawi', 'country_code' => 'MW'],
            '+266' => ['country' => 'Lesotho', 'country_code' => 'LS'],
            '+267' => ['country' => 'Botswana', 'country_code' => 'BW'],
            '+268' => ['country' => 'Eswatini', 'country_code' => 'SZ'],
            '+269' => ['country' => 'Comoros', 'country_code' => 'KM'],
            '+27' => ['country' => 'South Africa', 'country_code' => 'ZA'],
            
            // Middle East
            '+971' => ['country' => 'United Arab Emirates', 'country_code' => 'AE'],
            '+966' => ['country' => 'Saudi Arabia', 'country_code' => 'SA'],
            '+974' => ['country' => 'Qatar', 'country_code' => 'QA'],
            '+965' => ['country' => 'Kuwait', 'country_code' => 'KW'],
            '+973' => ['country' => 'Bahrain', 'country_code' => 'BH'],
            '+968' => ['country' => 'Oman', 'country_code' => 'OM'],
            
            // Asia Pacific
            '+86' => ['country' => 'China', 'country_code' => 'CN'],
            '+91' => ['country' => 'India', 'country_code' => 'IN'],
            '+65' => ['country' => 'Singapore', 'country_code' => 'SG'],
            '+60' => ['country' => 'Malaysia', 'country_code' => 'MY'],
            '+66' => ['country' => 'Thailand', 'country_code' => 'TH'],
            '+84' => ['country' => 'Vietnam', 'country_code' => 'VN'],
            '+62' => ['country' => 'Indonesia', 'country_code' => 'ID'],
            '+63' => ['country' => 'Philippines', 'country_code' => 'PH'],
            '+852' => ['country' => 'Hong Kong', 'country_code' => 'HK'],
            '+853' => ['country' => 'Macau', 'country_code' => 'MO'],
            '+886' => ['country' => 'Taiwan', 'country_code' => 'TW'],
            '+81' => ['country' => 'Japan', 'country_code' => 'JP'],
            '+82' => ['country' => 'South Korea', 'country_code' => 'KR'],
            '+61' => ['country' => 'Australia', 'country_code' => 'AU'],
            '+64' => ['country' => 'New Zealand', 'country_code' => 'NZ'],
            
            // Americas
            '+1' => ['country' => 'United States', 'country_code' => 'US'],
            '+55' => ['country' => 'Brazil', 'country_code' => 'BR'],
            '+54' => ['country' => 'Argentina', 'country_code' => 'AR'],
            '+56' => ['country' => 'Chile', 'country_code' => 'CL'],
            '+57' => ['country' => 'Colombia', 'country_code' => 'CO'],
            '+52' => ['country' => 'Mexico', 'country_code' => 'MX'],
        ];
        
        // Sort by prefix length (longest first for accurate matching)
        uksort($countryPrefixes, function($a, $b) {
            return strlen($b) - strlen($a);
        });
        
        foreach ($countryPrefixes as $prefix => $country) {
            if (str_starts_with($cleanPhone, $prefix)) {
                Log::info('Country detected from phone number', [
                    'phone' => $phone,
                    'prefix' => $prefix,
                    'country' => $country['country'],
                    'country_code' => $country['country_code']
                ]);
                return $country;
            }
        }
        
        return null;
    }

    /**
     * Detect country from email domain
     */
    private function detectCountryFromEmailDomain(string $email): ?array
    {
        $domain = strtolower(substr(strrchr($email, '@'), 1));
        
        // Country-specific email domains
        $domainCountries = [
            // Common country domains in transport/logistics
            'be' => ['country' => 'Belgium', 'country_code' => 'BE'],
            'nl' => ['country' => 'Netherlands', 'country_code' => 'NL'],
            'fr' => ['country' => 'France', 'country_code' => 'FR'],
            'de' => ['country' => 'Germany', 'country_code' => 'DE'],
            'uk' => ['country' => 'United Kingdom', 'country_code' => 'GB'],
            'co.uk' => ['country' => 'United Kingdom', 'country_code' => 'GB'],
            'it' => ['country' => 'Italy', 'country_code' => 'IT'],
            'es' => ['country' => 'Spain', 'country_code' => 'ES'],
            'ch' => ['country' => 'Switzerland', 'country_code' => 'CH'],
            'at' => ['country' => 'Austria', 'country_code' => 'AT'],
            'dk' => ['country' => 'Denmark', 'country_code' => 'DK'],
            'se' => ['country' => 'Sweden', 'country_code' => 'SE'],
            'no' => ['country' => 'Norway', 'country_code' => 'NO'],
            'pl' => ['country' => 'Poland', 'country_code' => 'PL'],
            'ma' => ['country' => 'Morocco', 'country_code' => 'MA'],
            'za' => ['country' => 'South Africa', 'country_code' => 'ZA'],
            'ae' => ['country' => 'United Arab Emirates', 'country_code' => 'AE'],
            'sg' => ['country' => 'Singapore', 'country_code' => 'SG'],
            'cn' => ['country' => 'China', 'country_code' => 'CN'],
            'jp' => ['country' => 'Japan', 'country_code' => 'JP'],
            'au' => ['country' => 'Australia', 'country_code' => 'AU'],
        ];
        
        // Check exact domain match
        if (isset($domainCountries[$domain])) {
            return $domainCountries[$domain];
        }
        
        // Check domain endings
        foreach ($domainCountries as $ccTLD => $country) {
            if (str_ends_with($domain, '.' . $ccTLD)) {
                Log::info('Country detected from email domain', [
                    'email' => $email,
                    'domain' => $domain,
                    'country' => $country['country'],
                    'country_code' => $country['country_code']
                ]);
                return $country;
            }
        }
        
        return null;
    }

    /**
     * Detect country from company name patterns
     */
    private function detectCountryFromCompanyName(string $company): ?array
    {
        $company = strtolower($company);
        
        // Company name patterns that indicate specific countries
        $patterns = [
            // Belgian patterns
            '/\b(bvba|sprl|sa|nv)\b/' => ['country' => 'Belgium', 'country_code' => 'BE'],
            '/\bantwerp|anvers|ghent|gent|bruges|brugge|brussels|bruxelles\b/' => ['country' => 'Belgium', 'country_code' => 'BE'],
            
            // Dutch patterns
            '/\b(bv|nv)\b/' => ['country' => 'Netherlands', 'country_code' => 'NL'],
            '/\bamsterdam|rotterdam|utrecht|eindhoven|tilburg|groningen|breda\b/' => ['country' => 'Netherlands', 'country_code' => 'NL'],
            
            // French patterns
            '/\b(sarl|sas|sa|eurl|scp|sci)\b/' => ['country' => 'France', 'country_code' => 'FR'],
            '/\bparis|marseille|lyon|toulouse|nice|nantes|strasbourg|montpellier|bordeaux|lille\b/' => ['country' => 'France', 'country_code' => 'FR'],
            
            // German patterns
            '/\b(gmbh|ag|kg|ohg|gbr)\b/' => ['country' => 'Germany', 'country_code' => 'DE'],
            '/\bberlin|hamburg|munich|münchen|cologne|köln|frankfurt|stuttgart|düsseldorf|dortmund\b/' => ['country' => 'Germany', 'country_code' => 'DE'],
            
            // UK patterns
            '/\b(ltd|plc|llp)\b/' => ['country' => 'United Kingdom', 'country_code' => 'GB'],
            '/\blondon|manchester|birmingham|leeds|glasgow|sheffield|bradford|liverpool|edinburgh\b/' => ['country' => 'United Kingdom', 'country_code' => 'GB'],
            
            // Italian patterns
            '/\b(srl|spa|snc|sas)\b/' => ['country' => 'Italy', 'country_code' => 'IT'],
            '/\brome|roma|milan|milano|naples|napoli|turin|torino|palermo|genoa|genova\b/' => ['country' => 'Italy', 'country_code' => 'IT'],
            
            // Spanish patterns
            '/\b(sl|sa|scp|cb)\b/' => ['country' => 'Spain', 'country_code' => 'ES'],
            '/\bmadrid|barcelona|valencia|sevilla|zaragoza|málaga|murcia|palma|bilbao\b/' => ['country' => 'Spain', 'country_code' => 'ES'],
            
            // African patterns
            '/\bdakar|senegal\b/' => ['country' => 'Senegal', 'country_code' => 'SN'],
            '/\babidjan|ivory.coast|côte.d.ivoire\b/' => ['country' => 'Ivory Coast', 'country_code' => 'CI'],
            '/\bouagadougou|burkina.faso\b/' => ['country' => 'Burkina Faso', 'country_code' => 'BF'],
            '/\baccra|ghana\b/' => ['country' => 'Ghana', 'country_code' => 'GH'],
            '/\blagos|nigeria\b/' => ['country' => 'Nigeria', 'country_code' => 'NG'],
            '/\bcasablanca|rabat|morocco|maroc\b/' => ['country' => 'Morocco', 'country_code' => 'MA'],
        ];
        
        foreach ($patterns as $pattern => $country) {
            if (preg_match($pattern, $company)) {
                Log::info('Country detected from company name', [
                    'company' => $company,
                    'pattern' => $pattern,
                    'country' => $country['country'],
                    'country_code' => $country['country_code']
                ]);
                return $country;
            }
        }
        
        return null;
    }

    /**
     * Resolve or create client for image/PDF based intake with VIN detection
     */
    public function resolveOrCreateClientForImage(Intake $intake, array $extractionData): ?string
    {
        Log::info('Attempting client resolution for image/PDF intake', [
            'intake_id' => $intake->id,
            'has_contact' => !empty($extractionData['contact']),
            'has_vin_candidates' => !empty($extractionData['vin_candidates']),
            'has_plate_candidates' => !empty($extractionData['plate_candidates'])
        ]);

        // First try normal contact resolution if we have sufficient contact info
        $contactData = $extractionData['contact'] ?? [];
        if (!empty($contactData['email']) || (!empty($contactData['phone']) && !empty($contactData['name']))) {
            $clientId = $this->resolveClientId($extractionData);
            if ($clientId) {
                Log::info('Client resolved via contact data for image intake', [
                    'intake_id' => $intake->id,
                    'client_id' => $clientId
                ]);
                return $clientId;
            }
        }

        // Try VIN-based resolution for vehicle images
        if (!empty($extractionData['vin_candidates'])) {
            foreach ($extractionData['vin_candidates'] as $vinCandidate) {
                $clientId = $this->resolveClientByVin($vinCandidate['vin']);
                if ($clientId) {
                    Log::info('Client resolved via VIN for image intake', [
                        'intake_id' => $intake->id,
                        'vin' => $vinCandidate['vin'],
                        'client_id' => $clientId
                    ]);
                    return $clientId;
                }
            }
        }

        // If no resolution possible, return null (will trigger fallback client creation)
        Log::info('No client resolution possible for image intake', [
            'intake_id' => $intake->id,
            'will_create_fallback' => true
        ]);
        
        return null;
    }

    /**
     * Create fallback client for image/PDF intakes using available data
     */
    public function createFallbackClient(Intake $intake, array $extractionData): ?string
    {
        Log::info('Creating fallback client for image/PDF intake', [
            'intake_id' => $intake->id,
            'extraction_keys' => array_keys($extractionData)
        ]);

        try {
            $client = $this->legacy();
            if (!$client) {
                Log::error('Cannot create fallback client - Robaws client not available');
                return null;
            }

            // Build client data from available information
            $clientData = [
                'name' => $this->generateFallbackClientName($intake, $extractionData),
                'email' => $this->extractFallbackEmail($extractionData),
                'phone' => $this->extractFallbackPhone($extractionData),
                'company' => $this->extractFallbackCompany($extractionData),
                'notes' => $this->generateFallbackNotes($intake, $extractionData),
                'source' => 'image_upload_fallback',
                'created_via' => 'bconnect_image_processing'
            ];

            // Add country detection if possible
            if (!empty($clientData['company'])) {
                $countryData = $this->detectCountryFromCompanyName($clientData['company']);
                if ($countryData) {
                    $clientData['country'] = $countryData['country'];
                    $clientData['country_code'] = $countryData['country_code'];
                }
            }

            Log::info('Creating fallback client with data', [
                'intake_id' => $intake->id,
                'client_data' => array_filter($clientData)
            ]);

            $response = $client->findOrCreateClient($clientData);

            if ($response && isset($response['id'])) {
                $clientId = (string) $response['id'];
                
                Log::info('Fallback client created successfully', [
                    'intake_id' => $intake->id,
                    'client_id' => $clientId,
                    'client_name' => $clientData['name']
                ]);

                // Try to create a contact within this client
                $this->createFallbackContact($clientId, $intake, $extractionData);
                
                return $clientId;
            }

            Log::error('Failed to create fallback client - no ID in response', [
                'intake_id' => $intake->id,
                'response' => $response
            ]);
            return null;

        } catch (\Exception $e) {
            Log::error('Exception creating fallback client', [
                'intake_id' => $intake->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Resolve client by VIN if available in the system
     */
    private function resolveClientByVin(string $vin): ?string
    {
        try {
            $client = $this->legacy();
            if (!$client) {
                return null;
            }

            // TODO: Implement VIN-based client search when available in RobawsClient
            // For now, return null to allow fallback to other methods
            Log::info('VIN-based client resolution not yet implemented', [
                'vin' => $vin
            ]);
            
            return null;

        } catch (\Exception $e) {
            Log::warning('VIN-based client resolution failed', [
                'vin' => $vin,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Generate fallback client name from available data
     */
    private function generateFallbackClientName(Intake $intake, array $extractionData): string
    {
        // Try contact name first
        if (!empty($extractionData['contact']['name'])) {
            return $extractionData['contact']['name'];
        }

        // Try company name
        if (!empty($extractionData['contact']['company'])) {
            return $extractionData['contact']['company'];
        }

        // Try VIN-based name with better formatting
        if (!empty($extractionData['vin_candidates'])) {
            $vin = $extractionData['vin_candidates'][0]['vin'] ?? $extractionData['vin_candidates'][0];
            if (is_string($vin) && strlen($vin) >= 8) {
                return "Client - VIN: " . substr($vin, -8);
            }
        }

        // Try to extract vehicle information for a meaningful name
        if (!empty($extractionData['vehicle']['make']) || !empty($extractionData['vehicle']['model'])) {
            $make = $extractionData['vehicle']['make'] ?? 'Vehicle';
            $model = $extractionData['vehicle']['model'] ?? '';
            return trim("Client - {$make} {$model} Owner");
        }

        // Check for any identifiable information in OCR text
        if (!empty($extractionData['ocr_text'])) {
            // Look for common patterns that might indicate a name
            $text = $extractionData['ocr_text'];
            
            // Look for email patterns to derive name
            if (preg_match('/([a-zA-Z]+\.[a-zA-Z]+)@/', $text, $matches)) {
                $nameParts = explode('.', $matches[1]);
                if (count($nameParts) >= 2) {
                    return ucfirst($nameParts[0]) . ' ' . ucfirst($nameParts[1]);
                }
            }
            
            // Look for phone number patterns to create identifier
            if (preg_match('/(\+?\d{2,3}[\s-]?\d{3}[\s-]?\d{2,3}[\s-]?\d{2,3})/', $text, $matches)) {
                $phone = preg_replace('/[^\d]/', '', $matches[1]);
                if (strlen($phone) >= 8) {
                    return "Client - Phone: " . substr($phone, -4);
                }
            }
        }

        // Use intake customer name if available
        if (!empty($intake->customer_name)) {
            return $intake->customer_name;
        }

        // Use file name if meaningful
        $firstFile = $intake->files->first();
        if ($firstFile && $firstFile->original_filename) {
            $filename = pathinfo($firstFile->original_filename, PATHINFO_FILENAME);
            if (strlen($filename) > 5 && !preg_match('/^(IMG|DSC|Photo|Screenshot)/', $filename)) {
                return "Client - " . ucwords(str_replace(['-', '_'], ' ', $filename));
            }
        }

        // Create a time-based identifier for uniqueness
        $timestamp = $intake->created_at->format('Ymd-Hi');
        return "Image Client - {$timestamp}";
    }

    /**
     * Extract fallback email from various sources
     */
    private function extractFallbackEmail(array $extractionData): ?string
    {
        return $extractionData['contact']['email'] ?? null;
    }

    /**
     * Extract fallback phone from various sources
     */
    private function extractFallbackPhone(array $extractionData): ?string
    {
        return $extractionData['contact']['phone'] ?? null;
    }

    /**
     * Extract fallback company from various sources
     */
    private function extractFallbackCompany(array $extractionData): ?string
    {
        return $extractionData['contact']['company'] ?? null;
    }

    /**
     * Generate comprehensive notes for fallback client
     */
    private function generateFallbackNotes(Intake $intake, array $extractionData): string
    {
        $notes = ["Created from image/PDF upload (Intake ID: {$intake->id})"];

        if (!empty($extractionData['vin_candidates'])) {
            $vins = array_column($extractionData['vin_candidates'], 'vin');
            $notes[] = "Detected VIN(s): " . implode(', ', $vins);
        }

        if (!empty($extractionData['plate_candidates'])) {
            $plates = array_column($extractionData['plate_candidates'], 'plate');
            $notes[] = "Detected License Plate(s): " . implode(', ', $plates);
        }

        if (!empty($extractionData['vehicle'])) {
            $vehicle = $extractionData['vehicle'];
            $vehicleInfo = array_filter([
                $vehicle['make'] ?? null,
                $vehicle['model'] ?? null,
                $vehicle['year'] ?? null
            ]);
            if ($vehicleInfo) {
                $notes[] = "Vehicle: " . implode(' ', $vehicleInfo);
            }
        }

        if (!empty($extractionData['extraction_confidence'])) {
            $notes[] = "Extraction confidence: {$extractionData['extraction_confidence']}%";
        }

        return implode('. ', $notes) . '.';
    }

    /**
     * Create a fallback contact within the client
     */
    private function createFallbackContact(string $clientId, Intake $intake, array $extractionData): void
    {
        try {
            Log::info('Creating fallback contact for client', [
                'client_id' => $clientId,
                'intake_id' => $intake->id
            ]);

            // For now, we'll log the intention to create a contact
            // This can be implemented when the RobawsClient has contact creation methods
            $contactData = [
                'client_id' => $clientId,
                'name' => $this->generateFallbackContactName($intake, $extractionData),
                'email' => $this->extractFallbackEmail($extractionData),
                'phone' => $this->extractFallbackPhone($extractionData),
                'notes' => $this->generateFallbackNotes($intake, $extractionData)
            ];

            Log::info('Contact data prepared (awaiting API implementation)', [
                'client_id' => $clientId,
                'contact_data' => array_filter($contactData)
            ]);

            // TODO: Implement contact creation when RobawsClient supports it
            // $client = $this->legacy();
            // $client->createContact($contactData);

        } catch (\Exception $e) {
            Log::warning('Failed to create fallback contact', [
                'client_id' => $clientId,
                'intake_id' => $intake->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Generate fallback contact name
     */
    private function generateFallbackContactName(Intake $intake, array $extractionData): string
    {
        // Try contact name from extraction
        if (!empty($extractionData['contact']['name'])) {
            return $extractionData['contact']['name'];
        }

        // Try to derive from email
        if (!empty($extractionData['contact']['email'])) {
            $email = $extractionData['contact']['email'];
            $localPart = explode('@', $email)[0];
            if (strpos($localPart, '.') !== false) {
                $parts = explode('.', $localPart);
                return ucfirst($parts[0]) . ' ' . ucfirst($parts[1]);
            }
            return ucfirst($localPart);
        }

        // Use intake customer name if available
        if (!empty($intake->customer_name)) {
            return $intake->customer_name;
        }

        // Generic fallback
        return "Contact";
    }
}
