<?php
require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\AiRouter;

echo "🧪 TESTING EMAIL PARSING DIRECTLY\n";
echo "=================================\n\n";

// Test email parsing with the BMW email
$emailFile = '/tmp/test_bmw_email.eml';

if (!file_exists($emailFile)) {
    echo "❌ BMW email file not found at: $emailFile\n";
    exit;
}

$content = file_get_contents($emailFile);
echo "📄 Email file size: " . strlen($content) . " bytes\n\n";

// Parse email headers manually to test our logic
$headers = [];
$body = '';
$inHeaders = true;
$lines = explode("\n", $content);

foreach ($lines as $line) {
    if ($inHeaders && trim($line) === '') {
        $inHeaders = false;
        continue;
    }
    
    if ($inHeaders) {
        if (preg_match('/^([^:]+):\s*(.+)$/i', $line, $matches)) {
            $headerName = strtolower(trim($matches[1]));
            $headerValue = trim($matches[2]);
            $headers[$headerName] = $headerValue;
        }
    } else {
        $body .= $line . "\n";
    }
}

echo "📧 Parsed Email Headers:\n";
echo "   From: " . ($headers['from'] ?? 'Not found') . "\n";
echo "   To: " . ($headers['to'] ?? 'Not found') . "\n";
echo "   Subject: " . ($headers['subject'] ?? 'Not found') . "\n";
echo "   Date: " . ($headers['date'] ?? 'Not found') . "\n";
echo "   Return-Path: " . ($headers['return-path'] ?? 'Not found') . "\n\n";

// Test From header parsing
$fromHeader = $headers['from'] ?? '';
$fromEmail = null;
$fromName = null;

if (preg_match('/^(.+?)\s*<([^>]+)>/', $fromHeader, $matches)) {
    $fromName = trim($matches[1], ' "\'');
    $fromEmail = trim($matches[2]);
} elseif (preg_match('/^([^\s]+@[^\s]+)/', $fromHeader, $matches)) {
    $fromEmail = trim($matches[1]);
}

echo "✅ Parsed Sender Information:\n";
echo "   Name: " . ($fromName ?: 'Not found') . "\n";
echo "   Email: " . ($fromEmail ?: 'Not found') . "\n\n";

// Extract plain text body
$plainBody = '';
if (preg_match('/Content-Type:\s*text\/plain[^-]*?\n\n(.*?)(?=--)/s', $body, $matches)) {
    $plainBody = $matches[1];
} else {
    // Try HTML and strip tags
    if (preg_match('/Content-Type:\s*text\/html[^-]*?\n\n(.*?)(?=--)/s', $body, $matches)) {
        $plainBody = strip_tags(html_entity_decode($matches[1]));
    } else {
        $plainBody = $body;
    }
}

// Decode quoted-printable if needed
if (strpos($body, 'Content-Transfer-Encoding: quoted-printable') !== false) {
    $plainBody = quoted_printable_decode($plainBody);
}

$plainBody = trim($plainBody);
$plainBody = preg_replace('/\r\n|\r/', "\n", $plainBody);

echo "📝 Email Body (first 500 chars):\n";
echo substr($plainBody, 0, 500) . "...\n\n";

// Test AI extraction
if ($fromEmail && $fromName) {
    echo "🤖 Testing AI extraction...\n";
    
    $emailData = [
        'from_email' => $fromEmail,
        'from_name' => $fromName,
        'to_email' => $headers['to'] ?? '',
        'subject' => $headers['subject'] ?? '',
        'body' => $plainBody,
        'date' => $headers['date'] ?? null
    ];
    
    try {
        $aiRouter = app(AiRouter::class);
        $result = $aiRouter->extractFromEmail($emailData);
        
        echo "✅ AI extraction completed:\n";
        echo "   Contact Name: " . ($result['contact']['name'] ?? 'Not found') . "\n";
        echo "   Contact Email: " . ($result['contact']['email'] ?? 'Not found') . "\n";
        echo "   Contact Company: " . ($result['contact']['company'] ?? 'Not found') . "\n";
        
        if (isset($result['vehicle'])) {
            echo "   Vehicle Make: " . ($result['vehicle']['make'] ?? 'Not found') . "\n";
            echo "   Vehicle Model: " . ($result['vehicle']['model'] ?? 'Not found') . "\n";
        }
        
        if (isset($result['shipment'])) {
            echo "   Origin: " . ($result['shipment']['origin'] ?? 'Not found') . "\n";
            echo "   Destination: " . ($result['shipment']['destination'] ?? 'Not found') . "\n";
        }
        
        // Verify sender is correctly captured
        if ($result['contact']['email'] === 'badr.algothami@gmail.com') {
            echo "\n🎉 SUCCESS: Sender correctly identified!\n";
        } else {
            echo "\n⚠️  Issue: Expected badr.algothami@gmail.com, got: " . ($result['contact']['email'] ?? 'none') . "\n";
        }
        
    } catch (Exception $e) {
        echo "❌ AI extraction failed: " . $e->getMessage() . "\n";
    }
} else {
    echo "❌ Could not extract sender information\n";
}

echo "\n✅ Test completed!\n";
