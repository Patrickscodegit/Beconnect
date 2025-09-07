<?php

/**
 * HTTP Client Consistency Verification Script
 * 
 * This script ensures all RobawsApiClient methods use consistent
 * HTTP client patterns with proper lazy initialization.
 */

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

use Illuminate\Support\Facades\Log;

echo "🔍 Verifying HTTP Client Consistency in RobawsApiClient...\n\n";

// Read the RobawsApiClient file
$filePath = __DIR__ . '/app/Services/Export/Clients/RobawsApiClient.php';

if (!file_exists($filePath)) {
    echo "❌ RobawsApiClient.php not found!\n";
    exit(1);
}

$content = file_get_contents($filePath);

// Check patterns
$patterns = [
    'getHttpClient_method' => '/private function getHttpClient\(\):\s*Client/',
    'lazy_initialization' => '/if\s*\(\s*\$this->http\s*===\s*null\s*\)/',
    'direct_http_usage' => '/\$this->http->[^g]/', // Looking for $this->http-> that's not getHttpClient()
    'proper_usage' => '/\$this->getHttpClient\(\)/',
    'consistent_pattern' => '/\$this->getHttpClient\(\)->(?:get|post|put|delete|request)/'
];

$results = [];

foreach ($patterns as $name => $pattern) {
    preg_match_all($pattern, $content, $matches);
    $results[$name] = count($matches[0]);
}

echo "📊 Analysis Results:\n";
echo "─────────────────────\n";
echo "✅ getHttpClient() method found: " . ($results['getHttpClient_method'] ? 'YES' : 'NO') . "\n";
echo "✅ Lazy initialization pattern: {$results['lazy_initialization']} occurrences\n";
echo "⚠️  Direct \$this->http usage: {$results['direct_http_usage']} occurrences\n";
echo "✅ Proper \$this->getHttpClient() usage: {$results['proper_usage']} occurrences\n";
echo "✅ HTTP method calls via getHttpClient(): {$results['consistent_pattern']} occurrences\n\n";

// Check for problematic patterns
$problemPatterns = [
    'return_this_http' => '/return\s+\$this->http;/',
    'assignment_to_http' => '/\$this->http\s*=/',
];

$hasProblems = false;

foreach ($problemPatterns as $name => $pattern) {
    preg_match_all($pattern, $content, $matches);
    if (count($matches[0]) > 0) {
        if ($name === 'return_this_http' && count($matches[0]) === 1) {
            // This is expected in getHttpClient() method
            continue;
        }
        if ($name === 'assignment_to_http' && count($matches[0]) === 1) {
            // This is expected in getHttpClient() method
            continue;
        }
        echo "⚠️  Found problematic pattern '{$name}': " . count($matches[0]) . " occurrences\n";
        $hasProblems = true;
    }
}

// Verify methods use getHttpClient() consistently
$methodsToCheck = [
    'findClientByEmail',
    'findContactByEmail', 
    'createOffer',
    'updateOffer',
    'getFileContent',
    'uploadDocument'
];

echo "🔍 Checking individual methods:\n";
echo "─────────────────────────────\n";

foreach ($methodsToCheck as $method) {
    // Extract method content
    preg_match('/function\s+' . preg_quote($method) . '\s*\([^}]*\{[^}]*\}/s', $content, $methodMatch);
    
    if (empty($methodMatch)) {
        echo "⚠️  Method {$method}: NOT FOUND\n";
        continue;
    }
    
    $methodContent = $methodMatch[0];
    
    // Check for proper usage
    $hasGetHttpClient = preg_match('/\$this->getHttpClient\(\)/', $methodContent);
    $hasDirectHttp = preg_match('/\$this->http->/', $methodContent);
    
    if ($hasGetHttpClient && !$hasDirectHttp) {
        echo "✅ Method {$method}: CONSISTENT\n";
    } elseif ($hasDirectHttp) {
        echo "⚠️  Method {$method}: Uses direct \$this->http\n";
        $hasProblems = true;
    } else {
        echo "ℹ️  Method {$method}: No HTTP calls (may be helper method)\n";
    }
}

echo "\n🎯 Type Safety Verification:\n";
echo "──────────────────────────\n";

// Check for type safety patterns in RobawsExportService
$exportServicePath = __DIR__ . '/app/Services/Robaws/RobawsExportService.php';
if (file_exists($exportServicePath)) {
    $exportContent = file_get_contents($exportServicePath);
    
    $typeSafetyPatterns = [
        'validateClientId_method' => '/private function validateClientId\(/',
        'validateAndSanitizeEmail_method' => '/private function validateAndSanitizeEmail\(/',
        'buildTypeSeafePayload_method' => '/private function buildTypeSeafePayload\(/',
        'integer_casting' => '/\(int\)\s*\$/',
        'email_validation' => '/filter_var\([^,]+,\s*FILTER_VALIDATE_EMAIL\)/',
    ];
    
    foreach ($typeSafetyPatterns as $name => $pattern) {
        preg_match_all($pattern, $exportContent, $matches);
        $count = count($matches[0]);
        
        if ($count > 0) {
            echo "✅ {$name}: {$count} occurrences\n";
        } else {
            echo "❌ {$name}: NOT FOUND\n";
            $hasProblems = true;
        }
    }
} else {
    echo "⚠️  RobawsExportService.php not found!\n";
    $hasProblems = true;
}

echo "\n" . str_repeat("=", 50) . "\n";

if (!$hasProblems) {
    echo "🎉 SUCCESS: All consistency checks passed!\n";
    echo "✅ HTTP client patterns are consistent\n";
    echo "✅ Type safety measures are in place\n";
    echo "✅ No problematic patterns detected\n\n";
    echo "🚀 Ready for production deployment!\n";
    exit(0);
} else {
    echo "⚠️  ISSUES FOUND: Please review the warnings above\n";
    echo "🔧 Some patterns may need adjustment for consistency\n";
    exit(1);
}
