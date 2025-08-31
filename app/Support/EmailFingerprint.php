<?php

namespace App\Support;

class EmailFingerprint
{
    /**
     * Generate fingerprint from raw email data
     */
    public static function fromRaw(string $raw, array $headers, string $plainBody): array
    {
        $messageId = self::normalizeMessageId($headers['message-id'] ?? null);

        $normalizedHeaders = strtolower(trim(($headers['from'] ?? '').'|'.($headers['to'] ?? '').'|'.($headers['subject'] ?? '')));
        $normalizedBody = preg_replace('/\s+/u', ' ', trim($plainBody));
        $contentHash = hash('sha256', $normalizedHeaders.'|'.$normalizedBody);

        return [
            'message_id'  => $messageId,      // may be null
            'content_sha' => $contentHash,    // always present
        ];
    }

    /**
     * Normalize Message-ID header
     */
    public static function normalizeMessageId(?string $v): ?string
    {
        if (!$v) return null;
        $v = trim($v);
        // Strip <...>
        if (preg_match('/<([^>]+)>/', $v, $m)) $v = $m[1];
        return strtolower($v);
    }

    /**
     * Parse headers from raw email content
     */
    public static function parseHeaders(string $rawEmail): array
    {
        $headers = [];
        
        // Split headers from body
        $parts = preg_split('/\r?\n\r?\n/', $rawEmail, 2);
        if (count($parts) < 2) return $headers;
        
        $headerSection = $parts[0];
        
        // Parse headers
        $lines = preg_split('/\r?\n/', $headerSection);
        $currentHeader = '';
        
        foreach ($lines as $line) {
            if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $matches)) {
                // New header
                if ($currentHeader) {
                    $headers[strtolower($currentHeader)] = trim($headers[strtolower($currentHeader)] ?? '');
                }
                $currentHeader = $matches[1];
                $headers[strtolower($currentHeader)] = $matches[2];
            } elseif ($currentHeader && preg_match('/^\s+(.*)$/', $line, $matches)) {
                // Continuation of previous header
                $headers[strtolower($currentHeader)] .= ' ' . $matches[1];
            }
        }
        
        // Don't forget the last header
        if ($currentHeader) {
            $headers[strtolower($currentHeader)] = trim($headers[strtolower($currentHeader)] ?? '');
        }
        
        return $headers;
    }

    /**
     * Extract plain text body from email
     */
    public static function extractPlainBody(string $rawEmail): string
    {
        // Try multipart plain text extraction
        if (preg_match('/Content-Type: text\/plain.*?\n\n(.+?)\n\n--/s', $rawEmail, $matches)) {
            return quoted_printable_decode($matches[1]);
        }
        
        // Try quoted-printable extraction
        if (preg_match('/Content-Transfer-Encoding: quoted-printable\s*\n\s*\n(.+?)\n--/s', $rawEmail, $matches)) {
            return quoted_printable_decode($matches[1]);
        }
        
        // Try simple body extraction
        $parts = preg_split('/\r?\n\r?\n/', $rawEmail, 2);
        if (count($parts) >= 2) {
            return trim($parts[1]);
        }
        
        return '';
    }
}
