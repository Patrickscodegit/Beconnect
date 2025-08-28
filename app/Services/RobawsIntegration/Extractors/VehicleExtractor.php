<?php

namespace App\Services\RobawsIntegration\Extractors;

class VehicleExtractor
{
    /**
     * Extract vehicle information with intelligent parsing
     */
    public function extract(array $data): array
    {
        $vehicle = $this->findVehicleData($data);
        
        // Parse vehicle description if no structured data
        if (empty($vehicle['brand']) && !empty($data['cargo_description'])) {
            $vehicle = array_merge($vehicle, $this->parseVehicleDescription($data['cargo_description']));
        }
        
        // Try to extract from messages if still missing
        if (empty($vehicle['brand']) && isset($data['messages'])) {
            $vehicle = array_merge($vehicle, $this->extractFromMessages($data['messages']));
        }
        
        // Calculate dimensions
        $dimensions = $this->extractDimensions($vehicle, $data);
        
        return array_merge($vehicle, [
            'dimensions' => $dimensions,
            'volume_m3' => $this->calculateVolume($dimensions),
            'formatted_dimensions' => $this->formatDimensions($dimensions),
            'description' => $this->buildDescription($vehicle),
        ]);
    }
    
    private function findVehicleData(array $data): array
    {
        // Try multiple possible locations
        $sources = ['vehicle', 'vehicle_details', 'cargo', 'vehicle_info'];
        
        foreach ($sources as $source) {
            if (isset($data[$source]) && is_array($data[$source])) {
                return $data[$source];
            }
        }
        
        return [];
    }
    
    private function extractFromMessages(array $messages): array
    {
        $result = [];
        
        foreach ($messages as $message) {
            $text = $message['text'] ?? $message['content'] ?? '';
            
            // Look for vehicle brand and model patterns
            $patterns = [
                '/(?:Vehicle|Car|Auto):\s*([A-Za-z-]+)\s+([A-Za-z0-9\s-]+)/i',
                '/([A-Za-z-]+)\s+([A-Za-z0-9\s-]+)\s+(?:car|vehicle|auto)/i',
                '/(BMW|Mercedes|Audi|Volkswagen|Toyota|Honda|Ford|Peugeot|Renault|Citroen)\s+([A-Za-z0-9\s-]+)/i'
            ];
            
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text, $matches)) {
                    $result['brand'] = trim($matches[1]);
                    $result['model'] = trim($matches[2]);
                    break 2;
                }
            }
            
            // Look for condition
            if (preg_match('/(new|used|neuf|occasion|d\'occasion)/i', $text, $matches)) {
                $result['condition'] = strtolower($matches[1]) === 'neuf' ? 'new' : 
                                     (in_array(strtolower($matches[1]), ['occasion', 'd\'occasion']) ? 'used' : strtolower($matches[1]));
            }
        }
        
        return $result;
    }
    
    private function parseVehicleDescription(string $description): array
    {
        $result = [];
        
        // Parse patterns like "BMW Series 7", "Mercedes-Benz Sprinter"
        if (preg_match('/(\w+(?:-\w+)?)\s+(.+)/i', $description, $matches)) {
            $result['brand'] = $matches[1];
            $result['model'] = $matches[2];
        }
        
        // Parse condition (new/used)
        if (preg_match('/(new|used|neuf|occasion)/i', $description, $matches)) {
            $result['condition'] = strtolower($matches[1]);
        }
        
        return $result;
    }
    
    private function extractDimensions(array $vehicle, array $data): array
    {
        // Check for structured dimensions in vehicle data
        if (!empty($vehicle['dimensions'])) {
            return $vehicle['dimensions'];
        }
        
        // Try to parse from various text sources
        $textSources = [
            $data['extracted_text'] ?? '',
            $data['cargo_description'] ?? '',
        ];
        
        // Add messages text
        if (!empty($data['messages'])) {
            foreach ($data['messages'] as $message) {
                $textSources[] = $message['text'] ?? $message['content'] ?? '';
            }
        }
        
        foreach ($textSources as $text) {
            // Look for dimension patterns
            $patterns = [
                '/(\d+\.?\d*)\s*x\s*(\d+\.?\d*)\s*x\s*(\d+\.?\d*)\s*m(?:etres?)?/i',
                '/L:\s*(\d+\.?\d*)\s*W:\s*(\d+\.?\d*)\s*H:\s*(\d+\.?\d*)/i',
                '/Length:\s*(\d+\.?\d*)\s*Width:\s*(\d+\.?\d*)\s*Height:\s*(\d+\.?\d*)/i',
            ];
            
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text, $matches)) {
                    return [
                        'length_m' => floatval($matches[1]),
                        'width_m' => floatval($matches[2]),
                        'height_m' => floatval($matches[3]),
                    ];
                }
            }
        }
        
        return [];
    }
    
    private function calculateVolume(array $dimensions): ?float
    {
        if (empty($dimensions['length_m']) || empty($dimensions['width_m']) || empty($dimensions['height_m'])) {
            return null;
        }
        
        return round(
            floatval($dimensions['length_m']) * 
            floatval($dimensions['width_m']) * 
            floatval($dimensions['height_m']), 
            2
        );
    }
    
    private function formatDimensions(array $dimensions): ?string
    {
        if (empty($dimensions)) {
            return null;
        }
        
        $volume = $this->calculateVolume($dimensions);
        
        return sprintf(
            '%.3f x %.2f x %.3f m // %.2f Cbm',
            $dimensions['length_m'] ?? 0,
            $dimensions['width_m'] ?? 0,
            $dimensions['height_m'] ?? 0,
            $volume ?? 0
        );
    }
    
    private function buildDescription(array $vehicle): string
    {
        $parts = ['1 x'];
        
        if (!empty($vehicle['condition'])) {
            $parts[] = ucfirst($vehicle['condition']);
        }
        
        if (!empty($vehicle['brand'])) {
            $parts[] = $vehicle['brand'];
        }
        
        if (!empty($vehicle['model'])) {
            $parts[] = $vehicle['model'];
        }
        
        if (count($parts) === 1) {
            $parts[] = 'Vehicle';
        }
        
        return implode(' ', $parts);
    }
}
