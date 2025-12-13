<?php

namespace App\Services\Pricing;

use App\Models\Port;

class EuCountryChecker
{
    private const EU_COUNTRIES = [
        'BE', 'NL', 'DE', 'FR', 'IT', 'ES', 'PT', 'GR', 'AT', 'FI',
        'IE', 'DK', 'SE', 'PL', 'CZ', 'HU', 'SK', 'SI', 'EE', 'LV',
        'LT', 'MT', 'CY', 'LU', 'RO', 'BG', 'HR'
    ];
    
    private const COUNTRY_NAME_TO_ISO = [
        'Belgium' => 'BE',
        'Netherlands' => 'NL',
        'Germany' => 'DE',
        'France' => 'FR',
        'Italy' => 'IT',
        'Spain' => 'ES',
        'Portugal' => 'PT',
        'Greece' => 'GR',
        'Austria' => 'AT',
        'Finland' => 'FI',
        'Ireland' => 'IE',
        'Denmark' => 'DK',
        'Sweden' => 'SE',
        'Poland' => 'PL',
        'Czech Republic' => 'CZ',
        'Hungary' => 'HU',
        'Slovakia' => 'SK',
        'Slovenia' => 'SI',
        'Estonia' => 'EE',
        'Latvia' => 'LV',
        'Lithuania' => 'LT',
        'Malta' => 'MT',
        'Cyprus' => 'CY',
        'Luxembourg' => 'LU',
        'Romania' => 'RO',
        'Bulgaria' => 'BG',
        'Croatia' => 'HR',
    ];
    
    public function isEuCountry(?string $countryIso): bool
    {
        return $countryIso && in_array(strtoupper($countryIso), self::EU_COUNTRIES);
    }
    
    public function getCountryIsoFromPortString(?string $portString): ?string
    {
        if (!$portString) {
            return null;
        }
        
        // Try to extract port code from format like "Antwerp (ANR), Belgium"
        if (preg_match('/\(([A-Z]{3})\)/', $portString, $matches)) {
            $portCode = $matches[1];
            $port = Port::where('code', $portCode)->first();
            if ($port && $port->country) {
                $iso = $this->getCountryIsoFromCountryName($port->country);
                if ($iso) {
                    return $iso;
                }
                // If we found the port but country is not in mapping, check if it's definitely not EU
                if ($port->country && !$this->isCountryNameEu($port->country)) {
                    // Return a placeholder to indicate non-EU (we'll handle this in VatResolver)
                    return 'NON_EU';
                }
            }
        }
        
        // Try to extract port name from format like "Antwerp (ANR), Belgium"
        if (preg_match('/^([A-Za-z\s]+?)\s*\(/', $portString, $matches)) {
            $portName = trim($matches[1]);
            $port = Port::where('name', $portName)->first();
            if ($port && $port->country) {
                $iso = $this->getCountryIsoFromCountryName($port->country);
                if ($iso) {
                    return $iso;
                }
                // If we found the port but country is not in mapping, check if it's definitely not EU
                if ($port->country && !$this->isCountryNameEu($port->country)) {
                    return 'NON_EU';
                }
            }
        }
        
        // Try to extract country name from end of string like "...Belgium"
        if (preg_match('/,\s*([A-Za-z\s]+?)$/', $portString, $matches)) {
            $countryName = trim($matches[1]);
            $iso = $this->getCountryIsoFromCountryName($countryName);
            if ($iso) {
                return $iso;
            }
            // If country name is not in mapping, check if it's definitely not EU
            if ($countryName && !$this->isCountryNameEu($countryName)) {
                return 'NON_EU';
            }
        }
        
        return null;
    }
    
    /**
     * Check if a country name is definitely an EU country (by name, not ISO)
     */
    private function isCountryNameEu(?string $countryName): bool
    {
        if (!$countryName) {
            return false;
        }
        
        $normalized = trim($countryName);
        return isset(self::COUNTRY_NAME_TO_ISO[$normalized]);
    }
    
    public function getCountryIsoFromCountryName(?string $countryName): ?string
    {
        if (!$countryName) {
            return null;
        }
        
        $normalized = trim($countryName);
        return self::COUNTRY_NAME_TO_ISO[$normalized] ?? null;
    }
}

