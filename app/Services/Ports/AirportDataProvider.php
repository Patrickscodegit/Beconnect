<?php

namespace App\Services\Ports;

/**
 * Airport Data Provider
 * 
 * Reads airport data from CSV or OpenFlights airports.dat format.
 * Supports IATA and ICAO code matching for airport enrichment.
 */
class AirportDataProvider
{
    /**
     * Read airport data from a file.
     * Detects format automatically (CSV or OpenFlights).
     * 
     * @param string $path File path
     * @return \Generator Yields associative arrays with airport data
     * @throws \RuntimeException If file cannot be read
     */
    public function readFromPath(string $path): \Generator
    {
        if (!file_exists($path) || !is_readable($path)) {
            throw new \RuntimeException("Cannot read airport file: {$path}");
        }

        // Detect format by extension or content
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        
        if ($extension === 'dat' || $this->isOpenFlightsFormat($path)) {
            yield from $this->readOpenFlightsFormat($path);
        } else {
            yield from $this->readCsvFormat($path);
        }
    }

    /**
     * Check if file is in OpenFlights format
     */
    protected function isOpenFlightsFormat(string $path): bool
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return false;
        }

        $firstLine = fgets($handle);
        fclose($handle);

        // OpenFlights format: tab-separated, typically starts with airport ID
        return $firstLine !== false && strpos($firstLine, "\t") !== false;
    }

    /**
     * Read OpenFlights airports.dat format
     * 
     * Format (tab-separated):
     * Airport ID, Name, City, Country, IATA, ICAO, Latitude, Longitude, ...
     * 
     * @param string $path File path
     * @return \Generator
     */
    protected function readOpenFlightsFormat(string $path): \Generator
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Failed to open file: {$path}");
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line) || strpos($line, '#') === 0) {
                    continue; // Skip comments and empty lines
                }

                $fields = explode("\t", $line);
                if (count($fields) < 8) {
                    continue; // Skip invalid rows
                }

                $parsed = $this->parseOpenFlightsRow($fields);
                if ($parsed !== null) {
                    yield $parsed;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Parse a single OpenFlights row
     */
    protected function parseOpenFlightsRow(array $fields): ?array
    {
        // OpenFlights format (tab-separated):
        // 0: Airport ID
        // 1: Name
        // 2: City
        // 3: Country
        // 4: IATA (3-letter code)
        // 5: ICAO (4-letter code)
        // 6: Latitude
        // 7: Longitude
        // ... (more fields, but we don't need them)

        $iata = trim($fields[4] ?? '');
        $icao = trim($fields[5] ?? '');

        // Skip if no IATA or ICAO code
        if (empty($iata) && empty($icao)) {
            return null;
        }

        $lat = $this->parseFloat($fields[6] ?? '');
        $lon = $this->parseFloat($fields[7] ?? '');

        $coordinates = null;
        if ($lat !== null && $lon !== null) {
            $coordinates = sprintf('%.6f,%.6f', $lat, $lon);
        }

        return [
            'iata_code' => strtoupper($iata),
            'icao_code' => strtoupper($icao),
            'name' => trim($fields[1] ?? ''),
            'city' => trim($fields[2] ?? ''),
            'country' => trim($fields[3] ?? ''),
            'coordinates' => $coordinates,
        ];
    }

    /**
     * Read CSV format
     * 
     * Expected columns (flexible header matching):
     * - IATA or iata_code
     * - ICAO or icao_code
     * - Name or name
     * - City or city
     * - Country or country
     * - Latitude or lat
     * - Longitude or lon
     * 
     * @param string $path File path
     * @return \Generator
     */
    protected function readCsvFormat(string $path): \Generator
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

            // Normalize headers
            $headers = array_map(function ($header) {
                return strtolower(trim($header));
            }, $headers);

            // Map headers to standard keys
            $headerMap = [
                'iata' => 'iata_code',
                'iata code' => 'iata_code',
                'icao' => 'icao_code',
                'icao code' => 'icao_code',
                'name' => 'name',
                'city' => 'city',
                'country' => 'country',
                'latitude' => 'lat',
                'lat' => 'lat',
                'longitude' => 'lon',
                'lon' => 'lon',
                'lng' => 'lon',
            ];

            // Read data rows
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 2) {
                    continue;
                }

                // Map row data
                $data = [];
                foreach ($headers as $index => $header) {
                    $value = $row[$index] ?? '';
                    $key = $headerMap[$header] ?? $header;
                    $data[$key] = trim($value);
                }

                $parsed = $this->parseCsvRow($data);
                if ($parsed !== null) {
                    yield $parsed;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Parse a CSV row
     */
    protected function parseCsvRow(array $data): ?array
    {
        $iata = strtoupper(trim($data['iata_code'] ?? ''));
        $icao = strtoupper(trim($data['icao_code'] ?? ''));

        // Skip if no IATA or ICAO code
        if (empty($iata) && empty($icao)) {
            return null;
        }

        // Parse coordinates
        $coordinates = null;
        $lat = $this->parseFloat($data['lat'] ?? '');
        $lon = $this->parseFloat($data['lon'] ?? '');
        if ($lat !== null && $lon !== null) {
            $coordinates = sprintf('%.6f,%.6f', $lat, $lon);
        }

        return [
            'iata_code' => $iata,
            'icao_code' => $icao,
            'name' => trim($data['name'] ?? ''),
            'city' => trim($data['city'] ?? ''),
            'country' => trim($data['country'] ?? ''),
            'coordinates' => $coordinates,
        ];
    }

    /**
     * Parse a float value, returning null if invalid
     */
    protected function parseFloat(?string $value): ?float
    {
        if (empty($value)) {
            return null;
        }

        $value = trim($value);
        $float = filter_var($value, FILTER_VALIDATE_FLOAT);
        
        return $float !== false ? $float : null;
    }
}

