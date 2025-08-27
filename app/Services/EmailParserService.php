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
        
        return $headers;
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
        
        // Add email metadata
        if (!empty($emailData['subject'])) {
            $content[] = "Subject: " . $emailData['subject'];
        }
        
        if (!empty($emailData['from'])) {
            $content[] = "From: " . $emailData['from'];
        }
        
        if (!empty($emailData['date'])) {
            $content[] = "Date: " . $emailData['date'];
        }
        
        $content[] = ""; // Empty line
        
        // Add main content (prefer text, fallback to HTML)
        $mainContent = !empty($emailData['text']) ? $emailData['text'] : $emailData['html'];
        if (!empty($mainContent)) {
            $content[] = "Email Content:";
            $content[] = $mainContent;
        }
        
        // Add attachment information
        if (!empty($emailData['attachments'])) {
            $content[] = "";
            $content[] = "Email Attachments:";
            foreach ($emailData['attachments'] as $attachment) {
                $content[] = "- " . $attachment['filename'] . " (" . $attachment['content_type'] . ")";
            }
        }
        
        return implode("\n", $content);
    }
}
