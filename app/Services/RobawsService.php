<?php

namespace App\Services;

use App\Models\Intake;
use Illuminate\Support\Facades\Http;

class RobawsService
{
    public function createOrUpdateOffer(Intake $intake, string $externalRef): string
    {
        logger("Creating/updating Robaws offer for intake {$intake->id}");
        
        // TODO: Implement actual Robaws API integration
        // This would typically involve:
        // 1. Authentication with Robaws API
        // 2. Formatting data according to their API schema
        // 3. Making HTTP requests to create/update offers
        // 4. Handling responses and error cases
        
        $extraction = $intake->extraction;
        if (!$extraction) {
            throw new \Exception("No extraction data found for intake {$intake->id}");
        }
        
        $data = $extraction->raw_json;
        
        // Use JsonFieldMapper for proper field mapping
        $mapper = app(\App\Services\RobawsIntegration\JsonFieldMapper::class);
        $mappedData = $mapper->mapFields($data);
        
        // Ensure JSON field is present for Robaws JSON tab
        if (!isset($mappedData['JSON']) && is_array($data)) {
            $mappedData['JSON'] = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
        
        // Log the mapped data for debugging
        logger("Robaws mapped data", [
            'intake_id' => $intake->id,
            'mapped_fields' => count($mappedData),
            'has_json_field' => isset($mappedData['JSON']),
            'sample_fields' => array_slice(array_keys($mappedData), 0, 10)
        ]);
        
        // Mock API payload construction with properly mapped fields
        $payload = [
            'external_reference' => $externalRef,
            'shipment_type' => $mappedData['shipment_type'] ?? $data['shipment_type'] ?? null,
            'origin_port' => $mappedData['por'] ?? $data['pol'] ?? null,
            'destination_port' => $mappedData['pod'] ?? $data['pod'] ?? null,
            'incoterms' => $mappedData['incoterms'] ?? $data['incoterms'] ?? null,
            'shipper' => $mappedData['customer'] ?? $data['parties']['shipper'] ?? null,
            'consignee' => $mappedData['endcustomer'] ?? $data['parties']['consignee'] ?? null,
            'json_data' => $mappedData['JSON'] ?? null, // Include raw JSON for Robaws JSON tab
            'vehicles' => array_map(function($vehicle) {
                return [
                    'make' => $vehicle['make'],
                    'model' => $vehicle['model'],
                    'year' => $vehicle['year'],
                    'vin' => $vehicle['vin'],
                    'dimensions' => $vehicle['dims_m'],
                    'weight_kg' => $vehicle['weight_kg'],
                    'cbm' => $vehicle['cbm']
                ];
            }, $data['vehicles'] ?? [])
        ];
        
        // Mock API call
        /*
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.robaws.api_key'),
            'Content-Type' => 'application/json',
        ])->post(config('services.robaws.api_url') . '/offers', $payload);
        
        if (!$response->successful()) {
            throw new \Exception("Robaws API error: " . $response->body());
        }
        
        $result = $response->json();
        return $result['offer_id'];
        */
        
        // Mock response
        $mockOfferId = 'ROBAWS-' . date('Ymd') . '-' . str_pad($intake->id, 6, '0', STR_PAD_LEFT);
        
        logger("Robaws offer created: {$mockOfferId} for intake {$intake->id}");
        
        return $mockOfferId;
    }
}
