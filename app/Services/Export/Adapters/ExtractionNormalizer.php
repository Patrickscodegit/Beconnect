<?php

namespace App\Services\Export\Adapters;

/**
 * Normalizes extraction data to ensure consistent structure for mapping
 */
final class ExtractionNormalizer
{
    /**
     * Normalize extraction data structure for consistent mapping
     */
    public static function normalize(array $x): array
    {
        // Lift raw_data.shipment → shipment if missing
        $shipment = $x['shipment'] ?? [];
        if (empty($shipment) && !empty($x['raw_data']['shipment'])) {
            $shipment = $x['raw_data']['shipment'];
        }

        // Common aliases for routing fields
        $origin      = $shipment['origin']      ?? $shipment['from'] ?? $shipment['por'] ?? null;
        $destination = $shipment['destination'] ?? $shipment['to']   ?? $shipment['pod'] ?? null;
        $type        = $shipment['type']        ?? $shipment['shipping_type'] ?? null;

        // Normalize the shipment structure
        $x['shipment'] = array_filter([
            'origin'      => $origin,
            'destination' => $destination,
            'type'        => $type ? strtolower($type) : null, // roro | lcl | fcl | air
        ]);

        // Normalize contact data if present in multiple locations
        $contact = $x['contact'] ?? [];
        if (empty($contact) && !empty($x['raw_data']['contact'])) {
            $contact = $x['raw_data']['contact'];
        }
        
        // Ensure contact has consistent structure
        if (!empty($contact)) {
            $x['contact'] = array_filter([
                'name'    => $contact['name'] ?? null,
                'email'   => $contact['email'] ?? null,
                'phone'   => $contact['phone'] ?? $contact['telephone'] ?? null,
                'company' => $contact['company'] ?? null,
            ]);
        }

        // Normalize vehicle data
        $vehicle = $x['vehicle'] ?? [];
        if (empty($vehicle) && !empty($x['raw_data']['vehicle'])) {
            $vehicle = $x['raw_data']['vehicle'];
        }
        
        if (!empty($vehicle)) {
            $x['vehicle'] = array_filter([
                'make'    => $vehicle['make'] ?? $vehicle['brand'] ?? null,
                'model'   => $vehicle['model'] ?? null,
                'year'    => $vehicle['year'] ?? $vehicle['manufacturing_year'] ?? null,
                'vin'     => $vehicle['vin'] ?? null,
                'dimensions' => $vehicle['dimensions'] ?? null,
            ]);
        }

        return $x;
    }
}
