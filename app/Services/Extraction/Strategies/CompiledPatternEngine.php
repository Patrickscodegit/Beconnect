<?php

namespace App\Services\Extraction\Strategies;

use Illuminate\Support\Facades\Log;

/**
 * COMPILED PATTERN ENGINE
 * 
 * Pre-compiles regex patterns for faster matching during PDF extraction.
 * Patterns are compiled once at service startup and reused for all extractions.
 */
class CompiledPatternEngine
{
    private array $patterns = [];
    private bool $initialized = false;

    public function __construct()
    {
        $this->initializePatterns();
    }

    /**
     * Initialize and compile all patterns
     */
    private function initializePatterns(): void
    {
        if ($this->initialized) {
            return;
        }

        Log::info('Initializing compiled pattern engine');

        $this->patterns = [
            // Contact patterns
            'shipper' => '/Shipper\s+([A-Za-z\s\.&,]+?)\s+([A-Za-z\s]+)\s+(\d+)\s+([A-Za-z0-9\s,]+?)(?=\s+[A-Z]{2,}\s+[A-Za-z]+\s+[A-Za-z]+|\s+Destination|\s+Consignee)/',
            'consignee' => '/^([A-Za-z\s\.&,]+?)\s+(?:No\.\s+(\d+)\s+([A-Za-z0-9\s,()]+?)\s+([A-Za-z\s,]+?)|Road\s+(\d+)\s+([A-Za-z0-9\s,()]+?)\s+([A-Za-z\s,]+?))\s+([A-Za-z0-9@\.]+)\s+(\+\d+\s+\d+)/',
            'notify' => '/Notify\s+([A-Za-z\s\.&,]+?)\s+(?:No\.\s+(\d+)\s+([A-Za-z0-9\s,()]+?)\s+([A-Za-z\s,]+?)|Road\s+(\d+)\s+([A-Za-z0-9\s,()]+?)\s+([A-Za-z\s,]+?))\s+([A-Za-z0-9@\.]+)\s+(\+\d+\s+\d+)/',
            
            // Vehicle patterns
            'vehicle_model' => '/CategoryMake\s+VIN\/Serialnumber\s+YearWeightType\s+Truck\s+(\w+)\s+(\w+)\s+([A-Z0-9]+)\s+(\d{4})([\d.]+)/i',
            'vin' => '/Chassis\s+nr:\s+([A-Z0-9]+)/i',
            'year' => '/Year:\s+(\d{4})/i',
            
            // Routing patterns
            'por' => '/POR\s+([A-Za-z\s,]+?)(?=\s+POL|\s+Destination)/i',
            'pol' => '/POL\s+([A-Za-z\s,]+?)(?=\s+Destination|\s+Consignee)/i',
            'destination' => '/Destination\s+([A-Za-z\s,]+?)(?=\s+Consignee|\s+Notify)/i',
            
            // Cargo patterns
            'cargo' => '/CategoryMake\s+VIN\/Serialnumber\s+YearWeightType\s+Truck\s+(\w+)\s+(\w+)\s+([A-Z0-9]+)\s+(\d{4})([\d.]+)/i',
            
            // Phone patterns (with exclusions)
            'phone' => '/(?<!Booking\s)(?<!251001115946)\+?\d{1,4}[\s\-]?\d{1,4}[\s\-]?\d{1,4}[\s\-]?\d{1,4}/',
            
            // Email patterns
            'email' => '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
            
            // Address patterns
            'address' => '/(?:No\.\s+\d+|Road\s+\d+)\s+[A-Za-z0-9\s,()]+/',
            
            // Booking number patterns
            'booking' => '/Booking\s+(\d+)/i',
            
            // Concerning field patterns
            'concerning' => '/Concerning\s+([A-Za-z0-9\s,]+?)(?=\s+Shipper|\s+Consignee)/i',
        ];

        // Compile patterns and validate them
        foreach ($this->patterns as $name => $pattern) {
            if (@preg_match($pattern, '') === false) {
                Log::error('Invalid regex pattern', [
                    'pattern_name' => $name,
                    'pattern' => $pattern,
                    'error' => 'Invalid regex syntax'
                ]);
                unset($this->patterns[$name]);
            }
        }

        $this->initialized = true;
        
        Log::info('Compiled pattern engine initialized', [
            'total_patterns' => count($this->patterns),
            'pattern_names' => array_keys($this->patterns)
        ]);
    }

    /**
     * Match a pattern against text
     */
    public function match(string $patternName, string $text): ?array
    {
        if (!isset($this->patterns[$patternName])) {
            Log::warning('Pattern not found', ['pattern_name' => $patternName]);
            return null;
        }

        $matches = [];
        $pattern = $this->patterns[$patternName];
        
        if (preg_match($pattern, $text, $matches)) {
            return $matches;
        }
        
        return null;
    }

    /**
     * Match all occurrences of a pattern
     */
    public function matchAll(string $patternName, string $text): array
    {
        if (!isset($this->patterns[$patternName])) {
            Log::warning('Pattern not found', ['pattern_name' => $patternName]);
            return [];
        }

        $matches = [];
        $pattern = $this->patterns[$patternName];
        
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            return $matches;
        }
        
        return [];
    }

    /**
     * Check if a pattern exists
     */
    public function hasPattern(string $patternName): bool
    {
        return isset($this->patterns[$patternName]);
    }

    /**
     * Get all available pattern names
     */
    public function getPatternNames(): array
    {
        return array_keys($this->patterns);
    }

    /**
     * Get pattern count
     */
    public function getPatternCount(): int
    {
        return count($this->patterns);
    }

    /**
     * Get pattern statistics
     */
    public function getStatistics(): array
    {
        return [
            'total_patterns' => count($this->patterns),
            'pattern_names' => array_keys($this->patterns),
            'initialized' => $this->initialized,
            'memory_usage' => memory_get_usage(true)
        ];
    }

    /**
     * Test pattern matching performance
     */
    public function testPerformance(string $testText): array
    {
        $results = [];
        $startTime = microtime(true);
        
        foreach ($this->patterns as $name => $pattern) {
            $patternStart = microtime(true);
            $matches = [];
            preg_match($pattern, $testText, $matches);
            $patternTime = microtime(true) - $patternStart;
            
            $results[$name] = [
                'matched' => !empty($matches),
                'match_count' => count($matches),
                'execution_time_ms' => round($patternTime * 1000, 3),
                'pattern' => $pattern
            ];
        }
        
        $totalTime = microtime(true) - $startTime;
        
        return [
            'total_execution_time_ms' => round($totalTime * 1000, 3),
            'pattern_results' => $results,
            'average_time_per_pattern_ms' => round(($totalTime * 1000) / count($this->patterns), 3)
        ];
    }

    /**
     * Add a new pattern dynamically
     */
    public function addPattern(string $name, string $pattern): bool
    {
        // Validate pattern
        if (@preg_match($pattern, '') === false) {
            Log::error('Invalid regex pattern', [
                'pattern_name' => $name,
                'pattern' => $pattern,
                'error' => 'Invalid regex syntax'
            ]);
            return false;
        }
        
        $this->patterns[$name] = $pattern;
        
        Log::info('Pattern added', [
            'pattern_name' => $name,
            'total_patterns' => count($this->patterns)
        ]);
        
        return true;
    }

    /**
     * Remove a pattern
     */
    public function removePattern(string $name): bool
    {
        if (isset($this->patterns[$name])) {
            unset($this->patterns[$name]);
            
            Log::info('Pattern removed', [
                'pattern_name' => $name,
                'total_patterns' => count($this->patterns)
            ]);
            
            return true;
        }
        
        return false;
    }

    /**
     * Clear all patterns
     */
    public function clearPatterns(): void
    {
        $this->patterns = [];
        $this->initialized = false;
        
        Log::info('All patterns cleared');
    }

    /**
     * Reinitialize patterns
     */
    public function reinitialize(): void
    {
        $this->clearPatterns();
        $this->initializePatterns();
        
        Log::info('Pattern engine reinitialized');
    }
}
