<?php

namespace App\Services;

use App\Models\Port;
use Illuminate\Support\Facades\Log;

class PortCodeValidationService
{
    /**
     * Official UN/LOCODE data for validation
     * This is manually verified data from the official UN/LOCODE database
     */
    private array $officialUnlocodeData = [
        // West Africa
        'ABJ' => ['name' => 'Abidjan', 'country' => 'Côte d\'Ivoire', 'unlocode' => 'CI ABJ'],
        'CKY' => ['name' => 'Conakry', 'country' => 'Guinea', 'unlocode' => 'GN CKY'],
        'COO' => ['name' => 'Cotonou', 'country' => 'Benin', 'unlocode' => 'BJ COO'],
        'DKR' => ['name' => 'Dakar', 'country' => 'Senegal', 'unlocode' => 'SN DKR'],
        'DLA' => ['name' => 'Douala', 'country' => 'Cameroon', 'unlocode' => 'CM DLA'],
        'LOS' => ['name' => 'Lagos', 'country' => 'Nigeria', 'unlocode' => 'NG LOS'],
        'LFW' => ['name' => 'Lomé', 'country' => 'Togo', 'unlocode' => 'TG LFW'],
        'PNR' => ['name' => 'Pointe Noire', 'country' => 'Republic of Congo', 'unlocode' => 'CG PNR'],
        
        // East Africa
        'DAR' => ['name' => 'Dar es Salaam', 'country' => 'Tanzania', 'unlocode' => 'TZ DAR'],
        'MBA' => ['name' => 'Mombasa', 'country' => 'Kenya', 'unlocode' => 'KE MBA'],
        
        // South Africa
        'DUR' => ['name' => 'Durban', 'country' => 'South Africa', 'unlocode' => 'ZA DUR'],
        'ELS' => ['name' => 'East London', 'country' => 'South Africa', 'unlocode' => 'ZA ELS'],
        'PLZ' => ['name' => 'Port Elizabeth', 'country' => 'South Africa', 'unlocode' => 'ZA PLZ'],
        'WVB' => ['name' => 'Walvis Bay', 'country' => 'Namibia', 'unlocode' => 'NA WVB'],
        
        // Europe
        'ANR' => ['name' => 'Antwerp', 'country' => 'Belgium', 'unlocode' => 'BE ANR'],
        'ZEE' => ['name' => 'Zeebrugge', 'country' => 'Belgium', 'unlocode' => 'BE ZEE'],
        'FLU' => ['name' => 'Vlissingen', 'country' => 'Netherlands', 'unlocode' => 'NL VLI'],
    ];

    /**
     * Validate a port code against UN/LOCODE database
     */
    public function validatePortCode(string $code): array
    {
        $code = strtoupper(trim($code));
        
        if (!isset($this->officialUnlocodeData[$code])) {
            return [
                'valid' => false,
                'code' => $code,
                'error' => 'Port code not found in UN/LOCODE database',
                'suggestions' => $this->findSimilarCodes($code)
            ];
        }

        return [
            'valid' => true,
            'code' => $code,
            'data' => $this->officialUnlocodeData[$code]
        ];
    }

    /**
     * Validate all ports in database against UN/LOCODE
     */
    public function validateAllPorts(): array
    {
        $ports = Port::all();
        $results = [
            'valid' => [],
            'invalid' => [],
            'summary' => [
                'total' => $ports->count(),
                'valid_count' => 0,
                'invalid_count' => 0
            ]
        ];

        foreach ($ports as $port) {
            $validation = $this->validatePortCode($port->code);
            
            if ($validation['valid']) {
                $results['valid'][] = [
                    'port' => $port,
                    'validation' => $validation
                ];
                $results['summary']['valid_count']++;
            } else {
                $results['invalid'][] = [
                    'port' => $port,
                    'validation' => $validation
                ];
                $results['summary']['invalid_count']++;
            }
        }

        return $results;
    }

    /**
     * Find similar port codes for suggestions
     */
    private function findSimilarCodes(string $code): array
    {
        $suggestions = [];
        $codeLength = strlen($code);
        
        foreach ($this->officialUnlocodeData as $officialCode => $data) {
            $similarity = $this->calculateSimilarity($code, $officialCode);
            
            if ($similarity > 0.6) { // 60% similarity threshold
                $suggestions[] = [
                    'code' => $officialCode,
                    'name' => $data['name'],
                    'country' => $data['country'],
                    'similarity' => $similarity
                ];
            }
        }

        // Sort by similarity (highest first)
        usort($suggestions, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        return array_slice($suggestions, 0, 5); // Return top 5 suggestions
    }

    /**
     * Calculate similarity between two strings
     */
    private function calculateSimilarity(string $str1, string $str2): float
    {
        $str1 = strtoupper($str1);
        $str2 = strtoupper($str2);
        
        // Levenshtein distance
        $distance = levenshtein($str1, $str2);
        $maxLength = max(strlen($str1), strlen($str2));
        
        if ($maxLength === 0) {
            return 1.0;
        }
        
        return 1 - ($distance / $maxLength);
    }

    /**
     * Get official UN/LOCODE data for a specific port
     */
    public function getOfficialData(string $code): ?array
    {
        $code = strtoupper(trim($code));
        return $this->officialUnlocodeData[$code] ?? null;
    }

    /**
     * Check if a port code exists in UN/LOCODE database
     */
    public function existsInUnlocode(string $code): bool
    {
        $code = strtoupper(trim($code));
        return isset($this->officialUnlocodeData[$code]);
    }

    /**
     * Get all available UN/LOCODE port codes
     */
    public function getAllUnlocodeCodes(): array
    {
        return array_keys($this->officialUnlocodeData);
    }

    /**
     * Validate port code format (3-letter uppercase)
     */
    public function validateFormat(string $code): array
    {
        $code = strtoupper(trim($code));
        
        if (empty($code)) {
            return [
                'valid' => false,
                'error' => 'Port code cannot be empty'
            ];
        }
        
        if (strlen($code) !== 3) {
            return [
                'valid' => false,
                'error' => 'Port code must be exactly 3 characters'
            ];
        }
        
        if (!preg_match('/^[A-Z]{3}$/', $code)) {
            return [
                'valid' => false,
                'error' => 'Port code must contain only uppercase letters'
            ];
        }
        
        return [
            'valid' => true,
            'code' => $code
        ];
    }

    /**
     * Comprehensive port validation
     */
    public function validatePort(Port $port): array
    {
        $results = [
            'port' => $port,
            'format_valid' => false,
            'unlocode_valid' => false,
            'name_match' => false,
            'country_match' => false,
            'overall_valid' => false,
            'issues' => [],
            'suggestions' => []
        ];

        // Validate format
        $formatValidation = $this->validateFormat($port->code);
        $results['format_valid'] = $formatValidation['valid'];
        
        if (!$formatValidation['valid']) {
            $results['issues'][] = $formatValidation['error'];
        }

        // Validate against UN/LOCODE
        $unlocodeValidation = $this->validatePortCode($port->code);
        $results['unlocode_valid'] = $unlocodeValidation['valid'];
        
        if ($unlocodeValidation['valid']) {
            $officialData = $unlocodeValidation['data'];
            
            // Check name match
            $results['name_match'] = $this->compareNames($port->name, $officialData['name']);
            if (!$results['name_match']) {
                $results['issues'][] = "Name mismatch: '{$port->name}' vs '{$officialData['name']}'";
            }
            
            // Check country match
            $results['country_match'] = $this->compareCountries($port->country, $officialData['country']);
            if (!$results['country_match']) {
                $results['issues'][] = "Country mismatch: '{$port->country}' vs '{$officialData['country']}'";
            }
        } else {
            $results['issues'][] = $unlocodeValidation['error'];
            $results['suggestions'] = $unlocodeValidation['suggestions'];
        }

        // Overall validation
        $results['overall_valid'] = $results['format_valid'] && 
                                   $results['unlocode_valid'] && 
                                   $results['name_match'] && 
                                   $results['country_match'];

        return $results;
    }

    /**
     * Compare port names allowing for variations
     */
    private function compareNames(string $name1, string $name2): bool
    {
        $name1 = strtolower(trim($name1));
        $name2 = strtolower(trim($name2));
        
        // Direct match
        if ($name1 === $name2) {
            return true;
        }
        
        // Handle common variations
        $variations = [
            'lagos (tin can island)' => 'lagos',
            'port elizabeth' => 'port elizabeth',
            'vlissingen' => 'vlissingen',
            'flushing' => 'vlissingen',
        ];
        
        foreach ($variations as $variant => $standard) {
            if ($name1 === $variant && $name2 === $standard) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Compare country names allowing for variations
     */
    private function compareCountries(string $country1, string $country2): bool
    {
        $country1 = strtolower(trim($country1));
        $country2 = strtolower(trim($country2));
        
        // Direct match
        if ($country1 === $country2) {
            return true;
        }
        
        // Handle common variations
        $variations = [
            'côte d\'ivoire' => 'cote d\'ivoire',
            'republic of congo' => 'congo',
            'south africa' => 'south africa',
        ];
        
        foreach ($variations as $variant => $standard) {
            if ($country1 === $variant && $country2 === $standard) {
                return true;
            }
        }
        
        return false;
    }
}
