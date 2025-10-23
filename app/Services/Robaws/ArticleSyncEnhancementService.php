<?php

namespace App\Services\Robaws;

use Illuminate\Support\Facades\Log;

class ArticleSyncEnhancementService
{
    /**
     * Extract commodity type from Robaws article data
     * Looks for the "Type" field in item info section
     *
     * @param array $articleData Raw article data from Robaws API
     * @return string|null Normalized commodity type
     */
    public function extractCommodityType(array $articleData): ?string
    {
        // Try to find type in various possible locations
        $type = $articleData['type'] 
                ?? $articleData['item_type'] 
                ?? $articleData['article_type']
                ?? $articleData['commodity_type']
                ?? null;

        if (empty($type)) {
            // Try to extract from article name or description
            $name = $articleData['article_name'] ?? $articleData['name'] ?? '';
            $type = $this->extractTypeFromName($name);
        }

        return $this->normalizeCommodityType($type);
    }

    /**
     * Extract POD code from POD field
     * Handles format: "Dakar (DKR), Senegal" → "DKR"
     *
     * @param string|null $podField POD field from Robaws
     * @return string|null Extracted POD code
     */
    public function extractPodCode(?string $podField): ?string
    {
        if (empty($podField)) {
            return null;
        }

        // Match pattern: "City (CODE), Country"
        if (preg_match('/\(([A-Z]{3,4})\)/', $podField, $matches)) {
            return $matches[1];
        }

        // If it's already a code (3-4 uppercase letters)
        if (preg_match('/^[A-Z]{3,4}$/', trim($podField))) {
            return trim($podField);
        }

        Log::warning('Could not extract POD code from field', ['pod_field' => $podField]);
        return null;
    }

    /**
     * Extract POL code from POL field
     * Handles format: "Antwerp (ANR), Belgium" → "ANR"
     *
     * @param string|null $polField POL field from Robaws
     * @return string|null Extracted POL code
     */
    public function extractPolCode(?string $polField): ?string
    {
        if (empty($polField)) {
            return null;
        }

        // Match pattern: "City (CODE), Country"
        if (preg_match('/\(([A-Z]{3,4})\)/', $polField, $matches)) {
            return $matches[1];
        }

        // If it's already a code (3-4 uppercase letters)
        if (preg_match('/^[A-Z]{3,4}$/', trim($polField))) {
            return trim($polField);
        }

        Log::warning('Could not extract POL code from field', ['pol_field' => $polField]);
        return null;
    }

    /**
     * Enhance article data with extracted fields before caching
     *
     * @param array $rawArticle Raw article data from Robaws API
     * @return array Enhanced article data with commodity_type and pod_code
     */
    public function enhanceArticleData(array $rawArticle): array
    {
        // Extract commodity type
        $rawArticle['commodity_type'] = $this->extractCommodityType($rawArticle);

        // Extract POD code if POD field exists
        if (isset($rawArticle['pod']) || isset($rawArticle['pod_name'])) {
            $podField = $rawArticle['pod'] ?? $rawArticle['pod_name'] ?? null;
            $rawArticle['pod_code'] = $this->extractPodCode($podField);
        }

        // Extract POL code if POL field exists (ensure consistency)
        if (isset($rawArticle['pol']) && empty($rawArticle['pol_code'])) {
            $rawArticle['pol_code'] = $this->extractPolCode($rawArticle['pol']);
        }

        return $rawArticle;
    }

    /**
     * Extract type from article name using pattern matching
     *
     * @param string $name Article name
     * @return string|null Extracted type
     */
    private function extractTypeFromName(string $name): ?string
    {
        $name = strtolower($name);

        // Check for vehicle types
        $patterns = [
            'big van' => ['big van', 'bigvan', 'big_van'],
            'small van' => ['small van', 'smallvan', 'small_van'],
            'car' => ['car', ' car ', 'sedan'],
            'suv' => ['suv'],
            'truck' => ['truck'],
            'truckhead' => ['truckhead', 'truck head', 'tractor unit'],
            'bus' => ['bus'],
            'motorcycle' => ['motorcycle', 'motorbike', 'bike'],
            'lm cargo' => ['lm cargo', 'lm seafreight', 'lm ', ' lm', 'lane meter'],  // LM = Lane Meter (trucks & machinery)
            'machinery' => ['machinery', 'excavator', 'forklift', 'crane'],
            'boat' => ['boat', 'yacht', 'vessel'],
            'container' => ['container', '20ft', '40ft', 'fcl'],
            'break bulk' => ['break bulk', 'breakbulk', 'bb'],
        ];

        foreach ($patterns as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($name, $keyword)) {
                    return $type;
                }
            }
        }

        return null;
    }

    /**
     * Normalize commodity type to standard values
     *
     * @param string|null $type Raw type string
     * @return string|null Normalized type
     */
    private function normalizeCommodityType(?string $type): ?string
    {
        if (empty($type)) {
            return null;
        }

        $type = strtolower(trim($type));

        // Mapping of various formats to standard types
        $typeMap = [
            // Van types
            'big van' => 'Big Van',
            'bigvan' => 'Big Van',
            'big_van' => 'Big Van',
            'large van' => 'Big Van',
            
            'small van' => 'Small Van',
            'smallvan' => 'Small Van',
            'small_van' => 'Small Van',
            'compact van' => 'Small Van',
            
            // Vehicle types
            'car' => 'Car',
            'sedan' => 'Car',
            'vehicle' => 'Car',
            
            'suv' => 'SUV',
            '4x4' => 'SUV',
            
            'truck' => 'Truck',
            'lorry' => 'Truck',
            
            'truckhead' => 'Truckhead',
            'truck head' => 'Truckhead',
            'tractor unit' => 'Truckhead',
            
            'bus' => 'Bus',
            'coach' => 'Bus',
            
            'motorcycle' => 'Motorcycle',
            'motorbike' => 'Motorcycle',
            'bike' => 'Motorcycle',
            
            // Other types
            'machinery' => 'Machinery',
            'equipment' => 'Machinery',
            'plant' => 'Machinery',
            
            'boat' => 'Boat',
            'yacht' => 'Boat',
            'vessel' => 'Boat',
            
            // LM Cargo (Lane Meter - trucks & machinery)
            'lm cargo' => 'LM Cargo',
            'lm' => 'LM Cargo',
            'lane meter' => 'LM Cargo',
            'lanemeter' => 'LM Cargo',
            
            // Cargo types
            'container' => 'Container',
            '20ft' => 'Container',
            '40ft' => 'Container',
            'fcl' => 'Container',
            
            'break bulk' => 'Break Bulk',
            'breakbulk' => 'Break Bulk',
            'bb' => 'Break Bulk',
            
            'general cargo' => 'General Cargo',
            'cargo' => 'General Cargo',
            'freight' => 'General Cargo',
        ];

        return $typeMap[$type] ?? ucwords($type);
    }

    /**
     * Batch enhance multiple articles
     *
     * @param array $articles Array of raw article data
     * @return array Array of enhanced article data
     */
    public function batchEnhanceArticles(array $articles): array
    {
        return array_map(function ($article) {
            return $this->enhanceArticleData($article);
        }, $articles);
    }

    /**
     * Validate extracted data
     *
     * @param array $articleData Enhanced article data
     * @return bool True if data is valid
     */
    public function validateEnhancedData(array $articleData): bool
    {
        // Check if POD code is valid format (3-4 uppercase letters)
        if (isset($articleData['pod_code']) && !empty($articleData['pod_code'])) {
            if (!preg_match('/^[A-Z]{3,4}$/', $articleData['pod_code'])) {
                Log::warning('Invalid POD code format', [
                    'pod_code' => $articleData['pod_code'],
                    'article_id' => $articleData['id'] ?? 'unknown'
                ]);
                return false;
            }
        }

        // Check if POL code is valid format
        if (isset($articleData['pol_code']) && !empty($articleData['pol_code'])) {
            if (!preg_match('/^[A-Z]{3,4}$/', $articleData['pol_code'])) {
                Log::warning('Invalid POL code format', [
                    'pol_code' => $articleData['pol_code'],
                    'article_id' => $articleData['id'] ?? 'unknown'
                ]);
                return false;
            }
        }

        return true;
    }
}

