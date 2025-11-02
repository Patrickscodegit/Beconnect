<?php

namespace App\Services\Robaws;

use App\Models\Port;
use Illuminate\Support\Facades\Log;

/**
 * Service for parsing Robaws article names to extract metadata
 * 
 * This is the single source of truth for all article name parsing logic.
 * Consolidates regex patterns that were previously duplicated across multiple services.
 */
class ArticleNameParser
{
    /**
     * Extract Port of Loading (POL) information from article name
     * 
     * Tries multiple patterns in order of specificity (most specific first).
     * Returns array with port details if found, null otherwise.
     * 
     * @param string $articleName The full article name from Robaws
     * @return array|null ['code' => 'ANR', 'name' => 'Antwerp', 'country' => 'Belgium', 'formatted' => 'Antwerp (ANR), Belgium', 'terminal' => 'T1234']
     */
    public function extractPOL(string $articleName): ?array
    {
        $polCode = null;
        
        // Pattern 1: Standard (CODE) format - e.g., (ANR), (RTM)
        if (preg_match('/\(([A-Z]{3})\)/', $articleName, $matches)) {
            $polCode = $matches[1];
        }
        // Pattern 2: (CODE numbers) format - e.g., (ANR 1333), (ZEE 456)
        elseif (preg_match('/\(([A-Z]{3})\s+\d+\)/', $articleName, $matches)) {
            $polCode = $matches[1];
        }
        // Pattern 3: (CODE numbers/numbers) format - e.g., (ANR 332/740), (RTM 123/456)
        elseif (preg_match('/\(([A-Z]{3})\s+[\d\/]+\)/', $articleName, $matches)) {
            $polCode = $matches[1];
        }
        
        if (!$polCode) {
            return null;
        }
        
        // Lookup port details in database
        $port = Port::where('code', $polCode)->first();
        
        if (!$port) {
            return [
                'code' => $polCode,
                'name' => null,
                'country' => null,
                'formatted' => $polCode,
                'terminal' => null
            ];
        }
        
        return [
            'code' => $port->code,
            'name' => $port->name,
            'country' => $port->country,
            'formatted' => $port->name . ' (' . $port->code . '), ' . $port->country,
            'terminal' => $port->terminal_code ?? null
        ];
    }
    
    /**
     * Extract Port of Discharge (POD) information from article name
     * 
     * Tries multiple patterns in order of specificity (most specific first).
     * Returns array with port details if found, null otherwise.
     * 
     * @param string $articleName The full article name from Robaws
     * @return array|null ['name' => 'Conakry', 'country' => 'Guinea', 'code' => 'CKY', 'formatted' => 'Conakry, Guinea (CKY)']
     */
    public function extractPOD(string $articleName): ?array
    {
        $podName = null;
        
        // Pattern 1: FCL - City (CODE) format - e.g., "FCL - Dubai (DXB)", "RORO - Hamburg (HAM)"
        if (preg_match('/(?:FCL|RORO)\s*-\s*([A-Za-z\s]+?)\s*\([A-Z]{3}\)/', $articleName, $matches)) {
            $podName = trim($matches[1]);
        }
        // Pattern 2: City(CODE) - Country format - e.g., "Dubai(DXB) - UAE"
        elseif (preg_match('/([A-Za-z\s]+?)\s*\([A-Z]{3}\)\s*-\s*[A-Za-z\s,]+/', $articleName, $matches)) {
            $podName = trim($matches[1]);
        }
        // Pattern 3: Any city before (CODE) format - e.g., "Hamburg(HAM)"
        elseif (preg_match('/([A-Za-z\s]+?)\s*\([A-Z]{3}\)/', $articleName, $matches)) {
            $podName = trim($matches[1]);
        }
        // Pattern 4: (POL CODE numbers) City Country format - e.g., "ACL(ANR 1333) Halifax Canada"
        elseif (preg_match('/\([A-Z]{3}\s+\d+\)\s+([A-Za-z\s]+?)\s+[A-Z][a-z]+/', $articleName, $matches)) {
            $podName = trim($matches[1]);
        }
        // Pattern 5: (POL CODE) City Country format - e.g., "Grimaldi(ANR) Alexandria Egypt"
        elseif (preg_match('/\([A-Z]{3}\)\s+([A-Za-z\s]+?)\s+[A-Z][a-z]+/', $articleName, $matches)) {
            $podName = trim($matches[1]);
        }
        // Pattern 6: (POL CODE numbers/numbers) City - Country format - e.g., "Sallaum(ANR 332/740) Abidjan - Ivory Coast"
        elseif (preg_match('/\([A-Z]{3}\s+[\d\/]+\)\s+([A-Za-z\s]+?)\s+-/', $articleName, $matches)) {
            $podName = trim($matches[1]);
        }
        // Pattern 7: (POL CODE numbers/numbers) City Country format - e.g., "Sallaum(ANR 332/740) Conakry Guinea"
        elseif (preg_match('/\([A-Z]{3}\s+[\d\/]+\)\s+([A-Za-z\s]+?)\s+[A-Z][a-z]+/', $articleName, $matches)) {
            $podName = trim($matches[1]);
        }
        
        if (!$podName) {
            return null;
        }
        
        // Lookup port details in database
        $port = Port::where('name', 'LIKE', '%' . $podName . '%')->first();
        
        if (!$port) {
            // Return extracted name even if port not found in database
            return [
                'name' => $podName,
                'country' => null,
                'code' => null,
                'formatted' => $podName
            ];
        }
        
        return [
            'name' => $port->name,
            'country' => $port->country,
            'code' => $port->code,
            'formatted' => $port->name . ' (' . $port->code . '), ' . $port->country
        ];
    }
    
    /**
     * Extract shipping line from article name
     * 
     * Known shipping lines: Grimaldi, ACL, Sallaum, LM
     * 
     * @param string $articleName The full article name from Robaws
     * @return string|null The shipping line name, or null if not found
     */
    public function extractShippingLine(string $articleName): ?string
    {
        $shippingLines = [
            'Grimaldi' => '/\bGrimaldi\b/i',
            'ACL' => '/\bACL\b/i',
            'Sallaum' => '/\bSallaum\b/i',
            'LM' => '/\bLM\b/i',
        ];
        
        foreach ($shippingLines as $line => $pattern) {
            if (preg_match($pattern, $articleName)) {
                return $line;
            }
        }
        
        return null;
    }
    
    /**
     * Extract service type from article name
     * 
     * Looks for keywords like: Seafreight, Export, Import, RORO, FCL, Static Cargo
     * 
     * @param string $articleName The full article name from Robaws
     * @return string|null The service type, or null if not found
     */
    public function extractServiceType(string $articleName): ?string
    {
        // Try to find explicit EXPORT or IMPORT first
        if (preg_match('/\bEXPORT\b/i', $articleName)) {
            return 'EXPORT';
        }
        
        if (preg_match('/\bIMPORT\b/i', $articleName)) {
            return 'IMPORT';
        }
        
        // Check for other service types
        if (preg_match('/\bRORO\b/i', $articleName)) {
            return 'RORO';
        }
        
        if (preg_match('/\bFCL\b/i', $articleName)) {
            return 'FCL';
        }
        
        if (preg_match('/\bSTATIC\s+CARGO\b/i', $articleName)) {
            return 'STATIC CARGO';
        }
        
        if (preg_match('/\bSeafreight\b/i', $articleName)) {
            return 'SEAFREIGHT';
        }
        
        return null;
    }
    
    /**
     * Extract all metadata from article name in one call
     * 
     * Convenience method that calls all extraction methods and returns combined results.
     * 
     * @param string $articleName The full article name from Robaws
     * @return array ['pol' => [...], 'pod' => [...], 'shipping_line' => '...', 'service_type' => '...']
     */
    public function extractAll(string $articleName): array
    {
        return [
            'pol' => $this->extractPOL($articleName),
            'pod' => $this->extractPOD($articleName),
            'shipping_line' => $this->extractShippingLine($articleName),
            'service_type' => $this->extractServiceType($articleName),
        ];
    }
    
    /**
     * Log articles that don't match any patterns (for monitoring coverage)
     * 
     * @param string $articleName The article name
     * @param int|null $articleId The article ID (optional)
     * @return void
     */
    public function logUnparseable(string $articleName, ?int $articleId = null): void
    {
        $pol = $this->extractPOL($articleName);
        $pod = $this->extractPOD($articleName);
        
        if (!$pol && !$pod) {
            Log::debug('Article name not matching any POL/POD patterns', [
                'article_name' => $articleName,
                'article_id' => $articleId,
            ]);
        }
    }
}

