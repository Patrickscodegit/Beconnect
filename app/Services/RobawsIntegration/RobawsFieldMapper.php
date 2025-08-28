<?php

namespace App\Services\RobawsIntegration;

use App\Services\RobawsIntegration\Extractors\ContactExtractor;
use App\Services\RobawsIntegration\Extractors\ShipmentExtractor;
use App\Services\RobawsIntegration\Extractors\VehicleExtractor;
use App\Services\RobawsIntegration\Formatters\RobawsFormatter;
use Illuminate\Support\Facades\Log;

class RobawsFieldMapper
{
    public function __construct(
        private ContactExtractor $contactExtractor,
        private ShipmentExtractor $shipmentExtractor,
        private VehicleExtractor $vehicleExtractor,
        private RobawsFormatter $formatter
    ) {}

    /**
     * Map extracted data to Robaws quotation format
     */
    public function mapToRobawsFormat(array $extractedData): array
    {
        Log::info('Starting Robaws field mapping', [
            'input_keys' => array_keys($extractedData),
            'has_contact' => isset($extractedData['contact']),
            'has_vehicle' => isset($extractedData['vehicle']),
            'has_shipment' => isset($extractedData['shipment']),
        ]);
        
        try {
            // Extract domain-specific data
            $contact = $this->contactExtractor->extract($extractedData);
            $shipment = $this->shipmentExtractor->extract($extractedData);
            $vehicle = $this->vehicleExtractor->extract($extractedData);
            
            Log::info('Extracted domain data', [
                'contact_name' => $contact['name'] ?? 'missing',
                'contact_email' => $contact['email'] ?? 'missing',
                'shipment_origin' => $shipment['origin'] ?? 'missing',
                'shipment_destination' => $shipment['destination'] ?? 'missing',
                'vehicle_brand' => $vehicle['brand'] ?? 'missing',
                'vehicle_model' => $vehicle['model'] ?? 'missing',
            ]);
            
            // Build the Robaws quotation structure
            $robawsData = $this->formatter->format([
                'contact' => $contact,
                'shipment' => $shipment,
                'vehicle' => $vehicle,
                'metadata' => $extractedData['metadata'] ?? [],
                'email_context' => $extractedData['email_metadata'] ?? [],
                'messages' => $extractedData['messages'] ?? [],
            ]);
            
            Log::info('Robaws mapping completed successfully', [
                'has_customer' => !empty($robawsData['customer']),
                'has_routing' => !empty($robawsData['por']) && !empty($robawsData['pod']),
                'has_cargo' => !empty($robawsData['cargo']),
                'reference' => $robawsData['customer_reference'] ?? 'missing',
            ]);
            
            return $robawsData;
            
        } catch (\Exception $e) {
            Log::error('Failed to map data to Robaws format', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'input_data' => $extractedData,
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Validate that the mapped data has minimum required fields
     */
    public function validateMappedData(array $robawsData): array
    {
        $errors = [];
        $warnings = [];
        
        // Required fields
        if (empty($robawsData['customer'])) {
            $errors[] = 'Customer name is required';
        }
        
        if (empty($robawsData['por']) || empty($robawsData['pod'])) {
            $errors[] = 'Origin and destination are required';
        }
        
        if (empty($robawsData['cargo'])) {
            $errors[] = 'Cargo description is required';
        }
        
        // Recommended fields
        if (empty($robawsData['customer_email'])) {
            $warnings[] = 'Customer email is missing';
        }
        
        if (empty($robawsData['dim_before_delivery'])) {
            $warnings[] = 'Vehicle dimensions are missing';
        }
        
        if (empty($robawsData['customer_reference'])) {
            $warnings[] = 'Customer reference is missing';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
