<?php

namespace App\Services\Countries;

class CountryService
{
    /**
     * Get all countries as an array suitable for select options
     * Returns unique country names (removes aliases, keeps canonical names)
     *
     * @return array
     */
    public static function getCountryOptions(): array
    {
        $countries = config('countries', []);
        
        // Get unique country values (canonical names)
        $uniqueCountries = array_unique(array_values($countries));
        
        // Sort alphabetically
        sort($uniqueCountries);
        
        // Return as key => value for select options
        return array_combine($uniqueCountries, $uniqueCountries);
    }

    /**
     * Get all countries including aliases
     * Useful for search/matching where aliases should be included
     *
     * @return array
     */
    public static function getAllCountriesWithAliases(): array
    {
        return config('countries', []);
    }

    /**
     * Normalize a country name to its canonical form
     * Handles aliases and variations
     *
     * @param string|null $countryName
     * @return string|null
     */
    public static function normalizeCountryName(?string $countryName): ?string
    {
        if (empty($countryName)) {
            return null;
        }

        $countries = config('countries', []);
        $normalized = trim($countryName);
        
        // Direct lookup (case-insensitive)
        foreach ($countries as $key => $canonical) {
            if (strcasecmp($key, $normalized) === 0) {
                return $canonical;
            }
        }
        
        // Partial match (case-insensitive)
        foreach ($countries as $key => $canonical) {
            if (stripos($key, $normalized) !== false || stripos($normalized, $key) !== false) {
                return $canonical;
            }
        }
        
        // If no match found, return original (might be a valid country not in our list)
        return $normalized;
    }

    /**
     * Check if a country name exists (including aliases)
     *
     * @param string $countryName
     * @return bool
     */
    public static function countryExists(string $countryName): bool
    {
        $countries = config('countries', []);
        $normalized = trim($countryName);
        
        foreach ($countries as $key => $canonical) {
            if (strcasecmp($key, $normalized) === 0 || strcasecmp($canonical, $normalized) === 0) {
                return true;
            }
        }
        
        return false;
    }
}

