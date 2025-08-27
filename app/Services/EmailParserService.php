<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class EmailParserService
{
    /**
     * Parse an EML file and extract relevant information
     */
    public function parseEmlFile(Document $document): array
    {
        try {
            $disk = Storage::disk($document->disk ?? config('filesystems.default'));
            $content = $disk->get($document->file_path);
            
            Log::info('Parsing EML file', [
                'document_id' => $document->id,
                'filename' => $document->filename,
                'size' => strlen($content),
            ]);
            
            $parsed = $this->parseEmailContent($content);
            
            Log::info('EML file parsed successfully', [
                'document_id' => $document->id,
                'has_text' => !empty($parsed['text']),
                'has_html' => !empty($parsed['html']),
                'attachments_count' => count($parsed['attachments'] ?? []),
            ]);
            
            return $parsed;
            
        } catch (Exception $e) {
            Log::error('Failed to parse EML file', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Parse the raw email content
     */
    private function parseEmailContent(string $content): array
    {
        $headers = [];
        $body = '';
        $attachments = [];
        
        // Split headers and body
        $parts = explode("\r\n\r\n", $content, 2);
        if (count($parts) < 2) {
            $parts = explode("\n\n", $content, 2);
        }
        
        $headerText = $parts[0] ?? '';
        $bodyText = $parts[1] ?? '';
        
        // Parse headers
        $headers = $this->parseHeaders($headerText);
        
        // Parse body (handle multipart if present)
        $parsedBody = $this->parseBody($bodyText, $headers);
        
        return [
            'from' => $headers['from'] ?? '',
            'to' => $headers['to'] ?? '',
            'subject' => $headers['subject'] ?? '',
            'date' => $headers['date'] ?? '',
            'text' => $parsedBody['text'] ?? '',
            'html' => $parsedBody['html'] ?? '',
            'attachments' => $parsedBody['attachments'] ?? [],
            'headers' => $headers,
        ];
    }
    
    /**
     * Parse email headers
     */
    private function parseHeaders(string $headerText): array
    {
        $headers = [];
        $lines = explode("\n", $headerText);
        $currentHeader = '';
        
        foreach ($lines as $line) {
            $line = rtrim($line, "\r");
            
            // Check if this is a continuation of the previous header
            if (preg_match('/^\s/', $line) && $currentHeader) {
                $headers[$currentHeader] .= ' ' . trim($line);
            } else {
                // New header
                if (preg_match('/^([^:]+):\s*(.*)/', $line, $matches)) {
                    $currentHeader = strtolower(trim($matches[1]));
                    $headers[$currentHeader] = trim($matches[2]);
                }
            }
        }
        
        // Decode RFC 2047 encoded headers (like =?utf-8?Q?...?=)
        foreach ($headers as $key => $value) {
            $headers[$key] = $this->decodeHeader($value);
        }
        
        return $headers;
    }
    
    /**
     * Decode RFC 2047 encoded header values
     */
    private function decodeHeader(string $header): string
    {
        // Handle RFC 2047 encoded words (=?charset?encoding?encoded-text?=)
        if (preg_match_all('/=\?([^?]+)\?([BQbq])\?([^?]*)\?=/', $header, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $charset = $match[1];
                $encoding = strtoupper($match[2]);
                $encodedText = $match[3];
                
                // Decode based on encoding type
                if ($encoding === 'B') {
                    // Base64 encoding
                    $decoded = base64_decode($encodedText);
                } elseif ($encoding === 'Q') {
                    // Quoted-printable encoding
                    $decoded = quoted_printable_decode(str_replace('_', ' ', $encodedText));
                } else {
                    $decoded = $encodedText;
                }
                
                // Convert charset if needed
                if (strtolower($charset) !== 'utf-8') {
                    $decoded = mb_convert_encoding($decoded, 'UTF-8', $charset);
                }
                
                // Replace the encoded part with decoded text
                $header = str_replace($match[0], $decoded, $header);
            }
        }
        
        return $header;
    }
    
    /**
     * Parse email body
     */
    private function parseBody(string $bodyText, array $headers): array
    {
        $contentType = $headers['content-type'] ?? '';
        
        // Simple case - plain text or HTML
        if (strpos($contentType, 'multipart') === false) {
            if (strpos($contentType, 'text/html') !== false) {
                return ['html' => $bodyText, 'text' => strip_tags($bodyText)];
            } else {
                return ['text' => $bodyText, 'html' => ''];
            }
        }
        
        // Multipart - extract boundary
        if (preg_match('/boundary[=:]\s*([^;\s]+)/i', $contentType, $matches)) {
            $boundary = trim($matches[1], '"');
            return $this->parseMultipart($bodyText, $boundary);
        }
        
        // Fallback - treat as plain text
        return ['text' => $bodyText, 'html' => ''];
    }
    
    /**
     * Parse multipart email content
     */
    private function parseMultipart(string $body, string $boundary): array
    {
        $result = ['text' => '', 'html' => '', 'attachments' => []];
        
        // Split by boundary
        $parts = explode('--' . $boundary, $body);
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part) || $part === '--') {
                continue;
            }
            
            // Split headers and content for this part
            $partSections = explode("\r\n\r\n", $part, 2);
            if (count($partSections) < 2) {
                $partSections = explode("\n\n", $part, 2);
            }
            
            if (count($partSections) < 2) {
                continue;
            }
            
            $partHeaders = $this->parseHeaders($partSections[0]);
            $partContent = $partSections[1];
            
            $contentType = $partHeaders['content-type'] ?? '';
            $disposition = $partHeaders['content-disposition'] ?? '';
            
            // Check if this is an attachment
            if (strpos($disposition, 'attachment') !== false) {
                $filename = 'attachment';
                if (preg_match('/filename[=:]\s*([^;\s]+)/i', $disposition, $matches)) {
                    $filename = trim($matches[1], '"');
                }
                
                $result['attachments'][] = [
                    'filename' => $filename,
                    'content_type' => $contentType,
                    'content' => $partContent,
                    'size' => strlen($partContent),
                ];
            } else {
                // This is body content
                if (strpos($contentType, 'text/html') !== false) {
                    $result['html'] = $partContent;
                } elseif (strpos($contentType, 'text/plain') !== false) {
                    $result['text'] = $partContent;
                }
            }
        }
        
        // If we have HTML but no text, extract text from HTML
        if (empty($result['text']) && !empty($result['html'])) {
            $result['text'] = strip_tags($result['html']);
        }
        
        return $result;
    }
    
    /**
     * Extract shipping-related content from email
     */
    public function extractShippingContent(array $emailData): string
    {
        $content = [];
        
        // Add email metadata with emphasis on subject (often contains vehicle info)
        if (!empty($emailData['subject'])) {
            $content[] = "=== EMAIL SUBJECT (Important for vehicle detection) ===";
            $content[] = $emailData['subject'];
            $content[] = "";
        }
        
        if (!empty($emailData['from'])) {
            $content[] = "From: " . $emailData['from'];
        }
        
        if (!empty($emailData['to'])) {
            $content[] = "To: " . $emailData['to'];
        }
        
        if (!empty($emailData['date'])) {
            $content[] = "Date: " . $emailData['date'];
        }
        
        $content[] = ""; // Empty line
        
        // Pre-analyze for vehicle information
        $vehicleInfo = $this->detectVehicleInformation($emailData);
        if (!empty($vehicleInfo)) {
            $content[] = "=== DETECTED VEHICLE INFORMATION ===";
            foreach ($vehicleInfo as $key => $value) {
                $content[] = ucfirst($key) . ": " . $value;
            }
            $content[] = "";
        }
        
        // Add main content (prefer text, fallback to HTML)
        $mainContent = !empty($emailData['text']) ? $emailData['text'] : $emailData['html'];
        if (!empty($mainContent)) {
            $content[] = "=== EMAIL CONTENT ===";
            
            // Clean up the content for better AI processing
            $cleanContent = $this->cleanEmailContent($mainContent);
            $content[] = $cleanContent;
        }
        
        // Add attachment information
        if (!empty($emailData['attachments'])) {
            $content[] = "";
            $content[] = "=== EMAIL ATTACHMENTS ===";
            foreach ($emailData['attachments'] as $attachment) {
                $content[] = "- " . $attachment['filename'] . " (" . ($attachment['content_type'] ?? 'unknown') . ")";
            }
        }
        
        $fullContent = implode("\n", $content);
        
        // Log the content being prepared for AI
        Log::info('Email content prepared for AI extraction', [
            'content_length' => strlen($fullContent),
            'has_subject' => !empty($emailData['subject']),
            'has_vehicle_detection' => !empty($vehicleInfo),
            'vehicle_detected' => $vehicleInfo,
            'subject' => $emailData['subject'] ?? 'N/A'
        ]);
        
        return $fullContent;
    }
    
    /**
     * Detect vehicle information in email data
     */
    private function detectVehicleInformation(array $emailData): array
    {
        $vehicleInfo = [];
        
        // Vehicle patterns for different brands
        $vehiclePatterns = [
            'BMW_Serie' => '/BMW\s+(?:Série|Series|S[eé]rie)\s*(\d+)/i',
            'BMW_Model' => '/BMW\s+([A-Z]?\d+[a-zA-Z]*(?:\s+[a-zA-Z]+)*)/i',
            'Mercedes' => '/Mercedes[\s-]?(?:Benz)?\s+([A-Z][\w\s-]+)/i',
            'Audi' => '/Audi\s+([A-Z]?\d+[a-zA-Z]*)/i',
            'Toyota' => '/Toyota\s+([A-Za-z0-9\s]+)/i',
            'Volkswagen' => '/(?:Volkswagen|VW)\s+([A-Za-z0-9\s]+)/i',
            'Peugeot' => '/Peugeot\s+(\d+[a-zA-Z]*)/i',
            'Renault' => '/Renault\s+([A-Za-z0-9\s]+)/i',
            'Ford' => '/Ford\s+([A-Za-z0-9\s]+)/i'
        ];
        
        // Clean and prepare search texts
        $searchTexts = [
            'subject' => $this->decodeHeader($emailData['subject'] ?? ''),
            'body' => $this->cleanEmailContent($emailData['text'] ?? $emailData['html'] ?? '')
        ];
        
        foreach ($searchTexts as $source => $text) {
            if (empty($text)) continue;
            
            foreach ($vehiclePatterns as $patternName => $pattern) {
                if (preg_match($pattern, $text, $matches)) {
                    $brandName = explode('_', $patternName)[0]; // Remove _Serie or _Model suffix
                    
                    if ($patternName === 'BMW_Serie') {
                        // Special handling for BMW Série
                        $vehicleInfo['brand'] = 'BMW';
                        $vehicleInfo['model'] = 'Série ' . trim($matches[1]);
                        $vehicleInfo['full_name'] = 'BMW Série ' . trim($matches[1]);
                        $vehicleInfo['detected_in'] = $source;
                        $vehicleInfo['detection_pattern'] = $patternName;
                        break 2; // Stop after first match
                    } elseif ($patternName === 'BMW_Model') {
                        $vehicleInfo['brand'] = 'BMW';
                        $vehicleInfo['model'] = trim($matches[1]);
                        $vehicleInfo['full_name'] = 'BMW ' . trim($matches[1]);
                        $vehicleInfo['detected_in'] = $source;
                        $vehicleInfo['detection_pattern'] = $patternName;
                        break 2; // Stop after first match
                    } else {
                        $vehicleInfo['brand'] = $brandName;
                        $vehicleInfo['model'] = trim($matches[1]);
                        $vehicleInfo['full_name'] = $brandName . ' ' . trim($matches[1]);
                        $vehicleInfo['detected_in'] = $source;
                        $vehicleInfo['detection_pattern'] = $patternName;
                        break 2; // Stop after first match
                    }
                }
            }
        }
        
        // Also check for general vehicle keywords if no specific brand found
        if (empty($vehicleInfo)) {
            foreach ($searchTexts as $source => $text) {
                if (preg_match('/(?:voiture|vehicle|car|auto|automobile)[\s:]*([^,\n.]+)/i', $text, $matches)) {
                    if (!empty(trim($matches[1]))) {
                        $vehicleInfo['type'] = 'vehicle';
                        $vehicleInfo['description'] = trim($matches[1]);
                        $vehicleInfo['detected_in'] = $source;
                        $vehicleInfo['detection_pattern'] = 'general_vehicle';
                        break;
                    }
                }
            }
        }
        
        return $vehicleInfo;
    }
    
    /**
     * Clean email content for better AI processing
     */
    private function cleanEmailContent(string $content): string
    {
        // Decode quoted-printable encoding
        $content = quoted_printable_decode($content);
        
        // Remove quoted email markers
        $content = preg_replace('/^>\s*/m', '', $content);
        
        // Remove excessive whitespace
        $content = preg_replace('/\n\s*\n\s*\n/m', "\n\n", $content);
        
        // Convert HTML entities if present
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Remove email footers/signatures (basic)
        $content = preg_replace('/--\s*\n.*$/s', '', $content);
        
        return trim($content);
    }
}
