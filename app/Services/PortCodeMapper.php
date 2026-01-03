<?php

namespace App\Services;

/**
 * @deprecated Use App\Services\Ports\PortResolutionService instead
 * This class is kept for backward compatibility during migration.
 * All new code should use PortResolutionService for port resolution.
 */
class PortCodeMapper
{
    /**
     * Mapping of 3-letter Robaws codes to 5-letter UN/LOCODE
     * (Optional - for future worldwide expansion)
     */
    protected static array $robawsTo5Letter = [
        'ANR' => 'BEANR',  // Antwerp, Belgium
        'CKY' => 'GNCKY',  // Conakry, Guinea
        'ZEE' => 'BEZEE',  // Zeebrugge, Belgium
        'LOS' => 'NGLOS',  // Lagos, Nigeria
        'ABJ' => 'CIABJ',  // Abidjan, Ivory Coast
        'DKR' => 'SNDAR',  // Dakar, Senegal
        'COO' => 'BJCOO',  // Cotonou, Benin
        'PNR' => 'CGPNR',  // Pointe-Noire, Congo
        'RTM' => 'NLRTM',  // Rotterdam, Netherlands
        'HAM' => 'DEHAM',  // Hamburg, Germany
        'DAR' => 'TZDAR',  // Dar es Salaam, Tanzania
        'MBA' => 'KEMBA',  // Mombasa, Kenya
        'DUR' => 'ZADUR',  // Durban, South Africa
    ];

    /**
     * Map city names to 3-letter Robaws codes
     * Used for quotation input (e.g., "Antwerp" → "ANR")
     */
    protected static array $cityToCode = [
        // Belgium
        'antwerp' => 'ANR',
        'zeebrugge' => 'ZEE',
        'ghent' => 'GNE',
        'flushing' => 'VLS',
        
        // Guinea
        'conakry' => 'CKY',
        
        // Netherlands
        'rotterdam' => 'RTM',
        'amsterdam' => 'AMS',
        
        // Germany
        'hamburg' => 'HAM',
        'bremerhaven' => 'BRV',
        
        // France
        'le havre' => 'LEH',
        'marseille' => 'MAR',
        
        // UK
        'london' => 'LON',
        'southampton' => 'SOU',
        'felixstowe' => 'FXT',
        
        // Spain
        'barcelona' => 'BCN',
        'valencia' => 'VLC',
        'algeciras' => 'ALG',
        
        // Italy
        'genoa' => 'GOA',
        'la spezia' => 'SPE',
        
        // West Africa
        'dakar' => 'DKR',
        'abidjan' => 'ABJ',
        'lagos' => 'LOS',
        'tema' => 'TEM',
        'lome' => 'LFW',
        'cotonou' => 'COO',
        'douala' => 'DLA',
        'pointe noire' => 'PNR',
        'luanda' => 'LAD',
        
        // North Africa
        'tangier' => 'PTM',
        'casablanca' => 'CAS',
        'oran' => 'ORN',
        'algiers' => 'ALG',
        'tunis' => 'TUN',
        
        // East Africa
        'mombasa' => 'MBA',
        'dar es salaam' => 'DAR',
        'durban' => 'DUR',
        
        // Middle East
        'jeddah' => 'JED',
        'dubai' => 'DXB',
        
        // Asia
        'singapore' => 'SIN',
        'shanghai' => 'SHA',
        'hong kong' => 'HKG',
    ];

    /**
     * Normalize port string to 3-letter Robaws code
     *
     * @deprecated Use App\Services\Ports\PortResolutionService::normalizeCode() instead
     * @param string|null $portString
     * @return string|null 3-letter port code
     */
    public static function normalizePortCode(?string $portString): ?string
    {
        if (empty($portString)) {
            return null;
        }

        // 1. Extract from "City, Country (ANR)" format
        if (preg_match('/\(([A-Z]{3})\)/', $portString, $matches)) {
            return $matches[1]; // Return 3-letter code directly
        }

        // 2. If it's already a 3-letter code
        if (preg_match('/^[A-Z]{3}$/', trim($portString))) {
            return strtoupper(trim($portString));
        }

        // 3. Lookup city name (extract before comma if present)
        $city = strtolower(trim(explode(',', $portString)[0]));
        return self::$cityToCode[$city] ?? null;
    }

    /**
     * Get 3-letter Robaws code from any port input format
     * Alias for normalizePortCode for clarity
     *
     * @param string|null $portString
     * @return string|null
     */
    public static function getPortCode(?string $portString): ?string
    {
        return self::normalizePortCode($portString);
    }

    /**
     * Convert 3-letter Robaws code to 5-letter UN/LOCODE
     * (Optional - for future use)
     *
     * @param string|null $code3
     * @return string|null
     */
    public static function to5LetterCode(?string $code3): ?string
    {
        if (empty($code3)) {
            return null;
        }

        $code3 = strtoupper(trim($code3));
        return self::$robawsTo5Letter[$code3] ?? null;
    }

    /**
     * Get port name from code (reverse lookup)
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
        $flipped = array_flip(self::$cityToCode);

        return $flipped[$portCode] ?? null;
    }

    /**
     * Check if a code is a valid Robaws 3-letter code
     *
     * @param string|null $code
     * @return bool
     */
    public static function isValidRobawsCode(?string $code): bool
    {
        if (empty($code)) {
            return false;
        }

        $code = strtoupper(trim($code));
        return in_array($code, self::$cityToCode) || in_array($code, array_keys(self::$robawsTo5Letter));
    }
}
