<?php

namespace App\Services\RobawsIntegration\Formatters;

class RobawsFormatter
{
    /**
     * Format extracted data into Robaws quotation structure
     */
    public function format(array $data): array
    {
        $contact = $data['contact'] ?? [];
        $shipment = $data['shipment'] ?? [];
        $vehicle = $data['vehicle'] ?? [];
        $metadata = $data['metadata'] ?? [];
        
        return [
            // Customer Information
            'customer' => $contact['name'] ?? 'Unknown Customer',
            'customer_email' => $contact['email'],
            'customer_phone' => $contact['phone'],
            'customer_company' => $contact['company'],
            
            // Routing Information
            'por' => $shipment['por'] ?? $shipment['origin'],
            'pol' => $shipment['pol'] ?? $shipment['origin'],
            'pod' => $shipment['pod'] ?? $shipment['destination'],
            'pot' => $shipment['pot'],
            
            // Service Information
            'service_type' => $shipment['service_type'] ?? 'EXP RORO',
            'customer_reference' => $shipment['reference'] ?? $this->buildDefaultReference($data),
            
            // Cargo Information
            'cargo' => $vehicle['description'] ?? '1 x Vehicle',
            'cargo_type' => 'Vehicle',
            'dim_before_delivery' => $vehicle['formatted_dimensions'],
            'volume_m3' => $vehicle['volume_m3'],
            
            // Dimensions (detailed)
            'dimensions' => $vehicle['dimensions'] ?? [],
            
            // Additional Information
            'internal_remarks' => $this->buildInternalRemarks($data),
            'processing_notes' => $this->buildProcessingNotes($metadata),
            
            // Timestamps
            'created_at' => now()->toIso8601String(),
            'formatted_at' => now()->toIso8601String(),
            
            // Source tracking
            'source' => 'email_extraction',
            'extraction_version' => '2.0',
        ];
    }
    
    private function buildDefaultReference(array $data): string
    {
        $parts = ['EXP RORO'];
        
        $contact = $data['contact'] ?? [];
        $shipment = $data['shipment'] ?? [];
        $vehicle = $data['vehicle'] ?? [];
        
        // Add route if available
        if (!empty($shipment['origin']) && !empty($shipment['destination'])) {
            $parts[] = $shipment['origin'] . ' - ' . $shipment['destination'];
        }
        
        // Add vehicle info
        if (!empty($vehicle['brand']) || !empty($vehicle['model'])) {
            $vehiclePart = '1 x ';
            if (!empty($vehicle['condition'])) {
                $vehiclePart .= ucfirst($vehicle['condition']) . ' ';
            }
            $vehiclePart .= trim(($vehicle['brand'] ?? '') . ' ' . ($vehicle['model'] ?? ''));
            $parts[] = $vehiclePart;
        }
        
        return implode(' - ', array_filter($parts));
    }
    
    private function buildInternalRemarks(array $data): ?string
    {
        $remarks = [];
        
        // Add email metadata context
        if (!empty($data['email_context']['subject'])) {
            $remarks[] = 'Subject: ' . $data['email_context']['subject'];
        }
        
        // Add relevant messages
        if (!empty($data['messages'])) {
            $messageTexts = [];
            foreach ($data['messages'] as $message) {
                if (!empty($message['text'])) {
                    // Limit message length
                    $text = strlen($message['text']) > 200 
                        ? substr($message['text'], 0, 200) . '...' 
                        : $message['text'];
                    $messageTexts[] = $text;
                }
            }
            
            if (!empty($messageTexts)) {
                $remarks[] = 'Messages: ' . implode(' | ', $messageTexts);
            }
        }
        
        // Add processing context
        if (!empty($data['metadata']['processing_notes'])) {
            $remarks[] = 'Processing: ' . $data['metadata']['processing_notes'];
        }
        
        return !empty($remarks) ? implode("\n", $remarks) : null;
    }
    
    private function buildProcessingNotes(array $metadata): ?string
    {
        $notes = [];
        
        if (!empty($metadata['extraction_method'])) {
            $notes[] = 'Extraction: ' . $metadata['extraction_method'];
        }
        
        if (!empty($metadata['confidence_score'])) {
            $notes[] = 'Confidence: ' . $metadata['confidence_score'];
        }
        
        if (!empty($metadata['ai_model'])) {
            $notes[] = 'AI Model: ' . $metadata['ai_model'];
        }
        
        return !empty($notes) ? implode(' | ', $notes) : null;
    }
}
