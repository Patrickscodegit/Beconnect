<?php

namespace App\Services\Ports;

/**
 * UN/LOCODE Data Provider
 * 
 * Reads and parses UN/LOCODE CSV files.
 * Supports multiple CSV files (CodeListPart1, CodeListPart2, CodeListPart3).
 * 
 * UN/LOCODE CSV format typically includes:
 * - Country (2-letter ISO code)
 * - Location (3-letter code)
 * - Name
 * - Coordinates (DDMMN DDDMME format)
 * - Function codes (port type indicators)
 */
class UnlocodeDataProvider
{
    /**
     * Read UN/LOCODE data from one or more CSV file paths.
     * 
     * @param array $paths Array of file paths to CSV files
     * @return \Generator Yields associative arrays with parsed data
     * @throws \RuntimeException If file cannot be read
     */
    public function readFromPaths(array $paths): \Generator
    {
        foreach ($paths as $path) {
            if (!file_exists($path) || !is_readable($path)) {
                throw new \RuntimeException("Cannot read UN/LOCODE file: {$path}");
            }

            yield from $this->readFromFile($path);
        }
    }

    /**
     * Read and parse a single UN/LOCODE CSV file.
     * 
     * @param string $path File path
     * @return \Generator Yields associative arrays
     */
    protected function readFromFile(string $path): \Generator
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Failed to open file: {$path}");
        }

        try {
            // Read header row
            $headers = fgetcsv($handle);
            if ($headers === false) {
                return; // Empty file
            }

            // Normalize headers (trim, lowercase for matching)
            $headers = array_map(function ($header) {
                return strtolower(trim($header));
            }, $headers);

            // Map common header variations to our standard keys
            $headerMap = $this->getHeaderMap();

            // Read data rows
            $lineNumber = 1;
            while (($row = fgetcsv($handle)) !== false) {
                $lineNumber++;

                if (count($row) < 2) {
                    continue; // Skip empty/invalid rows
                }

                // Map row data to associative array using headers
                $data = [];
                foreach ($headers as $index => $header) {
                    $value = $row[$index] ?? '';
                    $value = trim($value);
                    
                    // Map header to our standard key
                    $key = $headerMap[$header] ?? $header;
                    $data[$key] = $value;
                }

                // Extract and normalize data
                $parsed = $this->parseRow($data);
                if ($parsed !== null) {
                    yield $parsed;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Map common UN/LOCODE CSV header variations to standard keys.
     * 
     * @return array
     */
    protected function getHeaderMap(): array
    {
        return [
            'country' => 'country_code',
            'country code' => 'country_code',
            'ch' => 'country_code',
            'location' => 'location_code',
            'location code' => 'location_code',
            'lo' => 'location_code',
            'name' => 'name',
            'name wo diacritics' => 'name',
            'namewithdiacritics' => 'name',
            'subdiv' => 'sub_div',
            'subdivision' => 'sub_div',
            'subdivision code' => 'sub_div',
            'function' => 'function',
            'function code' => 'function',
            'coordinates' => 'coordinates',
            'coordinates (wgs84)' => 'coordinates',
            'status' => 'status',
            'date' => 'date',
            'iata' => 'iata',
            'iata code' => 'iata',
        ];
    }

    /**
     * Parse a single row of UN/LOCODE data.
     * 
     * @param array $data Raw row data
     * @return array|null Parsed data or null if invalid
     */
    protected function parseRow(array $data): ?array
    {
        $countryCode = strtoupper(trim($data['country_code'] ?? ''));
        $locationCode = strtoupper(trim($data['location_code'] ?? ''));

        // Skip if missing essential fields
        if (empty($countryCode) || empty($locationCode)) {
            return null;
        }

        // Validate country code (2 letters)
        if (strlen($countryCode) !== 2 || !ctype_alpha($countryCode)) {
            return null;
        }

        // Validate location code (3 alphanumeric)
        if (strlen($locationCode) !== 3) {
            return null;
        }

        // Build UN/LOCODE (concatenated: BEANR)
        $unlocode = $countryCode . $locationCode;

        // Parse coordinates
        $coordinates = $this->parseCoordinates($data['coordinates'] ?? '');

        // Map function code to port category
        $portCategory = $this->mapFunctionToCategory($data['function'] ?? '');

        return [
            'country_code' => $countryCode,
            'location_code' => $locationCode,
            'unlocode' => $unlocode,
            'name' => trim($data['name'] ?? ''),
            'sub_div' => trim($data['sub_div'] ?? ''),
            'function' => trim($data['function'] ?? ''),
            'coordinates' => $coordinates,
            'coordinates_raw' => trim($data['coordinates'] ?? ''),
            'status' => trim($data['status'] ?? ''),
            'date' => trim($data['date'] ?? ''),
            'port_category' => $portCategory,
        ];
    }

    /**
     * Parse UN/LOCODE coordinate format to decimal lat/lon.
     * 
     * Format: "DDMMN DDDMME" or "DDMMSSN DDDMMSSE"
     * Examples:
     * - "5126N 00424E" => 51.4333, 4.4000
     * - "512630N 0042400E" => 51.4417, 4.4000
     * 
     * @param string $coords Raw coordinate string
     * @return string|null Decimal coordinates as "lat,lon" or null if unparseable
     */
    protected function parseCoordinates(string $coords): ?string
    {
        $coords = trim($coords);
        if (empty($coords)) {
            return null;
        }

        // Pattern: DDMMN DDDMME or DDMMSSN DDDMMSSE
        // Match: digits, N/S, space, digits, E/W
        if (!preg_match('/^(\d+)([NS])\s+(\d+)([EW])$/i', $coords, $matches)) {
            return null;
        }

        $latDigits = $matches[1];
        $latDir = strtoupper($matches[2]);
        $lonDigits = $matches[3];
        $lonDir = strtoupper($matches[4]);

        // Parse latitude
        $lat = $this->parseDmsToDecimal($latDigits, $latDir === 'S');
        if ($lat === null) {
            return null;
        }

        // Parse longitude
        $lon = $this->parseDmsToDecimal($lonDigits, $lonDir === 'W');
        if ($lon === null) {
            return null;
        }

        return sprintf('%.6f,%.6f', $lat, $lon);
    }

    /**
     * Parse degrees/minutes/seconds string to decimal degrees.
     * 
     * Supports:
     * - DDMM (4 digits: degrees + minutes)
     * - DDMMSS (6 digits: degrees + minutes + seconds)
     * 
     * @param string $dms Degrees/minutes/seconds string
     * @param bool $negative If true, result is negative
     * @return float|null Decimal degrees or null if invalid
     */
    protected function parseDmsToDecimal(string $dms, bool $negative): ?float
    {
        $len = strlen($dms);
        
        if ($len === 4) {
            // DDMM format
            $degrees = (int) substr($dms, 0, 2);
            $minutes = (int) substr($dms, 2, 2);
            $seconds = 0;
        } elseif ($len === 6) {
            // DDMMSS format
            $degrees = (int) substr($dms, 0, 2);
            $minutes = (int) substr($dms, 2, 2);
            $seconds = (int) substr($dms, 4, 2);
        } else {
            return null; // Unsupported format
        }

        $decimal = $degrees + ($minutes / 60.0) + ($seconds / 3600.0);
        
        return $negative ? -$decimal : $decimal;
    }

    /**
     * Map UN/LOCODE function code to port category.
     * 
     * Function codes indicate port capabilities:
     * - '4' = Airport (highest priority)
     * - '1' = Seaport
     * - '6' or '8' = Inland/rail terminal (ICD)
     * 
     * @param string $function Raw function code string (e.g., "1-3-----", "4-----")
     * @return string Port category: AIRPORT, SEA_PORT, ICD, or UNKNOWN
     */
    public function mapFunctionToCategory(string $function): string
    {
        $function = trim($function);
        if (empty($function)) {
            return 'UNKNOWN';
        }

        // Check for airport (highest priority)
        if (strpos($function, '4') !== false) {
            return 'AIRPORT';
        }

        // Check for seaport
        if (strpos($function, '1') !== false) {
            return 'SEA_PORT';
        }

        // Check for inland/rail terminal (ICD)
        if (strpos($function, '6') !== false || strpos($function, '8') !== false) {
            return 'ICD';
        }

        return 'UNKNOWN';
    }
}

