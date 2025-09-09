<?php

namespace App\Support;

final class VinDetector
{
    // Basic VIN regex (excludes I, O, Q)
    private const VIN_REGEX = '/\b([A-HJ-NPR-Z0-9]{17})\b/';

    public static function detect(string $text): array
    {
        if (!$text) return [];
        
        $candidates = [];
        if (preg_match_all(self::VIN_REGEX, strtoupper($text), $matches)) {
            foreach ($matches[1] as $vin) {
                if (self::isValidVin($vin)) {
                    $candidates[] = $vin;
                }
            }
        }
        
        return array_values(array_unique($candidates));
    }

    // ISO 3779 check digit validation
    private static function isValidVin(string $vin): bool
    {
        if (strlen($vin) !== 17) return false;
        
        $transliterationMap = [
            'A'=>1,'B'=>2,'C'=>3,'D'=>4,'E'=>5,'F'=>6,'G'=>7,'H'=>8,
            'J'=>1,'K'=>2,'L'=>3,'M'=>4,'N'=>5,'P'=>7,'R'=>9,
            'S'=>2,'T'=>3,'U'=>4,'V'=>5,'W'=>6,'X'=>7,'Y'=>8,'Z'=>9,
            '0'=>0,'1'=>1,'2'=>2,'3'=>3,'4'=>4,'5'=>5,'6'=>6,'7'=>7,'8'=>8,'9'=>9,
        ];
        
        $weights = [8,7,6,5,4,3,2,10,0,9,8,7,6,5,4,3,2];

        $sum = 0;
        for ($i = 0; $i < 17; $i++) {
            $char = $vin[$i];
            if (!isset($transliterationMap[$char])) {
                return false; // Invalid character
            }
            $sum += $transliterationMap[$char] * $weights[$i];
        }
        
        $checkDigit = $sum % 11;
        $expectedCheckChar = ($checkDigit === 10) ? 'X' : (string)$checkDigit;

        return $vin[8] === $expectedCheckChar;
    }

    /**
     * Extract additional VIN information
     */
    public static function parseVin(string $vin): array
    {
        if (strlen($vin) !== 17) {
            return [];
        }

        return [
            'vin' => $vin,
            'wmi' => substr($vin, 0, 3), // World Manufacturer Identifier
            'vds' => substr($vin, 3, 6), // Vehicle Descriptor Section  
            'vis' => substr($vin, 9, 8), // Vehicle Identifier Section
            'model_year_code' => $vin[9],
            'plant_code' => $vin[10],
            'serial_number' => substr($vin, 11, 6),
            'check_digit' => $vin[8],
            'is_valid' => self::isValidVin($vin)
        ];
    }
}
