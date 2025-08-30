#!/usr/bin/env php
<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ai = app(\App\Services\AiRouter::class);

echo "🧪 Testing both AI services with new keys...\n\n";

// Test OpenAI
echo "Testing OpenAI...\n";
try {
    $result = $ai->extract('Consignee: OpenAI Test Corp', [], ['service' => 'openai']);
    echo "✅ OpenAI Success: " . json_encode($result) . "\n\n";
} catch (Exception $e) {
    echo "❌ OpenAI Failed: " . $e->getMessage() . "\n\n";
}

// Test Anthropic
echo "Testing Anthropic...\n";
try {
    $result = $ai->extract('Consignee: Anthropic Test Corp', [], ['service' => 'anthropic']);
    echo "✅ Anthropic Success: " . json_encode($result) . "\n\n";
} catch (Exception $e) {
    echo "❌ Anthropic Failed: " . $e->getMessage() . "\n\n";
}

echo "🎉 API key test complete!\n";
