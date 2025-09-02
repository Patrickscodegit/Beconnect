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
        $documentData = $extractionData['document_data'] ?? [];
        $vehicle = $documentData['vehicle'] ?? $extractionData['vehicle'] ?? [];
        $shipping = $documentData['shipping'] ?? $extractionData['shipping'] ?? [];
        $contact = $documentData['contact'] ?? $extractionData['contact'] ?? [];
        $shipment = $documentData['shipment'] ?? $extractionData['shipment'] ?? [];
        $dates = $extractionData['dates'] ?? [];
        $pricing = $extractionData['pricing'] ?? [];

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
            'title'           => $q['concerning'] ?? ($q['project'] ?? null),
            'project'         => $q['project'] ?? null,
            'clientReference' => $q['customer_reference'] ?? $q['client_reference'] ?? null,
            'contactEmail'    => $q['contact_email'] ?? null,
            'clientId'        => $clientId !== null ? (int) $clientId : null, // Top-level for Customer binding
        ];

        $xf = [];

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
        // Truncate JSON if too large to avoid API limits
        $jsonData = $au['json'] ?? null;
        if ($jsonData && strlen($jsonData) > 60000) {
            $jsonData = substr($jsonData, 0, 60000) . '… [truncated]';
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
        $base = $intake->extraction?->extracted_data ?? [];

        foreach ($intake->documents as $doc) {
            $docData = $doc->extraction?->extracted_data ?? [];
            $base = array_replace_recursive($base, $docData);
        }

        return $base;
    }

    /**
     * Map quotation info section
     */
    private function mapQuotationInfo(Intake $intake, array $contact, array $vehicle, array $shipping, array $extractionData = []): array
    {
        return [
            'date' => Carbon::now()->toDateString(), // ISO format internally
            'project' => $this->extractProject($vehicle),
            'customer' => $contact['name'] ?? $intake->customer_name ?? '',
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

        // For POL, default to Antwerp for Belgian origins, otherwise use origin
        $polValue = $topLevelOrigin ?: ($this->formatLocation($origin) ?: ($shipment['origin'] ?? ''));
        if ($polValue && (stripos($polValue, 'bruxelles') !== false || stripos($polValue, 'brussels') !== false || stripos($polValue, 'belgium') !== false)) {
            $polValue = 'Antwerp, Belgium';
        }

        // Get POD value and normalize spellings
        $podValue = $topLevelDestination ?: ($this->formatLocation($destination) ?: ($shipment['destination'] ?? ''));
        $podValue = $this->normalizePortNames($podValue);

        // FDEST logic: Check if oncarriage is requested or if destination is not a port
        $fDestValue = '';
        if ($podValue) {
            // Check if oncarriage is requested (has charges or explicitly mentioned)
            $hasOncarriageRequest = $this->hasOncarriageRequest($pricing, $extractionData);
            
            // If oncarriage is requested OR destination is not a port, set FDEST
            if ($hasOncarriageRequest || !$this->isPortDestination($podValue)) {
                $fDestValue = $podValue;
            }
        }

        return [
            'por' => $topLevelOrigin ?: ($this->formatLocation($origin) ?: ($shipment['origin'] ?? '')),
            'pol' => $polValue,
            'pot' => '', // Transit port if available
            'pod' => $podValue,
            'fdest' => $fDestValue,
            'in_transit_to' => '',
        ];
    }

    /**
     * Map cargo details section with normalized dimensions
     */
    private function mapCargoDetails(array $vehicle, array $extractionData): array
    {
        $dimensions = $vehicle['dimensions'] ?? [];
        [$L, $W, $H] = $this->normalizeDimensions($dimensions);
        $dimString = ($L && $W && $H) ? sprintf("L: %.2fm x W: %.2fm x H: %.2fm", $L, $W, $H) : '';

        // Check for cargo description in nested data first, then generate from vehicle
        $cargoDescription = $extractionData['document_data']['cargo']['description'] ?? 
                            $extractionData['cargo']['description'] ?? 
                            $this->generateCargoDescription($vehicle);

        return [
            'cargo' => $cargoDescription,
            'dimensions_text' => $dimString, // Fixed field name
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
        
        // Start with "EXP"
        $parts[] = 'EXP';
        
        // Add shipping method (e.g., RORO)
        $method = $shipping['method'] ?? '';
        if ($method) {
            $parts[] = strtoupper($method);
        }
        
        // Get origin and destination from multiple sources
        $documentData = $extractionData['document_data'] ?? [];
        $shipment = $documentData['shipment'] ?? $extractionData['shipment'] ?? [];
        $route = $shipping['route'] ?? [];
        
        // Priority: top-level extraction -> document_data.shipment -> shipping.route
        $originStr = $extractionData['origin'] ?? 
                     $shipment['origin'] ?? 
                     $this->formatLocation($route['origin'] ?? []);
        
        $destinationStr = $extractionData['destination'] ?? 
                          $shipment['destination'] ?? 
                          $this->formatLocation($route['destination'] ?? []);
        
        // Normalize origin (Brussels -> BRUSSEL, Antwerp -> ANR for ports)
        if ($originStr) {
            if (stripos($originStr, 'bruxelles') !== false || stripos($originStr, 'brussels') !== false) {
                $parts[] = 'BRUSSEL';
                $parts[] = 'ANR'; // Port of Loading (Antwerp)
            } else {
                $parts[] = strtoupper($this->shortenLocation($originStr));
            }
        }
        
        // Add destination
        if ($destinationStr) {
            $parts[] = strtoupper($this->shortenLocation($this->normalizePortNames($destinationStr)));
        }
        
        // Add vehicle description (e.g., "1 x BMW Série 7")
        $vehicleDesc = $this->generateVehicleDescription($vehicle);
        if ($vehicleDesc) {
            $parts[] = $vehicleDesc;
        }
        
        return implode(' - ', array_filter($parts));
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
    private function generateVehicleDescription(array $vehicle): string
    {
        if (empty($vehicle['brand']) && empty($vehicle['model'])) {
            return '1 x Vehicle';
        }
        
        $desc = '1 x';
        if (!empty($vehicle['brand'])) {
            $desc .= ' ' . $vehicle['brand'];
        }
        if (!empty($vehicle['model'])) {
            $desc .= ' ' . $vehicle['model'];
        }
        
        return $desc;
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

    private function generateCargoDescription(array $vehicle): string
    {
        $parts = [];

        if (!empty($vehicle['brand'])) {
            $parts[] = $vehicle['brand'];
        }
        if (!empty($vehicle['model'])) {
            $parts[] = $vehicle['model'];
        }
        if (!empty($vehicle['vin'])) {
            $parts[] = 'VIN: ' . $vehicle['vin'];
        }
        if (!empty($vehicle['year'])) {
            $parts[] = 'Year: ' . $vehicle['year'];
        }

        return implode(' | ', $parts) ?: 'Vehicle';
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
}
