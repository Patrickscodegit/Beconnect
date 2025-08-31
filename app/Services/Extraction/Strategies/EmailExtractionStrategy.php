<?php

namespace App\Services\Extraction\Strategies;

use App\Models\Document;
use App\Services\AiRouter;
use App\Services\Extraction\Results\ExtractionResult;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class EmailExtractionStrategy implements ExtractionStrategy
{
    public function __construct(
        private AiRouter $aiRouter
    ) {}

    public function getName(): string
    {
        return 'email_extraction';
    }

    public function getPriority(): int
    {
        return 100; // High priority for email documents
    }

    public function supports(Document $document): bool
    {
        // Support .eml files and RFC822 mime type
        return $document->mime_type === 'message/rfc822' || 
               str_ends_with(strtolower($document->filename), '.eml');
    }

    public function extract(Document $document): ExtractionResult
    {
        try {
            Log::info('Starting email extraction', [
                'document_id' => $document->id,
                'filename' => $document->filename,
                'strategy' => $this->getName()
            ]);

            // Parse email content and headers
            $emailData = $this->parseEmailFile($document);

            if (!$emailData) {
                return ExtractionResult::failure(
                    $this->getName(), 
                    'Could not parse email content',
                    ['document_id' => $document->id]
                );
            }

            // Use AI to extract structured data from email
            $extractedData = $this->aiRouter->extractFromEmail($emailData);

            if (empty($extractedData)) {
                return ExtractionResult::failure(
                    $this->getName(),
                    'AI extraction returned empty data',
                    ['document_id' => $document->id]
                );
            }

            // Calculate confidence based on extracted data quality
            $confidence = $this->calculateConfidence($extractedData, $emailData);

            Log::info('Email extraction completed', [
                'document_id' => $document->id,
                'confidence' => $confidence,
                'extracted_fields' => array_keys($extractedData)
            ]);

            return ExtractionResult::success(
                $extractedData,
                $confidence,
                $this->getName(),
                [
                    'email_metadata' => [
                        'from' => $emailData['from_name'] . ' <' . $emailData['from_email'] . '>',
                        'subject' => $emailData['subject'],
                        'date' => $emailData['date']
                    ]
                ]
            );

        } catch (\Exception $e) {
            Log::error('Email extraction failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'strategy' => $this->getName()
            ]);

            return ExtractionResult::failure(
                $this->getName(),
                $e->getMessage(),
                ['document_id' => $document->id, 'error_type' => get_class($e)]
            );
        }
    }

    /**
     * Parse email file and extract headers and content
     */
    private function parseEmailFile(Document $document): ?array
    {
        try {
            $content = $this->getEmailContent($document);
            
            if (!$content) {
                Log::error('Failed to get email content', ['document_id' => $document->id]);
                return null;
            }

            // Parse email headers and body
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
                        
                        // Handle multi-line headers
                        if (isset($headers[$headerName])) {
                            if (is_array($headers[$headerName])) {
                                $headers[$headerName][] = $headerValue;
                            } else {
                                $headers[$headerName] = [$headers[$headerName], $headerValue];
                            }
                        } else {
                            $headers[$headerName] = $headerValue;
                        }
                    } elseif (preg_match('/^\s+(.+)$/', $line, $matches) && !empty($headers)) {
                        // Continuation of previous header
                        $lastKey = array_key_last($headers);
                        if (is_array($headers[$lastKey])) {
                            $headers[$lastKey][count($headers[$lastKey]) - 1] .= ' ' . trim($matches[1]);
                        } else {
                            $headers[$lastKey] .= ' ' . trim($matches[1]);
                        }
                    }
                } else {
                    $body .= $line . "\n";
                }
            }

            // Extract sender information from headers
            $fromHeader = $headers['from'] ?? '';
            $fromEmail = null;
            $fromName = null;
            
            // Parse From header - handle format: "Name <email@domain.com>"
            if (preg_match('/^(.+?)\s*<([^>]+)>/', $fromHeader, $matches)) {
                $fromName = trim($matches[1], ' "\'');
                $fromEmail = trim($matches[2]);
            } elseif (preg_match('/^([^\s]+@[^\s]+)/', $fromHeader, $matches)) {
                // Just email address
                $fromEmail = trim($matches[1]);
            }
            
            // Also check Return-Path as fallback
            if (!$fromEmail && isset($headers['return-path'])) {
                if (preg_match('/<([^>]+)>/', $headers['return-path'], $matches)) {
                    $fromEmail = trim($matches[1]);
                } elseif (preg_match('/([^\s]+@[^\s]+)/', $headers['return-path'], $matches)) {
                    $fromEmail = trim($matches[1]);
                }
            }

            // Extract recipient information
            $toHeader = $headers['to'] ?? '';
            $toEmail = null;
            
            if (preg_match('/<([^>]+)>/', $toHeader, $matches)) {
                $toEmail = trim($matches[1]);
            } elseif (preg_match('/^([^\s]+@[^\s]+)/', $toHeader, $matches)) {
                $toEmail = trim($matches[1]);
            }

            // Extract subject and decode if needed
            $subject = $headers['subject'] ?? '';
            if (preg_match('/=\?.*\?=/i', $subject)) {
                $subject = mb_decode_mimeheader($subject);
            }

            // Extract plain text body
            $plainBody = $this->extractPlainTextBody($body);

            Log::info('Email parsed successfully', [
                'document_id' => $document->id,
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'to_email' => $toEmail,
                'subject' => $subject,
                'body_length' => strlen($plainBody)
            ]);

            return [
                'headers' => $headers,
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'to_email' => $toEmail,
                'subject' => $subject,
                'body' => $plainBody,
                'date' => $headers['date'] ?? null,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to parse email file', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }

    /**
     * Get email content from document
     */
    private function getEmailContent(Document $document): ?string
    {
        try {
            // First try using the storage_disk and file_path
            if ($document->storage_disk && $document->file_path) {
                if (Storage::disk($document->storage_disk)->exists($document->file_path)) {
                    return Storage::disk($document->storage_disk)->get($document->file_path);
                }
            }

            // Try file_path as absolute path
            if ($document->file_path && file_exists($document->file_path)) {
                return file_get_contents($document->file_path);
            }

            // Try default disk
            $defaultDisk = config('filesystems.default');
            if (Storage::disk($defaultDisk)->exists($document->file_path)) {
                return Storage::disk($defaultDisk)->get($document->file_path);
            }

            Log::error('Could not find email file in any location', [
                'document_id' => $document->id,
                'storage_disk' => $document->storage_disk,
                'file_path' => $document->file_path,
                'file_exists_absolute' => file_exists($document->file_path ?? ''),
                'default_disk' => $defaultDisk
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Could not read email file', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Extract plain text from email body (handle multipart messages)
     */
    private function extractPlainTextBody(string $body): string
    {
        // Check if this is a multipart message
        if (preg_match('/Content-Type:\s*multipart\//i', $body)) {
            // Extract plain text part
            if (preg_match('/Content-Type:\s*text\/plain[^-]*?\n\n(.*?)(?=--)/s', $body, $matches)) {
                $plainText = $matches[1];
            } else {
                // Fallback to HTML and strip tags
                if (preg_match('/Content-Type:\s*text\/html[^-]*?\n\n(.*?)(?=--)/s', $body, $matches)) {
                    $plainText = strip_tags(html_entity_decode($matches[1]));
                } else {
                    $plainText = $body;
                }
            }
        } else {
            $plainText = $body;
        }

        // Decode quoted-printable if needed
        if (strpos($body, 'Content-Transfer-Encoding: quoted-printable') !== false) {
            $plainText = quoted_printable_decode($plainText);
        }

        // Clean up the text
        $plainText = trim($plainText);
        $plainText = preg_replace('/\r\n|\r/', "\n", $plainText);
        $plainText = preg_replace('/\n{3,}/', "\n\n", $plainText);

        return $plainText;
    }

    /**
     * Calculate confidence based on extracted data quality
     */
    private function calculateConfidence(array $extractedData, array $emailData): float
    {
        $score = 0.6; // Base score for successful extraction
        
        // Increase score if we have sender information
        if (!empty($emailData['from_email'])) $score += 0.1;
        if (!empty($emailData['from_name'])) $score += 0.1;
        
        // Increase score based on extracted fields
        if (isset($extractedData['contact']) && !empty($extractedData['contact'])) $score += 0.1;
        if (isset($extractedData['vehicle']) && !empty($extractedData['vehicle'])) $score += 0.1;
        if (isset($extractedData['shipment']) && !empty($extractedData['shipment'])) $score += 0.1;
        
        return min(1.0, $score);
    }
}
