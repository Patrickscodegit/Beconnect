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
     * Resolve ANY string to a canonical Port (single result) with optional mode filtering
     * Strategy (in order):
     * a) normalize input
     * b) if formatted contains "(CODE)" extract CODE and try code lookup
     * c) if looks like a code (2-6 alnum) try ports.code (case-insensitive)
     * d) try exact name match (case-insensitive)
     * e) try alias match by alias_normalized (exact)
     * f) light fuzzy starts-with on alias/name (limit e.g. 5 results; pick exact if present; otherwise null to avoid wrong matches)
     * 
     * Mode-aware behavior:
     * - If input matches IATA (3 letters): return AIRPORT (even if mode is SEA, IATA is explicit)
     * - If input matches UN/LOCODE (5 chars): prefer facility type based on mode
     * - If input is city/name/alias: prefer facility type based on mode when multiple facilities exist
     * 
     * @param string $input
     * @param string|null $mode 'AIR', 'SEA', or null
     * @return Port|null
     */
    public function resolveOne(string $input, ?string $mode = null): ?Port
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

        // 1) UN/LOCODE match (ports.unlocode) — mode-aware
        if (preg_match('/^[a-z0-9]{5}$/i', $normalized)) {
            $ports = Port::whereRaw('UPPER(unlocode) = ?', [strtoupper($normalized)])
                ->where('is_active', true)
                ->get();
            
            if ($ports->count() === 1) {
                $port = $ports->first();
                $this->cache[$normalized] = $port;
                return $port;
            }
            
            // Multiple facilities with same UN/LOCODE - use mode to prefer
            if ($ports->count() > 1) {
                if ($mode === 'SEA') {
                    $port = $ports->firstWhere('port_category', 'SEA_PORT');
                    if ($port) {
                        $this->cache[$normalized] = $port;
                        return $port;
                    }
                } elseif ($mode === 'AIR') {
                    $port = $ports->firstWhere('port_category', 'AIRPORT');
                    if ($port) {
                        $this->cache[$normalized] = $port;
                        return $port;
                    }
                }
                // If mode is null, prefer SEA_PORT (default convention for UN/LOCODE)
                $port = $ports->firstWhere('port_category', 'SEA_PORT') ?? $ports->first();
                if ($port) {
                    $this->cache[$normalized] = $port;
                    return $port;
                }
            }
        }

        // 2) IATA match (ports.iata_code) — AIRPORT (even if mode is SEA, IATA is explicit)
        if (preg_match('/^[a-z]{3}$/i', $normalized)) {
            $port = Port::whereRaw('UPPER(iata_code) = ?', [strtoupper($normalized)])
                ->where('port_category', 'AIRPORT')
                ->where('is_active', true)
                ->first();
            if ($port) {
                $this->cache[$normalized] = $port;
                return $port; // Return immediately (IATA is explicit, even if mode is SEA)
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

        // 6) ports.name (case-insensitive exact) — mode-aware if multiple facilities
        $nameMatches = Port::whereRaw('UPPER(name) = ?', [strtoupper($normalized)])
            ->where('is_active', true)
            ->get();
        
        if ($nameMatches->count() === 1) {
            $port = $nameMatches->first();
            $this->cache[$normalized] = $port;
            return $port;
        }
        
        // Multiple facilities with same name - check if they share city_unlocode
        if ($nameMatches->count() > 1) {
            // Group by city_unlocode
            $byCity = $nameMatches->groupBy('city_unlocode');
            
            // If all share same city_unlocode, use mode to prefer
            if ($byCity->count() === 1) {
                $cityPorts = $byCity->first();
                if ($mode === 'SEA') {
                    $port = $cityPorts->firstWhere('port_category', 'SEA_PORT');
                    if ($port) {
                        $this->cache[$normalized] = $port;
                        return $port;
                    }
                } elseif ($mode === 'AIR') {
                    $port = $cityPorts->firstWhere('port_category', 'AIRPORT');
                    if ($port) {
                        $this->cache[$normalized] = $port;
                        return $port;
                    }
                }
                // If mode is null and multiple facilities exist, return null (ambiguous)
                $this->cache[$normalized] = null;
                return null;
            }
        }

        // 7) aliases.alias_normalized (exact) — mode-aware if multiple facilities
        $aliasNormalized = PortAliasNormalizer::normalize($normalized);
        $aliases = PortAlias::byNormalized($aliasNormalized)->active()->with('port')->get();
        
        if ($aliases->count() === 1 && $aliases->first()->port) {
            $port = $aliases->first()->port;
            // Check mode if multiple facilities exist for this city
            if ($port->city_unlocode) {
                $cityPorts = Port::byCityUnlocode($port->city_unlocode)
                    ->where('is_active', true)
                    ->get();
                
                if ($cityPorts->count() > 1) {
                    if ($mode === 'SEA') {
                        $seaPort = $cityPorts->firstWhere('port_category', 'SEA_PORT');
                        if ($seaPort) {
                            $this->cache[$normalized] = $seaPort;
                            return $seaPort;
                        }
                    } elseif ($mode === 'AIR') {
                        $airPort = $cityPorts->firstWhere('port_category', 'AIRPORT');
                        if ($airPort) {
                            $this->cache[$normalized] = $airPort;
                            return $airPort;
                        }
                    }
                    // If mode is null and multiple facilities exist, return null (ambiguous)
                    $this->cache[$normalized] = null;
                    return null;
                }
            }
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

    /**
     * Resolve city name to all facilities (airport + seaport)
     * Returns Collection of Ports for that city
     * Use mainly for admin/global search UIs (not pricing flows)
     * 
     * @param string $input City name or UN/LOCODE
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function resolveByCity(string $input): \Illuminate\Database\Eloquent\Collection
    {
        $normalized = $this->normalizeInput($input);
        
        if (empty($normalized)) {
            return collect();
        }

        // Try to find a port by name or UN/LOCODE first
        $port = $this->resolveOne($normalized);
        
        if ($port && $port->city_unlocode) {
            // Return all active facilities for this city
            return Port::byCityUnlocode($port->city_unlocode)
                ->where('is_active', true)
                ->get();
        }
        
        // If no city_unlocode found, return just the single port (if found)
        return $port ? collect([$port]) : collect();
    }
}

