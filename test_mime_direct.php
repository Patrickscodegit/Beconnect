<?php

use ZBateson\MailMimeParser\MailMimeParser;

echo "🧪 Testing Modern MIME Parser Directly\n";
echo "=====================================\n\n";

$sourceEmlPath = '/Users/patrickhome/Downloads/68b320c74d202_01K3XVG2MBHB27G6HHDHBT47RX.eml';

if (!file_exists($sourceEmlPath)) {
    echo "❌ Source EML file not found: {$sourceEmlPath}\n";
    exit(1);
}

echo "📧 Reading BMW email content...\n";
$content = file_get_contents($sourceEmlPath);

if (empty($content)) {
    echo "❌ Could not read email content\n";
    exit(1);
}

try {
    echo "🔍 Testing modern MIME parser...\n";
    
    $parser = new MailMimeParser();
    
    // Create proper stream for MIME parser
    $message = $parser->parse($content, false);

    // Extract sender information with fallbacks
    $fromHeader = $message->getHeaderValue('from');
    $replyToHeader = $message->getHeaderValue('reply-to');
    
    $fromEmail = null;
    $fromName = null;
    
    // Parse FROM header
    if ($fromHeader) {
        if (preg_match('/^(.+?)\s*<([^>]+)>/', $fromHeader, $matches)) {
            $fromName = trim($matches[1], ' "\'');
            $fromEmail = trim($matches[2]);
        } elseif (preg_match('/^([^\s]+@[^\s]+)/', $fromHeader, $matches)) {
            $fromEmail = trim($matches[1]);
        }
    }
    
    // Extract recipient
    $toHeader = $message->getHeaderValue('to');
    $toEmail = null;
    
    if ($toHeader) {
        if (preg_match('/<([^>]+)>/', $toHeader, $matches)) {
            $toEmail = trim($matches[1]);
        } elseif (preg_match('/^([^\s]+@[^\s]+)/', $toHeader, $matches)) {
            $toEmail = trim($matches[1]);
        }
    }

    // Extract other headers
    $subject = $message->getHeaderValue('subject') ?? '';
    $date = $message->getHeaderValue('date');

    // Extract body content
    $textContent = $message->getTextContent();
    $htmlContent = $message->getHtmlContent();
    
    $body = '';
    if (!empty($textContent)) {
        $body = $textContent;
    } elseif (!empty($htmlContent)) {
        $body = strip_tags(html_entity_decode($htmlContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    echo "✅ Modern MIME parsing successful!\n\n";
    
    echo "📋 PARSED EMAIL DATA:\n";
    echo "====================\n";
    echo "👤 From:\n";
    echo "   Name: " . ($fromName ?: 'NOT FOUND') . "\n";
    echo "   Email: " . ($fromEmail ?: 'NOT FOUND') . "\n";
    echo "👤 To:\n";
    echo "   Email: " . ($toEmail ?: 'NOT FOUND') . "\n";
    echo "📧 Headers:\n";
    echo "   Subject: " . ($subject ?: 'NOT FOUND') . "\n";
    echo "   Date: " . ($date ?: 'NOT FOUND') . "\n";
    echo "📝 Body:\n";
    echo "   Length: " . strlen($body) . " characters\n";
    echo "   Preview: " . substr($body, 0, 100) . "...\n\n";
    
    // Critical test
    $expectedEmail = 'badr.algothami@gmail.com';
    $expectedName = 'Badr Algothami';
    
    echo "🎯 VALIDATION RESULTS:\n";
    echo "=====================\n";
    
    if ($fromEmail === $expectedEmail) {
        echo "   ✅ Email extraction: PERFECT\n";
    } else {
        echo "   ❌ Email extraction: FAILED\n";
        echo "       Expected: {$expectedEmail}\n";
        echo "       Got: " . ($fromEmail ?: 'NULL') . "\n";
    }
    
    if ($fromName === $expectedName) {
        echo "   ✅ Name extraction: PERFECT\n";
    } else {
        echo "   ⚠️  Name extraction: " . ($fromName ?: 'NULL') . "\n";
        echo "       Expected: {$expectedName}\n";
    }
    
    // Subject decoding test
    if (str_contains(strtolower($subject), 'bmw') && str_contains(strtolower($subject), 'série')) {
        echo "   ✅ Subject decoding: PERFECT (special chars handled)\n";
    } else {
        echo "   ⚠️  Subject: " . $subject . "\n";
    }
    
    echo "\n🏆 Modern MIME Parser Assessment:\n";
    echo "===============================\n";
    
    if ($fromEmail === $expectedEmail && $fromName === $expectedName) {
        echo "   ✅ EXCELLENT: Both email and name extracted perfectly\n";
        echo "   📊 Ready for production - bulletproof contact extraction\n";
    } elseif ($fromEmail === $expectedEmail) {
        echo "   ✅ GOOD: Email extracted correctly (name optional)\n";
        echo "   📊 Will solve 'needs_contact' issue\n";
    } else {
        echo "   ❌ NEEDS WORK: Email extraction failed\n";
    }

} catch (\Exception $e) {
    echo "❌ Modern MIME Parser Exception: " . $e->getMessage() . "\n";
    echo "📍 Class: " . get_class($e) . "\n";
}

echo "\n✨ Modern MIME parser test completed!\n";
