<?php

namespace App\Services\Export\Mappers;

use App\Models\Intake;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class RobawsMapper
{
    private $apiClient;

    public function __construct(\App\Services\Export\Clients\RobawsApiClient $apiClient = null)
    {
        $this->apiClient = $apiClient;
    }
    /**
     * Schema defining the exact field codes and types expected by Robaws
     */
    private const EXTRA_SCHEMA = [
        // Routing
        'POR'   => 'TEXT',
        'POL'   => 'TEXT',
        'POD'   => 'TEXT',
        'FDEST' => 'TEXT',

        // Cargo
        'CARGO'                => 'TEXT',
        'CONTAINER_NR'         => 'TEXT',
        'DIM_BEF_DELIVERY'     => 'TEXT',

        // Shipping
        'TRANSPORT_COMPANY' => 'TEXT',
        'SHIPPING_LINE'     => 'TEXT',
        'METHOD'            => 'SELECT',
        'TRANSIT_TIME'      => 'TEXT',
        'VESSEL'            => 'TEXT',
        'VOYAGE'            => 'TEXT',
        'ETC'               => 'DATE',
        'ETS'               => 'DATE',
        'ETA'               => 'DATE',

        // Prices
        'SEAFREIGHT'        => 'NUMBER',
        'PRE_CARRIAGE'      => 'NUMBER',
        'CUSTOMS_ORIGIN'    => 'NUMBER',
        'DESTINATION'       => 'NUMBER',
        'CUSTOMS_DEST'      => 'NUMBER',
        'ONCARRIAGE'        => 'NUMBER',
        'INSURANCE'         => 'NUMBER',

        // Automation / notes
        'JSON'                   => 'TEXT',      // Important: JSON should be TEXT not TEXTAREA
        'EXTRACTED_INFORMATION'  => 'TEXTAREA',

        // Flags
        'URGENT'  => 'CHECKBOX',
        'FOLLOW'  => 'CHECKBOX',

        // Optional mirrors for convenience
        'CUSTOMER'       => 'TEXT',
        'CONTACT'        => 'TEXT',
        'CONTACT_EMAIL'  => 'TEXT',
        'CONCERNING'     => 'TEXT',
    ];

    /**
     * Wraps a scalar into the typed Robaws extraFields entry.
     * Returns null when value is empty/invalid for the type.
     * Format matches official Robaws API documentation.
     */
    private function wrapExtra(string $code, $value): ?array
    {
        $type = self::EXTRA_SCHEMA[$code] ?? 'TEXT';
        if ($value === null) return null;

        // Normalize scalars
        if (is_array($value) || is_object($value)) {
            // Only JSON field may receive arrays/objects — encode to string
            if ($code !== 'JSON') return null;
            $value = json_encode($value, \JSON_UNESCAPED_UNICODE|\JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        // Trim strings
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') return null;
        }

        switch ($type) {
            case 'TEXT':
            case 'TEXTAREA':
            case 'SELECT':   // still sent as stringValue
                return ['stringValue' => (string) $value];

            case 'DATE':     // Convert to YYYY-MM-DD format for dateValue
                try {
                    $date = \Carbon\Carbon::parse($value)->format('Y-m-d');
                    return ['dateValue' => $date];
                } catch (\Exception $e) {
                    // If parsing fails, treat as string
                    return ['stringValue' => (string) $value];
                }

            case 'NUMBER':
                if (!is_numeric($value)) return null;
                // Check if it's a decimal number
                if (is_float($value) || strpos((string) $value, '.') !== false) {
                    return ['decimalValue' => (float) $value];
                } else {
                    return ['integerValue' => (int) $value];
                }

            case 'CHECKBOX':
                // Accept truthy/falsy; cast to bool, use booleanValue
                return ['booleanValue' => (bool) $value];

            default:
                // Fallback to TEXT
                return ['stringValue' => (string) $value];
        }
    }

    /**
     * Map intake data to Robaws format
     */
    public function mapIntakeToRobaws(Intake $intake, array $extractionData = []): array
    {
        // Get extraction data from intake or documents
        if (empty($extractionData)) {
            $extractionData = $this->getExtractionData($intake);
        }

        // Extract nested data structures from actual extraction format
        // Check both direct access and raw_data nested structure
        $rawData = $extractionData['raw_data'] ?? [];
        $documentData = $extractionData['document_data'] ?? [];
        
        $vehicle = $documentData['vehicle'] ?? $extractionData['vehicle'] ?? $rawData['vehicle'] ?? [];
        $shipping = $documentData['shipping'] ?? $extractionData['shipping'] ?? $rawData['shipping'] ?? [];
        $contact = $documentData['contact'] ?? $extractionData['contact'] ?? $rawData['contact'] ?? [];
        $shipment = $documentData['shipment'] ?? $extractionData['shipment'] ?? $rawData['shipment'] ?? [];
        $dates = $extractionData['dates'] ?? $rawData['dates'] ?? [];
        $pricing = $extractionData['pricing'] ?? $rawData['pricing'] ?? [];

        // Handle flat field structure (from image extraction) by building nested arrays
        if (empty($vehicle) && !empty($rawData)) {
            $vehicle = [
                'make' => $rawData['vehicle_make'] ?? null,
                'model' => $rawData['vehicle_model'] ?? null,
                'year' => $rawData['vehicle_year'] ?? null,
                'condition' => $rawData['vehicle_condition'] ?? null,
                'dimensions' => $rawData['dimensions'] ?? null,
                'weight' => $rawData['weight'] ?? null,
                'fuel_type' => $rawData['fuel_type'] ?? null,
                'cargo_description' => $rawData['cargo_description'] ?? null,
            ];
        }

        if (empty($shipment) && !empty($rawData)) {
            $shipment = [
                'origin' => $rawData['origin'] ?? null,
                'destination' => $rawData['destination'] ?? null,
                'shipping_type' => $rawData['shipment_type'] ?? null,
            ];
        }

        if (empty($shipping) && !empty($rawData)) {
            // Build shipping structure from flat fields
            $shipping = [
                'route' => [
                    'origin' => ['city' => $this->extractCityFromLocation($rawData['origin'] ?? ''), 'country' => $this->extractCountryFromLocation($rawData['origin'] ?? '')],
                    'destination' => ['city' => $this->extractCityFromLocation($rawData['destination'] ?? ''), 'country' => $this->extractCountryFromLocation($rawData['destination'] ?? '')],
                ],
                'method' => $this->mapShippingMethod($rawData['shipment_type'] ?? null),
            ];
        }

        // Build enhanced customer data for client creation
        $customerData = $this->extractEnhancedCustomerData($extractionData, $intake);

        // Map to Robaws structure
        return [
            'quotation_info' => $this->mapQuotationInfo($intake, $contact, $vehicle, $shipping, $extractionData),
            'routing' => $this->mapRouting($shipping, $shipment, $extractionData, $pricing),
            'cargo_details' => $this->mapCargoDetails($vehicle, $extractionData),
            'internal_remarks' => $this->mapInternalRemarks($intake, $extractionData),
            'automation' => $this->mapAutomation($extractionData),
            'shipping' => $this->mapShipping($shipping, $dates),
            'payments' => $this->mapPayments($pricing, $extractionData),
            'offer_extra_info' => $this->mapOfferExtraInfo($extractionData),
            'customer_data' => $customerData, // Pass enhanced customer data for client creation
            'extraction_data' => $extractionData, // Pass extraction data for placeholder generation
        ];
    }

    /**
     * Convert sectioned payload to Robaws API format with extraFields structure
     */
    public function toRobawsApiPayload(array $mapped): array
    {
        $q  = $mapped['quotation_info'] ?? [];
        $r  = $mapped['routing'] ?? [];
        $c  = $mapped['cargo_details'] ?? [];
        $s  = $mapped['shipping'] ?? [];
        $p  = $mapped['payments'] ?? [];
        $au = $mapped['automation'] ?? [];

        // Use passed client ID or fallback to API resolution (with backward compatibility)
        $clientId = $mapped['client_id'] ?? $mapped['customer_id'] ?? null;
        if (!$clientId && $this->apiClient) {
            $clientId = $this->apiClient->findClientId(
                $q['customer'] ?? null,
                $q['contact_email'] ?? null
            );
        }

        $payload = [
            'title'           => $q['customer_reference'] ?? ($q['project'] ?? null),
            'project'         => $q['project'] ?? null,
            'clientReference' => $q['customer_reference'] ?? $q['client_reference'] ?? null,
            'contactEmail'    => $q['contact_email'] ?? null,
            'customerId'      => $clientId !== null ? (int) $clientId : null, // Top-level for Customer binding
            'clientId'        => $clientId !== null ? (int) $clientId : null, // Also set clientId for compatibility
            'companyId'       => config('services.robaws.default_company_id', config('services.robaws.company_id', 1)),
        ];

        $xf = [];

        // --- Client/Customer Template Placeholders ---
        // Extract customer data directly from extraction_data with enhanced fields
        if (!empty($mapped['extraction_data'])) {
            $extractionData = $mapped['extraction_data'];
            $rawData = $extractionData['raw_data'] ?? [];
            $contact = $extractionData['contact'] ?? [];
            
            // Build customer data directly from extraction
            $customerName = $rawData['company'] ?? 
                           $contact['company'] ?? 
                           $extractionData['customer_name'] ?? 
                           $contact['name'] ?? 
                           $q['customer'] ?? 
                           'Unknown Customer';
                           
            $email = $contact['email'] ?? $rawData['email'] ?? $extractionData['email'] ?? '';
            $phone = $contact['phone'] ?? $rawData['phone'] ?? $extractionData['phone'] ?? '';
            $mobile = $contact['mobile'] ?? $rawData['mobile'] ?? $extractionData['mobile'] ?? '';
            
            // Extract enhanced fields from raw_data
            $vatNumber = $rawData['vat'] ?? $rawData['vat_number'] ?? $extractionData['vat_number'] ?? '';
            if ($vatNumber && !str_starts_with($vatNumber, 'BE')) {
                $vatNumber = 'BE' . preg_replace('/[^0-9]/', '', $vatNumber);
            }
            
            $website = $rawData['website'] ?? $extractionData['website'] ?? '';
            if ($website && !preg_match('/^https?:\/\//', $website)) {
                $website = 'www.' . ltrim($website, 'www.');
            }
            
            // Build address
            $address = $rawData['address'] ?? '';
            $street = $rawData['street'] ?? '';
            $city = $rawData['city'] ?? '';
            $zip = $rawData['zip'] ?? '';
            $country = $rawData['country'] ?? 'Belgium';
            
            // Build full address string if components exist
            if (!$address && ($street || $city)) {
                $addressParts = array_filter([$street, $zip . ' ' . $city, $country]);
                $address = implode(', ', $addressParts);
            }
            
            // Normalize phone numbers
            $phone = $this->normalizePhoneNumber($phone);
            $mobile = $this->normalizePhoneNumber($mobile);
            
            // Build placeholders directly using correct Robaws template field names
            $xf = array_merge($xf, array_filter([
                'client' => $customerName ? ['stringValue' => $customerName] : null,                    // ${client}
                'clientAddress' => $address ? ['stringValue' => $address] : null,                      // ${clientAddress}
                'clientStreet' => $street ? ['stringValue' => $street] : null,                         // ${clientStreet}
                'clientZipcode' => $zip ? ['stringValue' => $zip] : null,                              // ${clientZipcode}
                'clientCity' => $city ? ['stringValue' => $city] : null,                              // ${clientCity}
                'clientCountry' => $country ? ['stringValue' => $country] : null,                     // ${clientCountry}
                'clientTel' => $phone ? ['stringValue' => $phone] : null,                             // ${clientTel}
                'clientGsm' => $mobile ? ['stringValue' => $mobile] : null,                           // ${clientGsm}
                'clientEmail' => $email ? ['stringValue' => $email] : null,                           // ${clientEmail}
                'clientVat' => $vatNumber ? ['stringValue' => $vatNumber] : null,                     // ${clientVat}
                'CONTACT_NAME' => ($contact['name'] ?? '') ? ['stringValue' => $contact['name']] : null,
                'CONTACT_EMAIL' => $email ? ['stringValue' => $email] : null,
                'CONTACT_TEL' => $phone ? ['stringValue' => $phone] : null,
                'CONTACT_GSM' => $mobile ? ['stringValue' => $mobile] : null,
            ], fn($v) => $v !== null));
            
            // Update/create contact person phone numbers using existing upsert logic
            if ($clientId && ($phone || $mobile) && $this->apiClient) {
                try {
                    // Parse full name into first and last name
                    $fullName = $contact['name'] ?? 'Contact Person';
                    $nameParts = explode(' ', trim($fullName), 2);
                    $firstName = $nameParts[0] ?? 'Contact';
                    $lastName = $nameParts[1] ?? 'Person';
                    
                    $contactData = array_filter([
                        'email' => $q['contact_email'] ?? null,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'phone' => $phone ?: null,
                        'mobile' => $mobile ?: null,
                        'isPrimary' => true
                    ]);
                    
                    if (!empty($contactData['email'])) {
                        \Illuminate\Support\Facades\Log::info('Attempting to upsert contact person with phone numbers', [
                            'client_id' => $clientId,
                            'contact_email' => $contactData['email'],
                            'phone' => $phone,
                            'mobile' => $mobile
                        ]);
                        
                        $this->apiClient->upsertPrimaryContact($clientId, $contactData);
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('Failed to upsert contact person phone numbers', [
                        'client_id' => $clientId,
                        'error' => $e->getMessage(),
                        'contact_email' => $q['contact_email'] ?? null
                    ]);
                }
            }
        }

        // --- Routing ---
        $xf['POR']   = $this->wrapExtra('POR',   $r['por']   ?? null);
        $xf['POL']   = $this->wrapExtra('POL',   $r['pol']   ?? null);
        $xf['POD']   = $this->wrapExtra('POD',   $r['pod']   ?? null);
        $xf['FDEST'] = $this->wrapExtra('FDEST', $r['fdest'] ?? null);

        // --- Cargo ---
        $xf['CARGO']               = $this->wrapExtra('CARGO', $c['cargo'] ?? null);
        $xf['CONTAINER_NR']        = $this->wrapExtra('CONTAINER_NR', $c['container_nr'] ?? null);
        $xf['DIM_BEF_DELIVERY']    = $this->wrapExtra('DIM_BEF_DELIVERY', $c['dimensions_text'] ?? null);

        // --- Shipping ---
        $xf['TRANSPORT_COMPANY'] = $this->wrapExtra('TRANSPORT_COMPANY', $s['transport_company'] ?? null);
        $xf['SHIPPING_LINE']     = $this->wrapExtra('SHIPPING_LINE',     $s['shipping_line'] ?? null);
        $xf['METHOD']            = $this->wrapExtra('METHOD',            $s['method'] ?? null);
        $xf['TRANSIT_TIME']      = $this->wrapExtra('TRANSIT_TIME',      $s['transit_time'] ?? null);
        $xf['VESSEL']            = $this->wrapExtra('VESSEL',            $s['vessel'] ?? null);
        $xf['VOYAGE']            = $this->wrapExtra('VOYAGE',            $s['voyage'] ?? null);
        $xf['ETC']               = $this->wrapExtra('ETC',               $s['etc'] ?? null);
        $xf['ETS']               = $this->wrapExtra('ETS',               $s['ets'] ?? null);
        $xf['ETA']               = $this->wrapExtra('ETA',               $s['eta'] ?? null);

        // --- Payments ---
        $amt = fn($v) => is_array($v) ? ($v['amount'] ?? null) : $v;
        $xf['SEAFREIGHT']      = $this->wrapExtra('SEAFREIGHT',      $amt($p['seafreight'] ?? null));
        $xf['PRE_CARRIAGE']    = $this->wrapExtra('PRE_CARRIAGE',    $amt($p['pre_carriage'] ?? null));
        $xf['CUSTOMS_ORIGIN']  = $this->wrapExtra('CUSTOMS_ORIGIN',  $amt($p['customs_origin'] ?? null));
        $xf['DESTINATION']     = $this->wrapExtra('DESTINATION',     $amt($p['destination_charges'] ?? null));
        $xf['CUSTOMS_DEST']    = $this->wrapExtra('CUSTOMS_DEST',    $amt($p['customs_destination'] ?? null));
        $xf['ONCARRIAGE']      = $this->wrapExtra('ONCARRIAGE',      $amt($p['oncarriage'] ?? null));
        $xf['INSURANCE']       = $this->wrapExtra('INSURANCE',       $amt($p['insurance'] ?? null));

        // --- Automation / notes ---
        // Skip JSON field for image-based extractions to prevent API errors
        $jsonData = $au['json'] ?? null;
        if ($jsonData && strlen($jsonData) > 5000) {
            // For very large JSON data, skip it entirely to prevent API 500 errors
            $jsonData = null;
        }
        $xf['JSON']                  = $this->wrapExtra('JSON',                  $jsonData);
        $xf['EXTRACTED_INFORMATION'] = $this->wrapExtra('EXTRACTED_INFORMATION', $au['extracted_information'] ?? null);

        // --- Flags ---
        $xf['URGENT'] = $this->wrapExtra('URGENT', false);
        $xf['FOLLOW'] = $this->wrapExtra('FOLLOW', false);

        // Optional mirrors
        $xf['CUSTOMER']      = $this->wrapExtra('CUSTOMER',      $q['customer'] ?? null);
        $xf['CONTACT']       = $this->wrapExtra('CONTACT',       $q['contact'] ?? null);
        $xf['CONTACT_EMAIL'] = $this->wrapExtra('CONTACT_EMAIL', $q['contact_email'] ?? null);
        $xf['CONCERNING']    = $this->wrapExtra('CONCERNING',    $q['concerning'] ?? null);

        // Strip nulls (unknown/missing)
        $xf = array_filter($xf);

        if (!empty($xf)) {
            $payload['extraFields'] = $xf;
        }

        // Only filter out NULL values, keep 0/false/empty string if they're valid
        return array_filter($payload, static fn($v) => $v !== null);
    }

    /**
     * Convert nested/sectioned payload to flat Robaws field names (LEGACY)
     */
    public function toRobawsPayloadFlat(array $mapped): array
    {
        $q  = $mapped['quotation_info'] ?? [];
        $r  = $mapped['routing'] ?? [];
        $c  = $mapped['cargo_details'] ?? [];
        $s  = $mapped['shipping'] ?? [];
        $p  = $mapped['payments'] ?? [];
        $au = $mapped['automation'] ?? [];
        $ir = $mapped['internal_remarks'] ?? [];
        $oe = $mapped['offer_extra_info'] ?? [];

        return array_filter([
            // Quotation / customer fields
            'date'            => $q['date'] ?? null,
            'project'         => $q['project'] ?? null,
            'customer'        => $q['customer'] ?? null,
            'contact'         => $q['contact'] ?? null,
            'contact_email'   => $q['contact_email'] ?? null,
            'customer_reference' => $q['customer_reference'] ?? null,
            'concerning'      => $q['concerning'] ?? null,
            'status'          => $q['status'] ?? null,
            'assignee'        => $q['assignee'] ?? null,
            'endcustomer'     => $q['endcustomer'] ?? null,

            // Routing fields
            'por'             => $r['por'] ?? null,
            'pol'             => $r['pol'] ?? null,
            'pot'             => $r['pot'] ?? null,
            'pod'             => $r['pod'] ?? null,
            'fdest'           => $r['fdest'] ?? null,
            'in_transit_to'   => $r['in_transit_to'] ?? null,

            // Cargo fields
            'cargo'           => $c['cargo'] ?? null,
            'dimensions_text' => $c['dimensions_text'] ?? $c['dim_bef_delivery'] ?? null,
            'container_nr'    => $c['container_nr'] ?? null,

            // Shipping fields
            'shipping_line'   => $s['shipping_line'] ?? null,
            'transport_company' => $s['transport_company'] ?? null,
            'warehouse'       => $s['warehouse'] ?? null,
            'vessel'          => $s['vessel'] ?? null,
            'voyage'          => $s['voyage'] ?? null,
            'ets'             => $s['ets'] ?? null,
            'etc'             => $s['etc'] ?? null,
            'eta'             => $s['eta'] ?? null,
            'transit_time'    => $s['transit_time'] ?? null,
            'next_sailings'   => $s['next_sailings'] ?? null,
            'pol_forwarder'   => $s['pol_forwarder'] ?? null,
            'pod_forwarder_dropdown' => $s['pod_forwarder_dropdown'] ?? null,
            'booking_pol_and_ref' => $s['booking_pol_and_ref'] ?? null,

            // Payment fields (extract amount if array)
            'seafreight'      => is_array($p['seafreight'] ?? null) ? ($p['seafreight']['amount'] ?? null) : ($p['seafreight'] ?? null),
            'pre_carriage'    => is_array($p['pre_carriage'] ?? null) ? ($p['pre_carriage']['amount'] ?? null) : ($p['pre_carriage'] ?? null),
            'customs_origin'  => is_array($p['customs_origin'] ?? null) ? ($p['customs_origin']['amount'] ?? null) : ($p['customs_origin'] ?? null),
            'customs_destination' => is_array($p['customs_destination'] ?? null) ? ($p['customs_destination']['amount'] ?? null) : ($p['customs_destination'] ?? null),
            'destination_charges' => is_array($p['destination_charges'] ?? null) ? ($p['destination_charges']['amount'] ?? null) : ($p['destination_charges'] ?? null),
            'fob_charges'     => is_array($p['fob_charges'] ?? null) ? ($p['fob_charges']['amount'] ?? null) : ($p['fob_charges'] ?? null),
            'insurance'       => is_array($p['insurance'] ?? null) ? ($p['insurance']['amount'] ?? null) : ($p['insurance'] ?? null),
            'oncarriage'      => is_array($p['oncarriage'] ?? null) ? ($p['oncarriage']['amount'] ?? null) : ($p['oncarriage'] ?? null),

            // Internal remarks
            'urgent'          => $ir['urgent'] ?? null,
            'follow'          => $ir['follow'] ?? null,
            'followed_by'     => $ir['followed_by'] ?? null,
            'follow_up'       => $ir['follow_up'] ?? null,
            'calculation'     => $ir['calculation'] ?? null,
            'inspection_call' => $ir['inspection_call'] ?? null,
            'weblink'         => $ir['weblink'] ?? null,
            'external_sales_person' => $ir['external_sales_person'] ?? null,

            // Offer extra info
            'payment_conditions' => $oe['payment_conditions'] ?? null,
            'remarks'         => $oe['remarks'] ?? null,
            'remarks_drop_down' => $oe['remarks_drop_down'] ?? null,

            // Automation fields (JSON data)
            'extracted_information' => $au['extracted_information'] ?? null,
            'json'            => $au['json'] ?? null,
        ], fn($v) => !is_null($v) && $v !== '');
    }

    /**
     * Get extraction data from intake or its documents - using recursive merge
     */
    private function getExtractionData(Intake $intake): array
    {
        // Use the intake's extraction_data attribute (not a relationship)
        $base = $intake->extraction_data ?? [];
        
        // Handle case where extraction_data is stored as JSON string
        if (is_string($base)) {
            $base = json_decode($base, true) ?? [];
        }
        
        // Ensure $base is always an array
        if (!is_array($base)) {
            $base = [];
        }

        foreach ($intake->documents as $doc) {
            $docData = $doc->extraction?->extracted_data ?? [];
            
            // Handle case where extracted_data is stored as JSON string
            if (is_string($docData)) {
                $docData = json_decode($docData, true) ?? [];
            }
            
            // Ensure $docData is always an array
            if (!is_array($docData)) {
                $docData = [];
            }
            
            $base = array_replace_recursive($base, $docData);
        }

        return $base;
    }

    /**
     * Extract city from location string
     */
    private function extractCityFromLocation(string $location): string
    {
        // Handle common patterns like "Beverly Hills Car Club" -> "Beverly Hills"
        // or "Antwerpen" -> "Antwerpen"
        $location = trim($location);
        
        // If it contains "Car Club" or similar, extract the city part
        if (str_contains($location, 'Car Club')) {
            return trim(str_replace('Car Club', '', $location));
        }
        
        // For simple city names, return as is
        return $location;
    }

    /**
     * Extract country from location string or map to known countries
     */
    private function extractCountryFromLocation(string $location): string
    {
        $location = strtolower(trim($location));
        
        // Map common locations to countries
        $locationMap = [
            'antwerpen' => 'Belgium',
            'antwerp' => 'Belgium',
            'beverly hills' => 'USA',
            'los angeles' => 'USA',
            'california' => 'USA',
            'bruxelles' => 'Belgium',
            'brussels' => 'Belgium',
            'djeddah' => 'Saudi Arabia',
            'jeddah' => 'Saudi Arabia',
        ];

        foreach ($locationMap as $key => $country) {
            if (str_contains($location, $key)) {
                return $country;
            }
        }

        return 'Unknown';
    }

    /**
     * Map shipping method from extraction to standard terms
     */
    private function mapShippingMethod(?string $method): string
    {
        if (!$method) return '';
        
        $method = strtolower(trim($method));
        
        $methodMap = [
            'lcl' => 'LCL',
            'fcl' => 'FCL', 
            'roro' => 'RoRo',
            'ro-ro' => 'RoRo',
            'air' => 'Air Freight',
            'truck' => 'Road Transport',
        ];

        return $methodMap[$method] ?? ucfirst($method);
    }

    /**
     * Map quotation info section
     */
    private function mapQuotationInfo(Intake $intake, array $contact, array $vehicle, array $shipping, array $extractionData = []): array
    {
        // Extract customer name from company first, then fallback to contact name
        $rawData = $extractionData['raw_data'] ?? [];
        $customerName = $rawData['company'] ?? 
                       $contact['company'] ?? 
                       $extractionData['customer_name'] ?? 
                       $contact['name'] ?? 
                       $intake->customer_name ?? 
                       '';

        return [
            'date' => Carbon::now()->toDateString(), // ISO format internally
            'project' => $this->extractProject($vehicle),
            'customer' => $customerName,
            'contact' => $this->extractContactPerson($contact, $shipping),
            'endcustomer' => $contact['company'] ?? '',
            'contact_email' => $contact['email'] ?? $intake->customer_email ?? '',
            'customer_reference' => $this->generateConcerning($vehicle, $shipping, $extractionData),
            'concerning' => '', // Empty since we moved content to customer_reference
            'status' => 'pending',
            'assignee' => $contact['email'] ?? 'sales@truck-time.com',
            'winst' => '',
        ];
    }

    /**
     * Map routing section
     */
    private function mapRouting(array $shipping, array $shipment, array $extractionData = [], array $pricing = []): array
    {
        $route = $shipping['route'] ?? [];
        $origin = $route['origin'] ?? [];
        $destination = $route['destination'] ?? [];

        // Top-level fields take precedence over nested data
        $topLevelOrigin = $extractionData['origin'] ?? null;
        $topLevelDestination = $extractionData['destination'] ?? null;

        // Get origin and destination from shipment data
        $originValue = $topLevelOrigin ?: ($this->formatLocation($origin) ?: ($shipment['origin'] ?? ''));
        $destinationValue = $topLevelDestination ?: ($this->formatLocation($destination) ?: ($shipment['destination'] ?? ''));

        // Determine if this is port-to-port or door-to-door shipping
        $isPortToPort = $this->isPortToPortShipping($originValue, $destinationValue, $extractionData, $pricing);

        // For port-to-port shipping (like Antwerp to Dar es Salaam):
        if ($isPortToPort) {
            // POR (Port of Receipt) = empty (no inland pickup)
            $porValue = '';
            // POL (Port of Loading) = origin port, but map Germany to Antwerp
            $polValue = $this->shouldMapToDefaultPort($originValue) 
                ? $this->getDefaultPortForLocation($originValue) 
                : $originValue;
            // POD (Port of Discharge) = destination port, but map certain countries to default ports
            $podValue = $this->shouldMapToDefaultPort($destinationValue)
                ? $this->getDefaultPortForLocation($destinationValue)
                : $destinationValue;
            // FDEST (Final Destination) = empty (no inland delivery)
            $fDestValue = '';
        } else {
            // For door-to-door shipping:
            $porValue = $originValue;
            $polValue = $this->getDefaultPortForLocation($originValue);
            $podValue = $this->getDefaultPortForLocation($destinationValue);
            $fDestValue = $destinationValue;
        }

        // Normalize port names
        // NOTE: If podValue or polValue contains combined ports (e.g., "CAS/TFN"),
        // they should be resolved using PortResolutionService->resolveManyWithReport().
        // This will split and resolve each port separately, never creating combined ports.
        $polValue = $this->normalizePortNames($polValue);
        $podValue = $this->normalizePortNames($podValue);

        return [
            'por' => $porValue,
            'pol' => $polValue,
            'pot' => '', // Transit port if available
            'pod' => $podValue,
            'fdest' => $fDestValue,
            'in_transit_to' => '',
        ];
    }

    /**
     * Map cargo details section with enhanced dimensions and weight handling
     */
    private function mapCargoDetails(array $vehicle, array $extractionData): array
    {
        $dimensions = $vehicle['dimensions'] ?? [];
        $dimString = '';
        
        // Generate enhanced dimensions string with cargo info for DIM_BEF_DELIVERY
        if (is_array($dimensions)) {
            [$L, $W, $H] = $this->normalizeDimensions($dimensions);
            if ($L && $W && $H) {
                // Build enhanced dimension string: "1 x Motorgrader L: 10.06m x W: 2.52m x H: 3.12m (79.10m³) // 18,750 kg"
                $parts = [];
                
                // Add quantity and type with enhanced description
                $quantity = $vehicle['quantity'] ?? 1;
                $parts[] = $quantity . ' x';
                
                // Use full_description if available for enhanced formatting
                if (!empty($vehicle['full_description'])) {
                    $parts[] = $vehicle['full_description'];
                } elseif (!empty($vehicle['type'])) {
                    $parts[] = $vehicle['type'];
                } elseif (!empty($vehicle['brand']) && !empty($vehicle['model'])) {
                    $condition = $this->getVehicleCondition($vehicle, $extractionData['raw_email_content'] ?? '');
                    $description = trim($condition . ' ' . $vehicle['brand'] . ' ' . $vehicle['model']);
                    
                    // Add trailer connection if present
                    if (!empty($vehicle['additional_info'])) {
                        $description .= ' connected to 1 x ' . $condition . ' ' . $vehicle['additional_info'];
                    }
                    
                    $parts[] = $description;
                } elseif (!empty($vehicle['brand'])) {
                    $parts[] = $vehicle['brand'];
                }
                
                $cargoType = implode(' ', array_filter($parts));
                
                // Add dimensions
                $dimString = sprintf("%s L: %.2fm x W: %.2fm x H: %.2fm", 
                    $cargoType, $L, $W, $H);
                
                // Calculate and add volume
                $volume = $L * $W * $H;
                if ($volume > 0) {
                    $dimString .= sprintf(" (%.2fm³)", $volume);
                }
                
                // Add weight if available
                if (!empty($vehicle['weight_kg'])) {
                    $dimString .= ' // ' . number_format($vehicle['weight_kg'], 0) . ' kg';
                }
            }
        } elseif (is_string($dimensions) && !empty($dimensions)) {
            $dimString = $dimensions;
        }

        // Enhanced cargo description logic for different data structures
        $cargoDescription = null;
        
        // First check for direct cargo description (from flat structure)
        if (isset($extractionData['raw_data']['cargo_description'])) {
            $cargoDescription = $extractionData['raw_data']['cargo_description'];
        }
        // Then check nested structures
        elseif (isset($extractionData['document_data']['cargo']['description'])) {
            $cargoDescription = $extractionData['document_data']['cargo']['description'];
        }
        elseif (isset($extractionData['cargo']['description'])) {
            $cargoDescription = $extractionData['cargo']['description'];
        }
        // Fallback to generated description (simple format for CARGO field)
        else {
            $cargoDescription = $this->generateCargoDescription($vehicle, $extractionData);
        }

        // Enhanced dimensions handling from various extraction sources (fallback)
        if (!$dimString) {
            // Try raw_data dimensions
            if (isset($extractionData['raw_data']['dimensions'])) {
                $dimString = $extractionData['raw_data']['dimensions'];
            }
            // Try direct dimensions field from extraction
            elseif (isset($extractionData['dimensions']) && is_string($extractionData['dimensions'])) {
                $dimString = $extractionData['dimensions'];
            }
            // Try vehicle dimensions raw text
            elseif (isset($vehicle['raw_dimensions'])) {
                $dimString = $vehicle['raw_dimensions'];
            }
        }

        Log::info('Cargo details mapping completed', [
            'cargo_description' => $cargoDescription,
            'dimensions_text' => $dimString,
            'has_vehicle_dimensions' => !empty($vehicle['dimensions']),
            'vehicle_weight' => $vehicle['weight_kg'] ?? null
        ]);

        return [
            'cargo' => $cargoDescription,
            'dimensions_text' => $dimString,
            'container_nr' => $extractionData['container_number'] ?? '',
        ];
    }

    /**
     * Map internal remarks section
     */
    private function mapInternalRemarks(Intake $intake, array $extractionData): array
    {
        return [
            'urgent' => false,
            'follow' => false,
            'followed_by' => '',
            'follow_up' => '',
            'calculation' => '',
            'inspection_call' => '',
            'weblink' => '',
            'external_sales_person' => '',
        ];
    }

    /**
     * Map automation section - This is where the extracted JSON goes
     */
    private function mapAutomation(array $extractionData): array
    {
        return [
            'extracted_information' => $this->generateExtractedInfo($extractionData),
            'json' => json_encode($extractionData, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Map shipping section with ISO dates
     */
    private function mapShipping(array $shipping, array $dates): array
    {
        $timeline = $shipping['timeline'] ?? [];
        $method = $shipping['method'] ?? '';

        return [
            'booking_pol_and_ref' => '',
            'transport_company' => $this->extractTransportCompany($shipping),
            'warehouse' => '',
            'shipping_line' => $this->extractShippingLine($shipping, $method),
            'transhipment' => '',
            'afvaarten' => '',
            'transit_time' => $this->extractTransitTime($timeline),
            'next_sailings' => '',
            'vessel' => $timeline[0]['vessel'] ?? '',
            'voyage' => $timeline[0]['voyage'] ?? '',
            'etc' => $this->formatDate($dates, 'etc'),
            'ets' => $this->formatDate($dates, 'ets'),
            'eta' => $this->formatDate($dates, 'eta'),
            'pol_forwarder' => '',
            'pod_forwarder_dropdown' => '',
            'pod_forwarder_dropdownextra' => '',
        ];
    }

    /**
     * Map payments section with numeric values
     */
    private function mapPayments(array $pricing, array $extractionData): array
    {
        return [
            'pre_carriage' => $this->extractPrice($pricing, 'pre_carriage'),
            'pre_carriage_bill_to_party' => '',
            'customs_origin' => $this->extractPrice($pricing, 'customs_origin'),
            'customs_origin_bill_to_party' => '',
            'fob_charges' => $this->extractPrice($pricing, 'fob'),
            'fob_bill_to_party' => '',
            'seafreight' => $this->extractPrice($pricing, 'seafreight'),
            'seafreight_bill_to_party' => '',
            'insurance' => $this->extractPrice($pricing, 'insurance'),
            'insurance_bill_to_party' => '',
            'destination_charges' => $this->extractPrice($pricing, 'destination'),
            'destination_charges_bill_to_party' => '',
            'customs_destination' => $this->extractPrice($pricing, 'customs_destination'),
            'customs_destination_bill_to_party' => '',
            'oncarriage' => $this->extractPrice($pricing, 'oncarriage'),
            'oncarriage_bill_to_party' => '',
        ];
    }

    /**
     * Map offer extra info section
     */
    private function mapOfferExtraInfo(array $extractionData): array
    {
        return [
            'payment_conditions' => '',
            'remarks' => '',
            'remarks_drop_down' => '',
        ];
    }

    // Helper methods

    private function extractProject(array $vehicle): string
    {
        if (!empty($vehicle['brand']) && !empty($vehicle['model'])) {
            return trim($vehicle['brand'] . ' ' . $vehicle['model']);
        }
        return '';
    }

    private function extractContactPerson(array $contact, array $shipping): string
    {
        return $contact['name'] ?? $shipping['contact']['name'] ?? '';
    }

    /**
     * Generate stable concerning field with route summary
     * Format: EXP RORO - BRUSSEL - ANR - JEDDAH - 1 x BMW Série 7
     */
    private function generateConcerning(array $vehicle, array $shipping, array $extractionData = []): string
    {
        $parts = [];
        
        // Start with "EXP RORO"
        $method = $shipping['method'] ?? 'RORO';
        $parts[] = 'EXP ' . strtoupper($method);
        
        // Get data from raw_data.expedition if available (new structure)
        $rawData = $extractionData['raw_data'] ?? [];
        $expedition = $rawData['expedition'] ?? [];
        
        // Get origin, POL, and destination from multiple sources
        $documentData = $extractionData['document_data'] ?? [];
        $shipment = $documentData['shipment'] ?? $extractionData['shipment'] ?? [];
        $route = $shipping['route'] ?? [];
        
        // Priority: raw_data.expedition -> top-level extraction -> document_data.shipment -> shipping.route
        $originStr = $expedition['origin'] ?? 
                     $extractionData['origin'] ?? 
                     $shipment['origin'] ?? 
                     $this->formatLocation($route['origin'] ?? []);
        
        $destinationStr = $expedition['destination'] ?? 
                          $extractionData['destination'] ?? 
                          $shipment['destination'] ?? 
                          $this->formatLocation($route['destination'] ?? []);
        
        // Convert cities to codes
        $originCode = $this->cityToCode($originStr);
        $polCode = 'ANR'; // Default POL to Antwerp
        $destinationCode = $this->cityToCode($destinationStr);
        
        // Add origin code
        if ($originCode) {
            $parts[] = $originCode;
        }
        
        // Add POL code (Antwerp)
        $parts[] = $polCode;
        
        // Add destination code
        if ($destinationCode) {
            $parts[] = $destinationCode;
        }
        
        // Add vehicle description with "used" condition
        $vehicleDesc = $this->generateVehicleDescription($vehicle, $extractionData);
        if ($vehicleDesc) {
            $parts[] = $vehicleDesc;
        }
        
        return implode(' - ', array_filter($parts));
    }

    /**
     * Convert city name to code
     */
    private function cityToCode(?string $city): string
    {
        if (!$city) return '';
        
        $map = [
            'Brussels' => 'BRU',
            'Bruxelles' => 'BRU',
            'Antwerp' => 'ANR',
            'Anvers' => 'ANR',
            'Jeddah' => 'JED',
            'Djeddah' => 'JED',
        ];
        
        return $map[$city] ?? strtoupper(substr($city, 0, 3));
    }

    /**
     * Shorten location names for concerning field
     */
    private function shortenLocation(string $location): string
    {
        $shortenings = [
            'jeddah' => 'JEDDAH',
            'riyadh' => 'RIYADH',
            'dubai' => 'DUBAI',
            'antwerp' => 'ANR',
            'hamburg' => 'HAM',
            'rotterdam' => 'RTM',
            'le havre' => 'HAV',
        ];
        
        $lower = strtolower(trim($location));
        
        foreach ($shortenings as $full => $short) {
            if (stripos($lower, $full) !== false) {
                return $short;
            }
        }
        
        // Default: take first word and uppercase
        $words = explode(' ', $location);
        return strtoupper($words[0] ?? '');
    }

    /**
     * Generate vehicle description for concerning field
     */
    private function generateVehicleDescription(array $vehicle, array $extractionData = []): string
    {
        // Try to extract vehicle info from raw text if not in vehicle array
        $rawText = $extractionData['raw_text'] ?? '';
        
        // Check for vehicle info in different formats - improved regex to avoid duplication
        $vehicleMatch = '';
        if (preg_match('/(\d+)\s*x\s*(?:(new|used|second\s*hand)\s+)?([A-Za-z0-9\s]+?)(?:\s+[A-Za-z0-9\s]+)?(?:\s*,|\s*afmetingen|\s*dimensions|\s*$)/i', $rawText, $matches)) {
            $quantity = $matches[1];
            $conditionFromText = !empty($matches[2]) ? strtolower($matches[2]) : null;
            $vehicleType = trim($matches[3]);
            
            // Clean up vehicle type to remove duplicate words
            $words = explode(' ', $vehicleType);
            $uniqueWords = [];
            $lastWord = '';
            
            foreach ($words as $word) {
                $cleanWord = trim($word);
                if (!empty($cleanWord) && strtolower($cleanWord) !== strtolower($lastWord)) {
                    $uniqueWords[] = $cleanWord;
                    $lastWord = $cleanWord;
                }
            }
            
            $vehicleType = implode(' ', $uniqueWords);
            
            // Use condition from text if found, otherwise default
            $condition = $conditionFromText === 'second hand' ? 'used' : ($conditionFromText ?? $this->getVehicleCondition($vehicle, $rawText));
            $vehicleMatch = $quantity . ' x ' . $condition . ' ' . $vehicleType;
        }
        
        // If we found a match in raw text, use it
        if ($vehicleMatch) {
            return $vehicleMatch;
        }
        
        // Fallback to vehicle array data or raw_data.expedition.vehicle
        $condition = $this->getVehicleCondition($vehicle, $rawText);
        
        // Check raw_data.expedition.vehicle first (new structure)
        $rawData = $extractionData['raw_data'] ?? [];
        $expeditionVehicle = $rawData['expedition']['vehicle'] ?? [];
        
        $make = $expeditionVehicle['make'] ?? $vehicle['brand'] ?? $vehicle['make'] ?? '';
        $model = $expeditionVehicle['model'] ?? $vehicle['model'] ?? '';
        
        if (empty($make) && empty($model)) {
            return '1 x ' . $condition . ' Motorgrader'; // Default for heavy machinery
        }
        
        $desc = '1 x ' . $condition;
        if (!empty($make)) {
            $desc .= ' ' . $make;
        }
        if (!empty($model)) {
            $desc .= ' ' . $model;
        }
        
        return $desc;
    }

    /**
     * Get vehicle condition, defaulting to 'used' if not specified
     */
    private function getVehicleCondition(array $vehicle, string $rawText = ''): string
    {
        // Check vehicle array first
        if (!empty($vehicle['condition'])) {
            return strtolower($vehicle['condition']);
        }
        
        // Check raw text for condition keywords
        if (preg_match('/\b(new|used|second\s*hand)\b/i', $rawText, $matches)) {
            $condition = strtolower($matches[1]);
            return $condition === 'second hand' ? 'used' : $condition;
        }
        
        // Default to 'used'
        return 'used';
    }

    private function formatLocation(array $location): string
    {
        if (empty($location)) {
            return '';
        }

        $parts = [];
        if (!empty($location['city'])) {
            $parts[] = $location['city'];
        }
        if (!empty($location['country'])) {
            $parts[] = $location['country'];
        }

        return implode(', ', $parts);
    }

    private function generateCargoDescription(array $vehicle, array $extractionData = []): string
    {
        $parts = [];
        $rawText = $extractionData['raw_text'] ?? '';

        // Add quantity (default to 1)
        $quantity = $vehicle['quantity'] ?? 1;
        $parts[] = $quantity . ' x';

        // Try to extract vehicle type and condition from raw text first
        if ($rawText && preg_match('/(\d+)\s*x\s*(?:(new|used|second\s*hand)\s+)?([A-Za-z0-9\s]+?)(?:\s*,|\s*afmetingen|\s*dimensions|\s*$)/i', $rawText, $matches)) {
            $conditionFromText = !empty($matches[2]) ? strtolower($matches[2]) : null;
            $vehicleType = trim($matches[3]);
            
            // Clean up vehicle type to remove duplicate words
            $words = explode(' ', $vehicleType);
            $uniqueWords = [];
            $lastWord = '';
            
            foreach ($words as $word) {
                $cleanWord = trim($word);
                if (!empty($cleanWord) && strtolower($cleanWord) !== strtolower($lastWord)) {
                    $uniqueWords[] = $cleanWord;
                    $lastWord = $cleanWord;
                }
            }
            
            $vehicleType = implode(' ', $uniqueWords);
            
            // Use condition from text if found, otherwise default
            $condition = $conditionFromText === 'second hand' ? 'used' : ($conditionFromText ?? $this->getVehicleCondition($vehicle, $rawText));
            $parts[] = $condition;
            $parts[] = $vehicleType;
        }
        // Fallback to vehicle array data
        else {
        // Add condition (default to 'used' if not specified)
        $condition = $this->getVehicleCondition($vehicle, $rawText);
        $parts[] = $condition;

        // Add vehicle/equipment type from vehicle array
        if (!empty($vehicle['full_description'])) {
            // Use the enhanced full description if available
            $parts[] = $vehicle['full_description'];
        } elseif (!empty($vehicle['type'])) {
            $parts[] = $vehicle['type'];
        } elseif (!empty($vehicle['brand']) && !empty($vehicle['model'])) {
            $description = trim($vehicle['brand'] . ' ' . $vehicle['model']);
            
            // Check if there's additional_info to connect
            if (!empty($vehicle['additional_info'])) {
                $description .= ' connected to 1 x ' . $condition . ' ' . $vehicle['additional_info'];
            }
            
            $parts[] = $description;
        } elseif (!empty($vehicle['brand'])) {
            $parts[] = $vehicle['brand'];
        } else {
            $parts[] = 'Motorgrader'; // Default for heavy machinery
        }
        }

        // Build simple cargo string (no dimensions or weight)
        $cargo = implode(' ', array_filter($parts));

        // Fallback description
        return $cargo ?: '1 x used Vehicle';
    }

    /**
     * Normalize dimensions to meters from various units
     */
    private function normalizeDimensions(array $dimensions): array
    {
        // Accept keys: length_m, length_cm, length_mm, etc.
        $length = $dimensions['length_m'] ?? 
                  (isset($dimensions['length_cm']) ? $dimensions['length_cm'] / 100 : null) ??
                  (isset($dimensions['length_mm']) ? $dimensions['length_mm'] / 1000 : null);
                  
        $width = $dimensions['width_m'] ?? 
                 (isset($dimensions['width_cm']) ? $dimensions['width_cm'] / 100 : null) ??
                 (isset($dimensions['width_mm']) ? $dimensions['width_mm'] / 1000 : null);
                 
        $height = $dimensions['height_m'] ?? 
                  (isset($dimensions['height_cm']) ? $dimensions['height_cm'] / 100 : null) ??
                  (isset($dimensions['height_mm']) ? $dimensions['height_mm'] / 1000 : null);
        
        return [
            round($length ?? 0, 2),
            round($width ?? 0, 2),
            round($height ?? 0, 2)
        ];
    }

    private function extractTransportCompany(array $shipping): string
    {
        return $shipping['carrier'] ?? $shipping['transport_company'] ?? '';
    }

    private function extractShippingLine(array $shipping, string $method): string
    {
        if (strtolower($method) === 'roro') {
            return 'RoRo Service';
        }
        return $shipping['shipping_line'] ?? '';
    }

    private function extractTransitTime(array $timeline): string
    {
        if (!empty($timeline)) {
            // Calculate from first to last event
            return '';
        }
        return '';
    }

    /**
     * Format date to ISO format (YYYY-MM-DD)
     */
    private function formatDate(array $dates, string $type): string
    {
        foreach ($dates as $date) {
            if (isset($date['type']) && strcasecmp($date['type'], $type) === 0) {
                return Carbon::parse($date['date'])->toDateString(); // ISO format
            }
        }
        return '';
    }

    /**
     * Extract price as numeric value with currency
     */
    private function extractPrice(array $pricing, string $type): ?array
    {
        foreach (($pricing ?? []) as $price) {
            if (isset($price['type']) && stripos($price['type'], $type) !== false) {
                $amount = $price['amount'] ?? null;
                if (is_string($amount)) {
                    $amount = (float) str_replace([',', ' '], ['.', ''], $amount);
                }
                return [
                    'amount' => $amount,
                    'currency' => $price['currency'] ?? 'EUR'
                ];
            }
        }
        return null;
    }

    private function generateExtractedInfo(array $extractionData): string
    {
        $info = [];
        
        // Vehicle info
        if (!empty($extractionData['vehicle'])) {
            $vehicle = $extractionData['vehicle'];
            $info[] = "Vehicle: " . trim(($vehicle['brand'] ?? '') . ' ' . ($vehicle['model'] ?? ''));
        }
        
        // Shipping info
        if (!empty($extractionData['shipping'])) {
            $shipping = $extractionData['shipping'];
            if (!empty($shipping['route'])) {
                $origin = $shipping['route']['origin'] ?? [];
                $dest = $shipping['route']['destination'] ?? [];
                $info[] = "Route: " . $this->formatLocation($origin) . ' → ' . $this->formatLocation($dest);
            }
            if (!empty($shipping['method'])) {
                $info[] = "Method: " . $shipping['method'];
            }
        }
        
        // Contact info
        if (!empty($extractionData['contact'])) {
            $contact = $extractionData['contact'];
            $info[] = "Contact: " . ($contact['name'] ?? '') . ' (' . ($contact['email'] ?? '') . ')';
        }
        
        return implode("\n", $info);
    }

    /**
     * Normalize port names (e.g., Djeddah -> Jeddah)
     */
    private function normalizePortNames(string $portName): string
    {
        $normalizations = [
            'djeddah' => 'Jeddah',
            'djidda' => 'Jeddah',
            'jiddah' => 'Jeddah',
        ];

        $lowerPort = strtolower(trim($portName));
        
        // Special handling for Antwerp to always show full format
        if ($lowerPort === 'antwerp' || $lowerPort === 'antwerpen') {
            return 'Antwerp, Belgium';
        }
        
        foreach ($normalizations as $variant => $normalized) {
            if (stripos($lowerPort, $variant) !== false) {
                return str_ireplace($variant, $normalized, $portName);
            }
        }

        return $portName;
    }

    /**
     * Check if a destination is a port (no oncarriage needed)
     */
    private function isPortDestination(string $destination): bool
    {
        $ports = [
            'jeddah',
            'antwerp',
            'hamburg',
            'rotterdam',
            'le havre',
            'felixstowe',
            'genoa',
            'valencia',
            'algeciras',
            'barcelona',
            'dubai',
            'abu dhabi',
            'doha',
            'kuwait',
            'dammam',
            'riyadh port', // Special case if mentioned as port
        ];

        $lowerDest = strtolower(trim($destination));
        
        foreach ($ports as $port) {
            if (stripos($lowerDest, $port) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if oncarriage is requested by the customer
     */
    private function hasOncarriageRequest(array $pricing, array $extractionData): bool
    {
        // Check if there are oncarriage charges specified
        $oncarriageAmount = $pricing['oncarriage'] ?? null;
        if (is_array($oncarriageAmount)) {
            $oncarriageAmount = $oncarriageAmount['amount'] ?? null;
        }
        
        // If there's a positive oncarriage amount, customer wants oncarriage
        if ($oncarriageAmount && (float)$oncarriageAmount > 0) {
            return true;
        }
        
        // Check if FDEST is explicitly mentioned in the original data
        if (!empty($extractionData['fdest'])) {
            return true;
        }
        
        // Check shipping data for final destination indicators
        $shipping = $extractionData['shipping'] ?? [];
        if (!empty($shipping['final_destination']) || !empty($shipping['fdest'])) {
            return true;
        }
        
        // Check if there are mentions of delivery, final destination, etc. in text data
        $documentText = strtolower($extractionData['raw_text'] ?? '');
        $oncarriageKeywords = [
            'final destination',
            'deliver to',
            'delivery to',
            'oncarriage',
            'on-carriage',
            'final delivery',
            'door to door',
            'door delivery'
        ];
        
        foreach ($oncarriageKeywords as $keyword) {
            if (stripos($documentText, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Determine if this is port-to-port shipping (no inland pickup/delivery)
     */
    private function isPortToPortShipping(string $origin, string $destination, array $extractionData = [], array $pricing = []): bool
    {
        // If both origin and destination are recognized ports, it's likely port-to-port
        $originIsPort = $this->isPortDestination($origin);
        $destinationIsPort = $this->isPortDestination($destination);
        
        if ($originIsPort && $destinationIsPort) {
            return true;
        }

        return false;
    }

    /**
     * Check if a location should be mapped to its default port even in port-to-port shipping
     */
    private function shouldMapToDefaultPort(string $location): bool
    {
        $location = strtolower(trim($location));
        
        // Countries and ports that should always be mapped to their default ports
        $countriesRequiringMapping = [
            'germany',
            'deutschland', 
            'belgium',
            'netherlands',
            'antwerp',     // Always format Antwerp properly
            'antwerpen',   // Dutch name for Antwerp
            'nigeria',
            'lagos',
            'tin-can port',
            'tin can port',
            'tincan'
        ];
        
        foreach ($countriesRequiringMapping as $country) {
            if (stripos($location, $country) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get default port for a location (for door-to-door shipping)
     */
    private function getDefaultPortForLocation(string $location): string
    {
        $location = strtolower(trim($location));
        
        // European ports mapping with countries
        $portMappings = [
            // Belgium
            'belgium' => 'Antwerp, Belgium',
            'brussels' => 'Antwerp, Belgium',
            'bruxelles' => 'Antwerp, Belgium',
            'antwerp' => 'Antwerp, Belgium',
            'antwerpen' => 'Antwerp, Belgium',
            
            // Netherlands
            'netherlands' => 'Rotterdam, Netherlands',
            'amsterdam' => 'Rotterdam, Netherlands',
            'rotterdam' => 'Rotterdam, Netherlands',
            
            // Germany - Changed to map to Antwerp instead of Hamburg
            'germany' => 'Antwerp, Belgium',
            'deutschland' => 'Antwerp, Belgium',
            'hamburg' => 'Hamburg, Germany',
            'bremen' => 'Bremen, Germany',
            'bremerhaven' => 'Bremen, Germany',
            
            // UK
            'uk' => 'Felixstowe, UK',
            'england' => 'Felixstowe, UK',
            'london' => 'Felixstowe, UK',
            
            // Africa - West Coast
            'nigeria' => 'Lagos, Nigeria',
            'lagos' => 'Lagos, Nigeria',
            'tin-can port' => 'Lagos, Nigeria',
            'tin can port' => 'Lagos, Nigeria',
            'tincan' => 'Lagos, Nigeria',
            
            // Africa - East Coast
            'tanzania' => 'Dar es Salaam, Tanzania',
            'kenya' => 'Mombasa, Kenya',
            'mozambique' => 'Maputo, Mozambique',
            
            // Default major ports
            'dar es salaam' => 'Dar es Salaam, Tanzania',
            'mombasa' => 'Mombasa, Kenya',
            'durban' => 'Durban, South Africa',
        ];
        
        foreach ($portMappings as $keyword => $port) {
            if (stripos($location, $keyword) !== false) {
                return $port;
            }
        }
        
        return $location; // Return original if no mapping found
    }

    /**
     * Extract enhanced customer data from extraction data
     */
    protected function extractEnhancedCustomerData(array $extractionData, Intake $intake): array
    {
        $rawData = $extractionData['raw_data'] ?? [];
        $documentData = $extractionData['document_data'] ?? [];
        $contact = $documentData['contact'] ?? $extractionData['contact'] ?? $rawData['contact'] ?? [];
        
        // Extract customer name with fallbacks (prioritize company name over contact person name)
        $customerName = $rawData['company'] ?? 
                       $contact['company'] ?? 
                       $extractionData['customer_name'] ?? 
                       $intake->customer_name ??
                       $contact['name'] ?? 
                       'Unknown Customer';
        
        // Extract email with fallbacks
        $email = $contact['email'] ?? 
                $rawData['email'] ?? 
                $extractionData['email'] ?? 
                $extractionData['customer_email'] ?? 
                $intake->contact_email ?? 
                null;
        
        // Extract phone numbers with enhanced fallbacks
        $phone = $contact['phone'] ?? 
                $rawData['phone'] ?? 
                $extractionData['phone'] ?? 
                $extractionData['customer_phone'] ?? 
                $intake->contact_phone ?? 
                null;
        
        $mobile = $contact['mobile'] ?? 
                 $rawData['mobile'] ?? 
                 $extractionData['mobile'] ?? 
                 null;
        
        // Extract VAT number with multiple fallbacks
        $vatNumber = $rawData['vat'] ?? 
                    $rawData['vat_number'] ?? 
                    $extractionData['vat_number'] ?? 
                    $extractionData['btw'] ?? 
                    $extractionData['btw_nummer'] ?? 
                    null;
        
        // Normalize Belgian VAT number
        if ($vatNumber) {
            $vatNumber = $this->normalizeVatNumber($vatNumber);
        }
        
        // Extract website with fallbacks
        $website = $rawData['website'] ?? 
                  $extractionData['website'] ?? 
                  $extractionData['web'] ?? 
                  null;
        
        // Normalize website URL
        if ($website && !preg_match('/^https?:\/\//', $website)) {
            $website = 'www.' . ltrim($website, 'www.');
        }
        
        // Extract contact person
        $contactPerson = null;
        if (!empty($contact)) {
            $contactPerson = $this->formatContactPerson($contact);
        } elseif (!empty($extractionData['sender']) && $extractionData['sender'] !== $customerName) {
            // Use sender as contact person if different from customer name
            $contactPerson = $this->formatContactPerson($extractionData['sender']);
        }
        
        // Extract enhanced address information
        $address = $this->extractEnhancedAddress($extractionData, $rawData);
        
        // Extract company information
        $companyInfo = $this->extractCompanyInfo($extractionData);
        
        // Build comprehensive customer data with enhanced fields
        $customerData = array_merge([
            'name' => trim($customerName),
            'email' => $email,
            'phone' => $this->normalizePhoneNumber($phone),
            'mobile' => $this->normalizePhoneNumber($mobile),
            'vat_number' => $vatNumber,
            'website' => $website,
            'contact_person' => $contactPerson,
            'language' => $this->detectLanguage($extractionData),
            'currency' => $extractionData['currency'] ?? 'EUR',
        ], $address, $companyInfo);

        // Remove empty values
        return array_filter($customerData, function ($value) {
            return $value !== null && $value !== '' && $value !== [];
        });
    }

    /**
     * Extract enhanced address information with better fallback handling
     */
    protected function extractEnhancedAddress(array $extractionData, array $rawData): array
    {
        $address = [];
        
        // Check for structured address data in raw_data first
        if (!empty($rawData['address'])) {
            $fullAddress = $rawData['address'];
            
            // Try to parse full address string
            if (is_string($fullAddress)) {
                $parsed = $this->parseAddressString($fullAddress);
                $address = array_merge($address, $parsed);
            }
        }
        
        // Extract individual address components with enhanced fallbacks
        $street = $rawData['street'] ?? 
                 $extractionData['address']['street'] ?? 
                 $extractionData['street'] ?? 
                 $address['street'] ?? 
                 null;
        
        $streetNumber = $rawData['street_number'] ?? 
                       $extractionData['address']['street_number'] ?? 
                       $extractionData['street_number'] ?? 
                       $address['street_number'] ?? 
                 null;
        
        $city = $rawData['city'] ?? 
               $extractionData['address']['city'] ?? 
               $extractionData['city'] ?? 
               $address['city'] ?? 
               null;
        
        $zip = $rawData['zip'] ?? 
              $rawData['postal_code'] ?? 
              $extractionData['address']['postal_code'] ?? 
              $extractionData['postal_code'] ?? 
              $address['postal_code'] ?? 
              null;
        
        $country = $rawData['country'] ?? 
                  $extractionData['address']['country'] ?? 
                  $extractionData['country'] ?? 
                  $address['country'] ?? 
                  'Belgium'; // Default for Belgian companies
        
        // Build final address structure
        $finalAddress = array_filter([
            'street' => $street,
            'street_number' => $streetNumber,
            'city' => $city,
            'postal_code' => $zip,
            'country' => $this->normalizeCountryName($country),
        ]);
        
        // Fallback: try to extract from shipping origin if address is incomplete
        if (empty($finalAddress['city']) || empty($finalAddress['country'])) {
            $origin = $extractionData['shipping']['origin'] ?? 
                     $extractionData['shipment']['origin'] ?? 
                     null;
            
            if ($origin) {
                $originParts = $this->parseLocationString($origin);
                // Normalize country from origin
                if (isset($originParts['country'])) {
                    $originParts['country'] = $this->normalizeCountryName($originParts['country']);
                } else {
                    // If no country in parsed origin, normalize the origin itself as country
                    $originParts['country'] = $this->normalizeCountryName($origin);
                }
                $finalAddress = array_merge($finalAddress, $originParts);
            }
        }
        
        // If country is still not set, try to normalize from shipping origin directly
        if (empty($finalAddress['country']) || $finalAddress['country'] === 'Belgium') {
            $origin = $extractionData['shipping']['origin'] ?? 
                     $extractionData['shipment']['origin'] ?? 
                     null;
            
            if ($origin) {
                $normalizedCountry = $this->normalizeCountryName($origin);
                if ($normalizedCountry && $normalizedCountry !== 'Belgium') {
                    $finalAddress['country'] = $normalizedCountry;
                }
            }
        }
        
        return $finalAddress;
    }

    /**
     * Parse a full address string into components
     */
    protected function parseAddressString(string $address): array
    {
        $parsed = [];
        
        // Look for common patterns
        // Example: "Kapelsesteenweg 611, B-2180 Antwerp (Ekeren), Belgium"
        if (preg_match('/^([^,]+),?\s*([A-Z]{0,2}-?\d{4,5})\s+([^,]+)(?:,\s*(.+))?$/i', $address, $matches)) {
            $parsed['street'] = trim($matches[1]);
            $parsed['postal_code'] = trim($matches[2]);
            $parsed['city'] = trim($matches[3]);
            if (isset($matches[4])) {
                $parsed['country'] = trim($matches[4]);
            }
        }
        
        return $parsed;
    }

    /**
     * Parse location string (like "Antwerp, Belgium")
     */
    protected function parseLocationString(string $location): array
    {
        $parts = array_map('trim', explode(',', $location));
        
        if (count($parts) >= 2) {
            return [
                'city' => $parts[0],
                'country' => end($parts),
            ];
        }
        
        return ['city' => $location];
    }

    /**
     * Normalize VAT number for Belgian format
     */
    protected function normalizeVatNumber(?string $vat): ?string
    {
        if (!$vat) return null;
        
        // Remove spaces, dots, and other formatting
        $cleanVat = preg_replace('/[\s\.\-]/', '', $vat);
        
        // Belgian VAT format: BE followed by 10 digits
        if (preg_match('/^(\d{4}\s?\d{3}\s?\d{3})$/', $cleanVat, $matches)) {
            return 'BE0' . preg_replace('/\s/', '', $matches[1]);
        }
        
        // Already has BE prefix
        if (preg_match('/^BE\d{10}$/', $cleanVat)) {
            return $cleanVat;
        }
        
        return $vat; // Return as-is if no pattern matches
    }

    /**
     * Normalize phone number for Belgian format
     */
    protected function normalizePhoneNumber(?string $phone): ?string
    {
        if (!$phone) return null;
        
        // Remove formatting characters
        $cleanPhone = preg_replace('/[\s\(\)\-\.]/', '', $phone);
        
        // Only normalize Belgian numbers (+32), leave other country codes untouched
        
        // Belgian phone format: +320XXXXXXXX (with leading 0)
        if (preg_match('/^\+320(\d{8,9})$/', $cleanPhone, $matches)) {
            // Format as +32(0)XXXXXXXX
            $digits = $matches[1];
            return '+32(0)' . $digits;
        }
        
        // Belgian phone format: +32XXXXXXXX (without leading 0) - add the 0
        if (preg_match('/^\+32([1-9]\d{7,8})$/', $cleanPhone, $matches)) {
            // Format as +32(0)XXXXXXXX
            $digits = $matches[1];
            return '+32(0)' . $digits;
        }
        
        // Belgian international format: 0032 followed by digits (Belgium without +)
        if (preg_match('/^0032(\d{8,9})$/', $cleanPhone, $matches)) {
            // Convert to +32(0)XXXXXXXX format
            $digits = $matches[1];
            return '+32(0)' . $digits;
        }
        
        // Local Belgian format: 0 followed by 8 or 9 digits (but not 0034 which is Spain)
        if (preg_match('/^0(\d{8,9})$/', $cleanPhone, $matches) && !str_starts_with($cleanPhone, '0034')) {
            // Convert to +32(0)XXXXXXXX format
            $digits = $matches[1];
            return '+32(0)' . $digits;
        }

        return $phone; // Return as-is if no Belgian pattern matches (could be Spanish, French, etc.)
    }

    /**
     * Format contact person data
     */
    protected function formatContactPerson($contact): ?array
    {
        if (empty($contact)) {
            return null;
        }
        
        if (is_string($contact)) {
            // Parse name to extract first and last name
            $nameParts = explode(' ', trim($contact), 2);
            return [
                'name' => $contact,
                'first_name' => $nameParts[0] ?? null,
                'last_name' => $nameParts[1] ?? null,
                'is_primary' => true,
            ];
        }
        
        if (is_array($contact)) {
            return array_filter([
                'name' => $contact['name'] ?? null,
                'first_name' => $contact['first_name'] ?? null,
                'last_name' => $contact['last_name'] ?? null,
                'email' => $contact['email'] ?? null,
                'phone' => $contact['phone'] ?? null,
                'mobile' => $contact['mobile'] ?? null,
                'function' => $contact['function'] ?? $contact['title'] ?? null,
                'is_primary' => true,
            ]);
        }
        
        return null;
    }

    /**
     * Extract address information
     */
    protected function extractAddress(array $extractionData): array
    {
        $address = [];
        
        // Check for structured address data
        if (!empty($extractionData['address'])) {
            $address = array_filter([
                'street' => $extractionData['address']['street'] ?? null,
                'street_number' => $extractionData['address']['street_number'] ?? null,
                'city' => $extractionData['address']['city'] ?? null,
                'postal_code' => $extractionData['address']['postal_code'] ?? null,
                'country' => $extractionData['address']['country'] ?? null,
            ]);
        }
        
        // Fallback to individual fields
        if (empty($address['city']) && !empty($extractionData['city'])) {
            $address['city'] = $extractionData['city'];
        }
        
        if (empty($address['country'])) {
            // Try to determine country from shipping data
            $origin = $extractionData['shipping']['origin'] ?? 
                     $extractionData['shipment']['origin'] ?? 
                     null;
            
            if ($origin) {
                $address['country'] = $this->normalizeCountryName($origin);
            }
        }
        
        return $address;
    }

    /**
     * Extract company information
     */
    protected function extractCompanyInfo(array $extractionData): array
    {
        $rawData = $extractionData['raw_data'] ?? [];
        $companyInfo = [];
        
        // Extract VAT number with enhanced fallbacks
        $vatNumber = $rawData['vat'] ?? 
                    $rawData['vat_number'] ?? 
                    $extractionData['vat_number'] ?? 
                    $extractionData['btw'] ?? 
                    $extractionData['btw_nummer'] ?? 
                    null;
        
        if ($vatNumber) {
            $companyInfo['vat_number'] = $this->normalizeVatNumber($vatNumber);
        }
        
        if (!empty($extractionData['company_number'])) {
            $companyInfo['company_number'] = $extractionData['company_number'];
        }
        
        // Extract website with enhanced fallbacks
        $website = $rawData['website'] ?? 
                  $extractionData['website'] ?? 
                  $extractionData['web'] ?? 
                  null;
        
        if ($website) {
            // Normalize website URL
            if (!preg_match('/^https?:\/\//', $website)) {
                $website = 'www.' . ltrim($website, 'www.');
            }
            $companyInfo['website'] = $website;
        }
        
        // Determine client type
        $companyInfo['client_type'] = $this->determineClientType($extractionData);
        
        return $companyInfo;
    }

    /**
     * Determine if client is company or individual
     */
    protected function determineClientType(array $extractionData): string
    {
        // Check for company indicators
        if (!empty($extractionData['vat_number']) || 
            !empty($extractionData['company_number'])) {
            return 'company';
        }
        
        // Check name for company indicators
        $name = $extractionData['customer_name'] ?? 
               $extractionData['contact']['name'] ?? '';
        
        $companyIndicators = ['Ltd', 'LLC', 'GmbH', 'BV', 'NV', 'SA', 'Inc', 'Corp', 'Company', 'Co.'];
        
        foreach ($companyIndicators as $indicator) {
            if (stripos($name, $indicator) !== false) {
                return 'company';
            }
        }
        
        return 'individual';
    }

    /**
     * Normalize country name for consistency
     */
    protected function normalizeCountryName(?string $country): ?string
    {
        if (!$country) return null;
        
        $countryMap = [
            'deutschland' => 'Germany',
            'germany' => 'Germany',
            'belgium' => 'Belgium',
            'belgie' => 'Belgium',
            'belgique' => 'Belgium',
            'netherlands' => 'Netherlands',
            'nederland' => 'Netherlands',
            'nigeria' => 'Nigeria',
            'kenya' => 'Kenya',
            'antwerp' => 'Belgium', // Port to country mapping
            'hamburg' => 'Germany',
            'rotterdam' => 'Netherlands',
            'lagos' => 'Nigeria',
            'mombasa' => 'Kenya',
        ];
        
        $normalized = strtolower(trim($country));
        return $countryMap[$normalized] ?? ucfirst($country);
    }

    /**
     * Detect language from extraction data
     */
    protected function detectLanguage(array $extractionData): string
    {
        $text = $extractionData['raw_text'] ?? '';
        
        // Simple language detection based on common words
        $germanWords = ['und', 'der', 'die', 'das', 'nach', 'von', 'ab', 'mit'];
        $dutchWords = ['en', 'de', 'het', 'van', 'naar', 'met', 'voor'];
        $frenchWords = ['et', 'le', 'la', 'de', 'pour', 'avec', 'dans'];
        
        $germanCount = 0;
        $dutchCount = 0;
        $frenchCount = 0;
        
        $words = str_word_count(strtolower($text), 1);
        
        foreach ($words as $word) {
            if (in_array($word, $germanWords)) $germanCount++;
            if (in_array($word, $dutchWords)) $dutchCount++;
            if (in_array($word, $frenchWords)) $frenchCount++;
        }
        
        if ($germanCount > $dutchCount && $germanCount > $frenchCount) {
            return 'de';
        } elseif ($dutchCount > $germanCount && $dutchCount > $frenchCount) {
            return 'nl';
        } elseif ($frenchCount > $germanCount && $frenchCount > $dutchCount) {
            return 'fr';
        }
        
        return 'en'; // Default to English
    }

    /**
     * Build client display placeholders for template variables
     * These are used in Robaws templates like ${client}, ${clientAddress}, etc.
     * Enhanced to handle both CustomerNormalizer format and extractEnhancedCustomerData format
     */
    public function buildClientDisplayPlaceholders(array $c): array
    {
        // Handle both formats: enhanced customer data and normalized customer data
        $client = $c['name'] ?? '';
        
        // Address handling - check for both formats
        if (isset($c['address']) && is_array($c['address'])) {
            // CustomerNormalizer format
            $street = $c['address']['street'] ?? '';
            $zip = $c['address']['zip'] ?? '';
            $city = $c['address']['city'] ?? '';
            $country = $c['address']['country'] ?? '';
        } else {
            // Enhanced customer data format (flat structure)
            $street = $c['street'] ?? '';
            $zip = $c['postal_code'] ?? '';
            $city = $c['city'] ?? '';
            $country = $c['country'] ?? '';
        }
        
        // Build full address string
        $addressParts = array_filter([$street, $zip . ' ' . $city, $country]);
        $addrStr = implode(', ', $addressParts);
        $addrStr = trim($addrStr, ', ');

        // Phone numbers
        $tel = $c['phone'] ?? '';
        $gsm = $c['mobile'] ?? '';
        $email = $c['email'] ?? '';
        
        // Enhanced fields - check for both vat_number and vat
        $vat = $c['vat_number'] ?? $c['vat'] ?? '';
        $website = $c['website'] ?? '';
        
        // Contact person - handle both formats
        if (isset($c['contact']) && is_array($c['contact'])) {
            // CustomerNormalizer format
            $contactName = $c['contact']['name'] ?? '';
            $contactEmail = $c['contact']['email'] ?? $email;
            $contactPhone = $c['contact']['phone'] ?? $tel;
            $contactMobile = $c['contact']['mobile'] ?? $gsm;
        } elseif (isset($c['contact_person']) && is_array($c['contact_person'])) {
            // Enhanced customer data format
            $contactName = $c['contact_person']['name'] ?? '';
            $contactEmail = $c['contact_person']['email'] ?? $email;
            $contactPhone = $c['contact_person']['phone'] ?? $tel;
            $contactMobile = $c['contact_person']['mobile'] ?? $gsm;
        } else {
            // Fallback to main customer data
            $contactName = $client;
            $contactEmail = $email;
            $contactPhone = $tel;
            $contactMobile = $gsm;
        }

        // Return extraFields format for Robaws API
        return array_filter([
            // Customer fields
            'CLIENT' => $client ? ['stringValue' => $client] : null,                    // ${client} or ${clientName}
            'CLIENT_ADDRESS' => $addrStr ? ['stringValue' => $addrStr] : null,          // ${clientAddress}
            'CLIENT_STREET' => $street ? ['stringValue' => $street] : null,            // ${clientStreet} or ${clientAddressStreet}
            'CLIENT_ZIPCODE' => $zip ? ['stringValue' => $zip] : null,                 // ${clientZipcode} or ${clientAddressZipcode}
            'CLIENT_CITY' => $city ? ['stringValue' => $city] : null,                 // ${clientCity} or ${clientAddressCity}
            'CLIENT_COUNTRY' => $country ? ['stringValue' => $country] : null,        // ${clientCountry}
            'CLIENT_TEL' => $tel ? ['stringValue' => $tel] : null,                    // ${clientTel}
            'CLIENT_GSM' => $gsm ? ['stringValue' => $gsm] : null,                    // ${clientGsm}
            'CLIENT_EMAIL' => $email ? ['stringValue' => $email] : null,              // ${clientEmail}
            'CLIENT_VAT' => $vat ? ['stringValue' => $vat] : null,                    // ${clientVat}
            'CLIENT_WEBSITE' => $website ? ['stringValue' => $website] : null,        // ${clientWebsite}
            
            // Contact person fields
            'CONTACT_NAME' => $contactName ? ['stringValue' => $contactName] : null,           // ${contactPersonName} or ${contactPersonFullname}
            'CONTACT_EMAIL' => $contactEmail ? ['stringValue' => $contactEmail] : null,        // ${contactPersonEmail}
            'CONTACT_TEL' => $contactPhone ? ['stringValue' => $contactPhone] : null,          // ${contactPersonTel}
            'CONTACT_GSM' => $contactMobile ? ['stringValue' => $contactMobile] : null,        // ${contactPersonGsm}
        ], fn($v) => $v !== null);
    }
    
    /**
     * Create a contact person for a client with phone numbers
     */
}
