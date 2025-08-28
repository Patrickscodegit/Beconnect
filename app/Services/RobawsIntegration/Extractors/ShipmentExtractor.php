<?php

namespace App\Services\RobawsIntegration\Extractors;

class ShipmentExtractor
{
    private array $portMappings = [
        'Brussels' => 'Antwerp',
        'Bruxelles' => 'Antwerp',
        'Paris' => 'Le Havre',
        'Frankfurt' => 'Hamburg',
        'Munich' => 'Hamburg',
        'Milano' => 'Genoa',
        'Madrid' => 'Valencia',
        'Rome' => 'Civitavecchia',
        'Barcelona' => 'Barcelona',
        'Amsterdam' => 'Rotterdam',
        'London' => 'Southampton',
        'Birmingham' => 'Southampton',
    ];
    
    private array $cityCodeMappings = [
        'Brussels, Belgium' => 'BRU',
        'Bruxelles, Belgique' => 'BRU',
        'Brussels' => 'BRU',
        'Bruxelles' => 'BRU',
        'Antwerp, Belgium' => 'ANR',
        'Antwerp' => 'ANR',
        'Jeddah, Saudi Arabia' => 'JED',
        'Djeddah, Arabie Saoudite' => 'JED',
        'Jeddah' => 'JED',
        'Djeddah' => 'JED',
        'Dubai, UAE' => 'DXB',
        'Dubai' => 'DXB',
        'Rotterdam, Netherlands' => 'RTM',
        'Rotterdam' => 'RTM',
        'Hamburg, Germany' => 'HAM',
        'Hamburg' => 'HAM',
        'Le Havre, France' => 'LEH',
        'Le Havre' => 'LEH',
    ];
    
    /**
     * Extract shipment routing information
     */
    public function extract(array $data): array
    {
        $shipment = $this->findShipmentData($data);
        
        // If no structured data, try to extract from messages or email content
        if (empty($shipment['origin']) || empty($shipment['destination'])) {
            $shipment = array_merge($shipment, $this->extractFromContent($data));
        }
        
        $origin = $shipment['origin'] ?? null;
        $destination = $shipment['destination'] ?? null;
        
        return [
            'origin' => $origin,
            'destination' => $destination,
            'por' => $origin,
            'pol' => $this->mapToPort($origin ?? ''),
            'pod' => $destination,
            'pot' => $shipment['transshipment'] ?? null,
            'reference' => $this->buildReference($shipment, $data),
            'service_type' => 'EXP RORO',
        ];
    }
    
    private function findShipmentData(array $data): array
    {
        $sources = ['shipment', 'shipping', 'transport', 'routing'];
        
        foreach ($sources as $source) {
            if (isset($data[$source]) && is_array($data[$source])) {
                return $data[$source];
            }
        }
        
        return [];
    }
    
    private function extractFromContent(array $data): array
    {
        $result = [];
        
        // Combine all text content
        $content = $this->getAllTextContent($data);
        
        // Multiple extraction patterns
        $patterns = [
            // Pattern: "from X to Y"
            '/from\s+([^to]+?)\s+to\s+([^,\.\n]+)/i',
            // Pattern: "X - Y" or "X → Y"
            '/([A-Za-z\s,]+)\s*[-→→]\s*([A-Za-z\s,]+)/',
            // Pattern: "Origin: X Destination: Y"
            '/Origin:\s*([^,\n]+).*?Destination:\s*([^,\n]+)/is',
            // Pattern: "De X vers Y" (French)
            '/de\s+([^vers]+?)\s+vers\s+([^,\.\n]+)/i',
            // Pattern: Route information in subject
            '/route[:\s]+([^-→]+)[-→]\s*([^,\n]+)/i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $origin = trim($matches[1]);
                $destination = trim($matches[2]);
                
                // Clean up the matches
                $origin = $this->cleanLocationName($origin);
                $destination = $this->cleanLocationName($destination);
                
                if ($origin && $destination) {
                    $result['origin'] = $origin;
                    $result['destination'] = $destination;
                    break;
                }
            }
        }
        
        return $result;
    }
    
    private function getAllTextContent(array $data): string
    {
        $parts = [];
        
        // Email subject often contains route info
        if (!empty($data['email_metadata']['subject'])) {
            $parts[] = $data['email_metadata']['subject'];
        }
        
        // Messages
        if (!empty($data['messages'])) {
            foreach ($data['messages'] as $message) {
                if (!empty($message['text'])) {
                    $parts[] = $message['text'];
                }
                if (!empty($message['content'])) {
                    $parts[] = $message['content'];
                }
            }
        }
        
        // Any extracted text
        if (!empty($data['extracted_text'])) {
            $parts[] = $data['extracted_text'];
        }
        
        return implode(' ', $parts);
    }
    
    private function cleanLocationName(string $location): string
    {
        // Remove common prefixes/suffixes
        $location = preg_replace('/^(from|to|de|vers)\s+/i', '', $location);
        $location = preg_replace('/\s+(to|vers)\s+.*$/i', '', $location);
        
        // Trim and clean
        $location = trim($location, ' ,-');
        
        // Remove extra whitespace
        $location = preg_replace('/\s+/', ' ', $location);
        
        return $location;
    }
    
    private function mapToPort(string $location): string
    {
        if (empty($location)) {
            return '';
        }
        
        foreach ($this->portMappings as $city => $port) {
            if (stripos($location, $city) !== false) {
                return $port;
            }
        }
        
        return $location;
    }
    
    private function buildReference(array $shipment, array $data): string
    {
        $parts = ['EXP RORO'];
        
        // Add simplified route
        if (!empty($shipment['origin']) && !empty($shipment['destination'])) {
            $originCode = $this->getLocationCode($shipment['origin']);
            $destCode = $this->getLocationCode($shipment['destination']);
            $parts[] = $originCode . ' - ' . $destCode;
        }
        
        // Add vehicle info if available
        if (!empty($data['vehicle']['description'])) {
            $parts[] = $data['vehicle']['description'];
        } elseif (!empty($data['vehicle'])) {
            $vehicle = $data['vehicle'];
            $vehicleDesc = '1 x ';
            if (!empty($vehicle['condition'])) {
                $vehicleDesc .= ucfirst($vehicle['condition']) . ' ';
            }
            $vehicleDesc .= ($vehicle['brand'] ?? '') . ' ' . ($vehicle['model'] ?? '');
            $parts[] = trim($vehicleDesc);
        }
        
        return implode(' - ', array_filter($parts));
    }
    
    private function getLocationCode(string $location): string
    {
        // Try exact match first
        if (isset($this->cityCodeMappings[$location])) {
            return $this->cityCodeMappings[$location];
        }
        
        // Try partial match
        foreach ($this->cityCodeMappings as $fullLocation => $code) {
            if (stripos($fullLocation, $location) !== false || stripos($location, $fullLocation) !== false) {
                return $code;
            }
        }
        
        // Fallback: use first 3 characters, uppercase
        return strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $location), 0, 3));
    }
}
