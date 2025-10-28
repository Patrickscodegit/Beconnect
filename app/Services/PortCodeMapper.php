<?php

namespace App\Services;

class PortCodeMapper
{
    /**
     * Map of port names to their codes
     * Format: 'city' => 'CODE' or 'city, country' => 'CODE'
     */
    protected static array $portMapping = [
        // Belgium
        'antwerp' => 'BEANR',
        'zeebrugge' => 'BEZEE',
        'ghent' => 'BEGNE',
        
        // Guinea
        'conakry' => 'GNCKY',
        
        // Netherlands
        'rotterdam' => 'NLRTM',
        'amsterdam' => 'NLAMS',
        
        // Germany
        'hamburg' => 'DEHAM',
        'bremerhaven' => 'DEBRV',
        
        // France
        'le havre' => 'FRLEH',
        'marseille' => 'FRMAR',
        
        // UK
        'london' => 'GBLON',
        'southampton' => 'GBSOU',
        'felixstowe' => 'GBFXT',
        
        // Spain
        'barcelona' => 'ESBCN',
        'valencia' => 'ESVLC',
        'algeciras' => 'ESALG',
        
        // Italy
        'genoa' => 'ITGOA',
        'la spezia' => 'ITSPE',
        
        // West Africa
        'dakar' => 'SNDAR',
        'abidjan' => 'CIABJ',
        'lagos' => 'NGLOS',
        'tema' => 'GHTEM',
        'lome' => 'TGLFW',
        'cotonou' => 'BJCOO',
        'douala' => 'CMDLA',
        'pointe noire' => 'CGPNR',
        'luanda' => 'AOLAD',
        
        // North Africa
        'tangier' => 'MAPTM',
        'casablanca' => 'MACAS',
        'oran' => 'DZORN',
        'algiers' => 'DZALG',
        'tunis' => 'TNTUN',
        
        // East Africa
        'mombasa' => 'KEMBA',
        'dar es salaam' => 'TZDAR',
        
        // Middle East
        'jeddah' => 'SAJED',
        'dubai' => 'AEDXB',
        
        // Asia
        'singapore' => 'SGSIN',
        'shanghai' => 'CNSHA',
        'hong kong' => 'HKHKG',
    ];

    /**
     * Get port code from port name
     *
     * @param string|null $portName
     * @return string|null
     */
    public static function getPortCode(?string $portName): ?string
    {
        if (empty($portName)) {
            return null;
        }

        // Normalize: lowercase, trim
        $normalized = strtolower(trim($portName));

        // Direct match
        if (isset(self::$portMapping[$normalized])) {
            return self::$portMapping[$normalized];
        }

        // Try extracting just city name (before comma)
        if (strpos($normalized, ',') !== false) {
            $city = trim(explode(',', $normalized)[0]);
            if (isset(self::$portMapping[$city])) {
                return self::$portMapping[$city];
            }
        }

        // Try extracting code from parentheses (existing format)
        if (preg_match('/\(([A-Z]{5})\)/', $portName, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Get port name from code
     *
     * @param string|null $portCode
     * @return string|null
     */
    public static function getPortName(?string $portCode): ?string
    {
        if (empty($portCode)) {
            return null;
        }

        $portCode = strtoupper($portCode);
        $flipped = array_flip(self::$portMapping);

        return $flipped[$portCode] ?? null;
    }

    /**
     * Normalize port code format (extract if embedded in text)
     *
     * @param string|null $portString
     * @return string|null
     */
    public static function normalizePortCode(?string $portString): ?string
    {
        if (empty($portString)) {
            return null;
        }

        // If it's already a clean 5-letter code
        if (preg_match('/^[A-Z]{5}$/', trim($portString))) {
            return strtoupper(trim($portString));
        }

        // Extract from "City, Country (CODE)" format
        if (preg_match('/\(([A-Z]{5})\)/', $portString, $matches)) {
            return $matches[1];
        }

        // Try to map from city name
        return self::getPortCode($portString);
    }
}

