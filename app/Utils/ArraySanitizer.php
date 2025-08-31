<?php

namespace App\Utils;

class ArraySanitizer
{
    /**
     * Clean placeholder values from nested arrays and objects
     */
    public static function cleanPlaceholders(mixed $v): mixed
    {
        if (is_array($v)) {
            $cleaned = [];
            foreach ($v as $key => $value) {
                $cleanedValue = self::cleanPlaceholders($value);
                if ($cleanedValue !== null) {
                    $cleaned[$key] = $cleanedValue;
                }
            }
            return $cleaned;
        }
        
        if (!is_string($v)) {
            return $v;
        }
        
        $s = mb_strtolower(trim($v));
        $placeholders = ['n/a', 'na', 'n\\a', 'unknown', '(unknown)', '--', '-', 'null', 'undefined'];
        
        return in_array($s, $placeholders, true) ? null : trim($v);
    }
    
    /**
     * Canonical shipping type normalization
     */
    public static function canonicalShipType(?string $v): ?string
    {
        $v = $v ? mb_strtolower($v) : null;
        return match ($v) {
            'roro', 'ro-ro', 'roulier' => 'roro',
            'container', 'conteneur' => 'container',
            'air', 'aérien' => 'air',
            default => $v,
        };
    }
    
    /**
     * Infer company from contact data
     */
    public static function inferCompany(array $contact): ?string
    {
        if (!empty($contact['company'])) {
            return $contact['company'];
        }
        
        if (!empty($contact['email']) && preg_match('/@([^>]+)$/', $contact['email'], $m)) {
            $domain = strtolower($m[1]);
            $host = preg_replace('/\.(com|net|org|be|fr|de|nl|uk|sa|eu)$/', '', $domain);
            
            if ($host && !str_contains($host, 'gmail') && !str_contains($host, 'outlook') && !str_contains($host, 'yahoo')) {
                return ucwords(str_replace(['-', '.'], ' ', $host));
            }
        }
        
        return $contact['name'] ?? null;
    }
}
