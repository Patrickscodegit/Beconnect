<?php

namespace App\Services\Ports;

use App\Models\Port;
use App\Models\PortAlias;
use App\Support\PortAliasNormalizer;

class PortResolutionService
{
    /**
     * In-memory cache for request duration
     * Key: normalized input, Value: Port or null
     */
    private array $cache = [];

    /**
     * Normalize input: trim, collapse whitespace, remove surrounding quotes, standardize separators
     * 
     * @param string $input
     * @return string
     */
    private function normalizeInput(string $input): string
    {
        // Trim
        $input = trim($input);
        
        // Remove surrounding quotes
        $input = preg_replace('/^["\']|["\']$/', '', $input);
        
        // Collapse whitespace
        $input = preg_replace('/\s+/', ' ', $input);
        
        // Standardize separators around '/', '&', ',' (but do not split here)
        $input = preg_replace('/\s*[\/&\+,]\s*/', '$0', $input);
        
        return trim($input);
    }

    /**
     * Resolve ANY string to a canonical Port (single result)
     * Strategy (in order):
     * a) normalize input
     * b) if formatted contains "(CODE)" extract CODE and try code lookup
     * c) if looks like a code (2-6 alnum) try ports.code (case-insensitive)
     * d) try exact name match (case-insensitive)
     * e) try alias match by alias_normalized (exact)
     * f) light fuzzy starts-with on alias/name (limit e.g. 5 results; pick exact if present; otherwise null to avoid wrong matches)
     * 
     * @param string $input
     * @return Port|null
     */
    public function resolveOne(string $input): ?Port
    {
        // a) Normalize input
        $normalized = $this->normalizeInput($input);
        
        if (empty($normalized)) {
            return null;
        }

        // Check cache first
        if (isset($this->cache[$normalized])) {
            return $this->cache[$normalized];
        }

        $port = null;

        // 1) UN/LOCODE match (ports.unlocode) — SEA_PORT/ICD most common, but can be any category
        if (preg_match('/^[a-z0-9]{5}$/i', $normalized)) {
            $port = Port::whereRaw('UPPER(unlocode) = ?', [strtoupper($normalized)])
                ->first();
            if ($port) {
                $this->cache[$normalized] = $port;
                return $port;
            }
        }

        // 2) IATA match (ports.iata_code) — AIRPORT
        if (preg_match('/^[a-z]{3}$/i', $normalized)) {
            $port = Port::whereRaw('UPPER(iata_code) = ?', [strtoupper($normalized)])
                ->where('port_category', 'AIRPORT')
                ->first();
            if ($port) {
                $this->cache[$normalized] = $port;
                return $port; // Return immediately (collision-safe)
            }
        }

        // 3) ICAO match (ports.icao_code) — AIRPORT
        if (preg_match('/^[a-z]{4}$/i', $normalized)) {
            $port = Port::whereRaw('UPPER(icao_code) = ?', [strtoupper($normalized)])
                ->where('port_category', 'AIRPORT')
                ->first();
            if ($port) {
                $this->cache[$normalized] = $port;
                return $port;
            }
        }

        // 4) If formatted contains "(CODE)" extract CODE and try code lookup
        if (preg_match('/\(([A-Z0-9]{2,6})\)/', $normalized, $matches)) {
            $code = strtoupper(trim($matches[1]));
            $port = Port::findByCodeInsensitive($code);
            if ($port) {
                $this->cache[$normalized] = $port;
                return $port;
            }
        }

        // 5) ports.code (case-insensitive)
        if (preg_match('/^[A-Z0-9]{2,6}$/i', $normalized)) {
            $port = Port::findByCodeInsensitive($normalized);
            if ($port) {
                $this->cache[$normalized] = $port;
                return $port;
            }
        }

        // 6) ports.name (case-insensitive exact)
        $port = Port::findByNameInsensitive($normalized);
        if ($port) {
            $this->cache[$normalized] = $port;
            return $port;
        }

        // 7) aliases.alias_normalized (exact)
        $aliasNormalized = PortAliasNormalizer::normalize($normalized);
        $alias = PortAlias::byNormalized($aliasNormalized)->active()->first();
        if ($alias && $alias->port) {
            $port = $alias->port;
            $this->cache[$normalized] = $port;
            return $port;
        }

        // 8) Small fuzzy starts-with on alias/name (limit e.g. 5 results; pick exact if present; otherwise null)
        // Try name starts-with
        $nameMatches = Port::whereRaw('UPPER(name) LIKE ?', [strtoupper($normalized) . '%'])
            ->limit(5)
            ->get();
        
        if ($nameMatches->count() === 1) {
            $port = $nameMatches->first();
            $this->cache[$normalized] = $port;
            return $port;
        }

        // Try alias starts-with
        $aliasMatches = PortAlias::where('alias_normalized', 'LIKE', $aliasNormalized . '%')
            ->active()
            ->with('port')
            ->limit(5)
            ->get()
            ->filter(fn($a) => $a->port !== null)
            ->map(fn($a) => $a->port)
            ->unique('id');

        if ($aliasMatches->count() === 1) {
            $port = $aliasMatches->first();
            $this->cache[$normalized] = $port;
            return $port;
        }

        // If multiple matches or no matches, return null to avoid wrong matches
        $this->cache[$normalized] = null;
        return null;
    }

    /**
     * Resolve combined inputs like "CAS/TFN" to array of Ports
     * - Split on separators: '/', '&', ',', ' and ', ' + '
     * - Normalize tokens; drop empties; unique tokens
     * - Resolve each token via resolveOne
     * - Return array of Ports (unique by id), ignoring nulls
     * NEVER creates combined ports - always splits and resolves separately
     * 
     * @param string $input
     * @return Port[]
     */
    public function resolveMany(string $input): array
    {
        // Split on separators
        $tokens = preg_split('/\s*[\/&\+,]\s*|\s+and\s+|\s+\+\s+/i', $input);
        
        // Normalize tokens; drop empties; unique tokens
        $tokens = array_unique(array_filter(array_map([$this, 'normalizeInput'], $tokens)));
        
        // Resolve each token via resolveOne
        $ports = [];
        foreach ($tokens as $token) {
            $port = $this->resolveOne($token);
            if ($port && !isset($ports[$port->id])) {
                $ports[$port->id] = $port;
            }
        }
        
        // Return array of Ports (unique by id)
        return array_values($ports);
    }

    /**
     * Resolve combined inputs with reporting of unresolved tokens
     * Returns: ['ports' => Port[], 'unresolved' => string[]]
     * So importers can log unresolved tokens for alias seeding
     * 
     * @param string $input
     * @return array{ports: Port[], unresolved: string[]}
     */
    public function resolveManyWithReport(string $input): array
    {
        // Split on separators
        $tokens = preg_split('/\s*[\/&\+,]\s*|\s+and\s+|\s+\+\s+/i', $input);
        
        // Normalize tokens; drop empties; unique tokens
        $tokens = array_unique(array_filter(array_map([$this, 'normalizeInput'], $tokens)));
        
        // Resolve each token via resolveOne
        $ports = [];
        $unresolved = [];
        
        foreach ($tokens as $token) {
            $port = $this->resolveOne($token);
            if ($port) {
                if (!isset($ports[$port->id])) {
                    $ports[$port->id] = $port;
                }
            } else {
                $unresolved[] = $token;
            }
        }
        
        return [
            'ports' => array_values($ports),
            'unresolved' => array_values($unresolved),
        ];
    }

    /**
     * Extract and return canonical code (uppercase) if resolvable
     * Uses resolveOne -> return uppercase port code
     * 
     * @param string $input
     * @return string|null
     */
    public function normalizeCode(string $input): ?string
    {
        $port = $this->resolveOne($input);
        return $port ? strtoupper($port->code) : null;
    }

    /**
     * Returns $port->formatFull() - canonical format
     * 
     * @param Port $port
     * @return string
     */
    public function formatCanonical(Port $port): string
    {
        return $port->formatFull();
    }
}

