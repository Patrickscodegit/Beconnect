<?php

require __DIR__.'/vendor/autoload.php';

use Illuminate\Support\Facades\Http;

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Testing Sallaum Lines Website Scraping ===\n\n";

// Test different URL patterns
$testUrls = [
    'Route Finder Main' => 'https://sallaumlines.com/route-finder/',
    'Schedules Page' => 'https://sallaumlines.com/schedules/',
    'Route Finder with params' => 'https://sallaumlines.com/route-finder/?origin=antwerp&destination=lagos',
];

foreach ($testUrls as $name => $url) {
    echo "Testing: {$name}\n";
    echo "URL: {$url}\n";
    
    try {
        $response = Http::timeout(30)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])
            ->get($url);
        
        if ($response->successful()) {
            $html = $response->body();
            $size = strlen($html);
            
            echo "✓ Response received ({$size} bytes)\n";
            
            // Analyze content
            echo "\nContent Analysis:\n";
            echo "- Contains 'schedule': " . (stripos($html, 'schedule') !== false ? 'YES' : 'NO') . "\n";
            echo "- Contains 'route': " . (stripos($html, 'route') !== false ? 'YES' : 'NO') . "\n";
            echo "- Contains 'sailing': " . (stripos($html, 'sailing') !== false ? 'YES' : 'NO') . "\n";
            echo "- Contains 'frequency': " . (stripos($html, 'frequency') !== false ? 'YES' : 'NO') . "\n";
            echo "- Contains 'departure': " . (stripos($html, 'departure') !== false ? 'YES' : 'NO') . "\n";
            echo "- Contains 'vessel': " . (stripos($html, 'vessel') !== false ? 'YES' : 'NO') . "\n";
            echo "- Contains JSON data: " . (stripos($html, 'application/json') !== false ? 'YES' : 'NO') . "\n";
            
            // Look for forms
            if (preg_match_all('/<form[^>]*>/i', $html, $forms)) {
                echo "- Forms found: " . count($forms[0]) . "\n";
            }
            
            // Look for select dropdowns
            if (preg_match_all('/<select[^>]*name=["\']([^"\']+)["\'][^>]*>/i', $html, $selects)) {
                echo "- Select dropdowns: " . implode(', ', array_unique($selects[1])) . "\n";
            }
            
            // Look for JavaScript data
            if (preg_match('/var\s+routes\s*=\s*(\{[^}]+\}|\[[^\]]+\])/i', $html, $jsData)) {
                echo "- JavaScript route data found\n";
                echo "  " . substr($jsData[1], 0, 100) . "...\n";
            }
            
            // Look for API endpoints
            if (preg_match_all('/https?:\/\/[^\s"\'<>]+api[^\s"\'<>]*/i', $html, $apis)) {
                echo "- API endpoints found: " . implode(', ', array_unique($apis[0])) . "\n";
            }
            
            // Save sample HTML
            $sampleFile = __DIR__ . "/sallaum_sample_{$name}.html";
            $sampleFile = str_replace(' ', '_', strtolower($sampleFile));
            file_put_contents($sampleFile, substr($html, 0, 50000)); // First 50KB
            echo "- Sample saved to: {$sampleFile}\n";
            
        } else {
            echo "✗ Failed: HTTP {$response->status()}\n";
        }
        
    } catch (\Exception $e) {
        echo "✗ Error: " . $e->getMessage() . "\n";
    }
    
    echo "\n" . str_repeat('-', 80) . "\n\n";
}

echo "=== Testing Complete ===\n";

