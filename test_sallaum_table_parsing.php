<?php

require __DIR__.'/vendor/autoload.php';

use Illuminate\Support\Facades\Http;

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Testing Sallaum Table Parsing ===\n\n";

$response = Http::timeout(30)
    ->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ])
    ->get('https://sallaumlines.com/schedules/europe-to-west-and-south-africa/');

if ($response->successful()) {
    $html = $response->body();
    
    // Extract table
    if (preg_match('/<table[^>]*>(.*?)<\/table>/is', $html, $tableMatch)) {
        echo "✓ Table found\n\n";
        
        // Extract ALL table rows
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $tableMatch[1], $rowMatches);
        
        echo "Total rows: " . count($rowMatches[1]) . "\n\n";
        
        // Show first 10 rows
        foreach (array_slice($rowMatches[1], 0, 10) as $i => $rowHtml) {
            echo "Row {$i}:\n";
            echo substr(strip_tags($rowHtml), 0, 200) . "\n";
            
            // Extract cells
            preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $rowHtml, $cellMatches);
            echo "Cells: " . count($cellMatches[1]) . "\n";
            foreach ($cellMatches[1] as $j => $cell) {
                $cellText = trim(strip_tags($cell));
                if (!empty($cellText)) {
                    echo "  Cell {$j}: " . substr($cellText, 0, 50) . "\n";
                }
            }
            echo "\n";
        }
        
    } else {
        echo "✗ No table found\n";
    }
} else {
    echo "✗ Failed to fetch page\n";
}

