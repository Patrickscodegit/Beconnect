<?php

namespace App\Services\Export\Mappers;

use App\Models\Intake;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class RobawsMapper
{
    /**
     * Map intake data to Robaws format
     */
    public function mapIntakeToRobaws(Intake $intake, array $extractionData = []): array
    {
        // Get extraction data from intake or documents
        if (empty($extractionData)) {
            $extractionData = $this->getExtractionData($intake);
        }

        // Extract nested data structures
        $vehicle = $extractionData['vehicle'] ?? [];
        $shipping = $extractionData['shipping'] ?? [];
        $contact = $extractionData['contact'] ?? [];
        $shipment = $extractionData['shipment'] ?? [];
        $dates = $extractionData['dates'] ?? [];
        $pricing = $extractionData['pricing'] ?? [];

        // Map to Robaws structure
        return [
            'quotation_info' => $this->mapQuotationInfo($intake, $contact, $vehicle, $shipping),
            'routing' => $this->mapRouting($shipping, $shipment),
            'cargo_details' => $this->mapCargoDetails($vehicle, $extractionData),
            'internal_remarks' => $this->mapInternalRemarks($intake, $extractionData),
            'automation' => $this->mapAutomation($extractionData),
            'shipping' => $this->mapShipping($shipping, $dates),
            'payments' => $this->mapPayments($pricing, $extractionData),
            'offer_extra_info' => $this->mapOfferExtraInfo($extractionData),
        ];
    }

    /**
     * Get extraction data from intake or its documents - using recursive merge
     */
    private function getExtractionData(Intake $intake): array
    {
        $base = $intake->extraction?->data ?? [];

        foreach ($intake->documents as $doc) {
            $docData = $doc->extraction?->data ?? [];
            $base = array_replace_recursive($base, $docData);
        }

        return $base;
    }

    /**
     * Map quotation info section
     */
    private function mapQuotationInfo(Intake $intake, array $contact, array $vehicle, array $shipping): array
    {
        return [
            'date' => Carbon::now()->toDateString(), // ISO format internally
            'project' => $this->extractProject($vehicle),
            'customer' => $contact['name'] ?? $intake->customer_name ?? '',
            'contact' => $this->extractContactPerson($contact, $shipping),
            'endcustomer' => $contact['company'] ?? '',
            'contact_email' => $contact['email'] ?? $intake->customer_email ?? '',
            'customer_reference' => $intake->customer_reference ?? '',
            'concerning' => $this->generateConcerning($vehicle, $shipping),
            'status' => 'pending',
            'assignee' => $contact['email'] ?? 'sales@truck-time.com',
            'winst' => '',
        ];
    }

    /**
     * Map routing section
     */
    private function mapRouting(array $shipping, array $shipment): array
    {
        $route = $shipping['route'] ?? [];
        $origin = $route['origin'] ?? [];
        $destination = $route['destination'] ?? [];

        return [
            'por' => $this->formatLocation($origin) ?: ($shipment['origin'] ?? ''),
            'pol' => $this->formatLocation($origin) ?: ($shipment['origin'] ?? ''),
            'pot' => '', // Transit port if available
            'pod' => $this->formatLocation($destination) ?: ($shipment['destination'] ?? ''),
            'fdest' => $this->formatLocation($destination) ?: ($shipment['destination'] ?? ''),
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

        return [
            'cargo' => $this->generateCargoDescription($vehicle),
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
            'json' => json_encode($extractionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
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
     */
    private function generateConcerning(array $vehicle, array $shipping): string
    {
        $parts = [];
        
        // Vehicle info
        if (!empty($vehicle['brand']) || !empty($vehicle['model'])) {
            $parts[] = trim(($vehicle['brand'] ?? '') . ' ' . ($vehicle['model'] ?? ''));
        }
        
        // Shipping method
        if (!empty($shipping['method'])) {
            $parts[] = strtoupper($shipping['method']);
        }
        
        // Route
        $route = $shipping['route'] ?? [];
        $origin = $this->formatLocation($route['origin'] ?? []);
        $destination = $this->formatLocation($route['destination'] ?? []);
        if ($origin || $destination) {
            $parts[] = "$origin → $destination";
        }
        
        return implode(' • ', array_filter($parts));
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
}
