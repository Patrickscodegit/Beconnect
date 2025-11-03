<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Normalize POL/POD format from "City, Country (CODE)" to "City (CODE), Country"
        Log::info('Starting POL/POD format normalization');
        
        $articles = DB::table('robaws_articles_cache')
            ->whereNotNull('pol')
            ->orWhereNotNull('pod')
            ->get(['id', 'pol', 'pod']);
        
        $updated = 0;
        
        foreach ($articles as $article) {
            $updates = [];
            
            // Normalize POL
            if ($article->pol) {
                $normalizedPol = $this->normalizePortFormat($article->pol);
                if ($normalizedPol !== $article->pol) {
                    $updates['pol'] = $normalizedPol;
                }
            }
            
            // Normalize POD
            if ($article->pod) {
                $normalizedPod = $this->normalizePortFormat($article->pod);
                if ($normalizedPod !== $article->pod) {
                    $updates['pod'] = $normalizedPod;
                }
            }
            
            if (!empty($updates)) {
                DB::table('robaws_articles_cache')
                    ->where('id', $article->id)
                    ->update($updates);
                $updated++;
            }
        }
        
        Log::info('POL/POD format normalization complete', [
            'total_articles' => $articles->count(),
            'updated' => $updated
        ]);
    }

    /**
     * Normalize port format from "City, Country (CODE)" to "City (CODE), Country"
     * 
     * @param string $portString
     * @return string
     */
    protected function normalizePortFormat(string $portString): string
    {
        $portString = trim($portString);
        
        // Already in correct format: "City (CODE), Country"
        // Example: "Antwerp (ANR), Belgium"
        if (preg_match('/^([^(]+?)\s*\(([^)]+)\)\s*,\s*(.+)$/', $portString, $matches)) {
            // Check if it's already correct format (CODE before comma)
            $beforeParen = trim($matches[1]);
            $code = trim($matches[2]);
            $afterComma = trim($matches[3]);
            
            // If beforeParen is just city name (no comma), it's correct format
            if (strpos($beforeParen, ',') === false) {
                return $portString; // Already correct
            }
        }
        
        // Format to normalize: "City, Country (CODE)"
        // Example: "Antwerp, Belgium (ANR)"
        if (preg_match('/^(.+?),\s*(.+?)\s*\(([^)]+)\)$/', $portString, $matches)) {
            $city = trim($matches[1]);
            $country = trim($matches[2]);
            $code = trim($matches[3]);
            
            // Convert to: "City (CODE), Country"
            return "{$city} ({$code}), {$country}";
        }
        
        // Format with just city name: "City (CODE)" or "City, Country"
        // Try to extract code if present
        if (preg_match('/^(.+?)\s*\(([^)]+)\)$/', $portString, $matches)) {
            $city = trim($matches[1]);
            $code = trim($matches[2]);
            
            // If no country, try to lookup from Port model
            try {
                $port = \App\Models\Port::where('code', $code)->first();
                if ($port && $port->country) {
                    return "{$city} ({$code}), {$port->country}";
                }
            } catch (\Exception $e) {
                // If lookup fails, return as-is
            }
            
            return $portString; // Return as-is if can't normalize
        }
        
        // No pattern matched, return as-is
        return $portString;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: This migration converts data, not schema
        // Reversing would require converting back, but we don't want to do that
        // Just log that reversal is not supported
        Log::warning('POL/POD format normalization migration reversal not supported');
    }
};