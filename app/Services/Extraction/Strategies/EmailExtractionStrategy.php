<?php

namespace App\Services\Extraction\Strategies;

use App\Models\Document;
use App\Services\AiRouter;
use App\Services\Extraction\HybridExtractionPipeline;
use App\Services\Extraction\Results\ExtractionResult;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class EmailExtractionStrategy implements ExtractionStrategy
{
    public function __construct(
        private AiRouter $aiRouter,
        private HybridExtractionPipeline $hybridPipeline
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
            Log::info('Starting email extraction with hybrid pipeline', [
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

            // Prepare enriched email content for hybrid pipeline
            $enrichedContent = $this->prepareEmailContextForPipeline($emailData);

            Log::info('Email parsed successfully, using hybrid pipeline', [
                'document_id' => $document->id,
                'from_contact' => $emailData['from_name'] . ' <' . $emailData['from_email'] . '>',
                'subject' => $emailData['subject'],
                'content_length' => strlen($enrichedContent)
            ]);

            // Use hybrid pipeline to extract structured data with vehicle database enhancement
            $pipelineResult = $this->hybridPipeline->extract($enrichedContent, 'email');

            // Get the pipeline result data directly (let pipeline handle merging)
            $enhancedData = $pipelineResult['data'];

            // Add email-specific metadata
            $metadata = $pipelineResult['metadata'];
            $metadata['extraction_strategy'] = $this->getName();
            $metadata['email_metadata'] = [
                'from' => $emailData['from_name'] . ' <' . $emailData['from_email'] . '>',
                'to' => $emailData['to_email'] ?? null,
                'subject' => $emailData['subject'],
                'date' => $emailData['date']
            ];
            $metadata['source'] = 'email_extraction';
            $metadata['document_type'] = 'email';
            $metadata['filename'] = $document->filename;

            $confidence = $metadata['overall_confidence'] ?? 0;

            Log::info('Email extraction with hybrid pipeline completed', [
                'document_id' => $document->id,
                'confidence' => $confidence,
                'vehicle_found' => !empty($enhancedData['vehicle']),
                'contact_found' => !empty($enhancedData['contact']),
                'shipment_found' => !empty($enhancedData['shipment']),
                'database_enhanced' => $metadata['database_validated'] ?? false,
                'strategies_used' => $metadata['extraction_strategies'] ?? []
            ]);

            return ExtractionResult::success(
                $enhancedData,
                $confidence,
                $this->getName(),
                $metadata
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
            
            if (empty($content)) {
                Log::error('Failed to get email content or content is empty', [
                    'document_id' => $document->id,
                    'file_path' => $document->file_path,
                    'storage_disk' => $document->storage_disk
                ]);
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
    private function getEmailContent(Document $document): string
    {
        try {
            // First try using the storage_disk and file_path
            if ($document->storage_disk && $document->file_path) {
                if (Storage::disk($document->storage_disk)->exists($document->file_path)) {
                    $content = Storage::disk($document->storage_disk)->get($document->file_path);
                    return $content ?: '';
                }
            }

            // Try file_path as absolute path
            if ($document->file_path && file_exists($document->file_path)) {
                $content = file_get_contents($document->file_path);
                return $content !== false ? $content : '';
            }

            // Try default disk
            $defaultDisk = config('filesystems.default');
            if (Storage::disk($defaultDisk)->exists($document->file_path)) {
                $content = Storage::disk($defaultDisk)->get($document->file_path);
                return $content ?: '';
            }

            // Try alternative paths that might exist
            $alternativePaths = [
                'private/documents/' . basename($document->file_path),
                'documents/' . basename($document->file_path),
                $document->file_path
            ];

            foreach ($alternativePaths as $altPath) {
                if (Storage::disk('local')->exists($altPath)) {
                    $content = Storage::disk('local')->get($altPath);
                    Log::info('Found email file at alternative path', [
                        'document_id' => $document->id,
                        'found_path' => $altPath,
                        'original_path' => $document->file_path
                    ]);
                    return $content ?: '';
                }
            }

            Log::error('Could not find email file in any location', [
                'document_id' => $document->id,
                'storage_disk' => $document->storage_disk,
                'file_path' => $document->file_path,
                'file_exists_absolute' => file_exists($document->file_path ?? ''),
                'default_disk' => $defaultDisk,
                'checked_alternatives' => $alternativePaths
            ]);

            // Return empty string instead of null to satisfy return type
            return '';

        } catch (\Exception $e) {
            Log::error('Could not read email file', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
            // Return empty string instead of null to satisfy return type
            return '';
        }
    }

    /**
     * Extract plain text from email body (handle multipart messages)
     */
    private function extractPlainTextBody(string $body): string
    {
        $plainText = '';
        
        // Check if this is a multipart message
        if (preg_match('/Content-Type:\s*multipart\//i', $body)) {
            
            // First, try to extract just the plain text part
            if (preg_match('/Content-Type:\s*text\/plain.*?\n\n(.*?)(?=\n--)/s', $body, $matches)) {
                $plainText = $matches[1];
                
                // Handle quoted-printable encoding in the plain text part
                if (strpos($body, 'Content-Transfer-Encoding: quoted-printable') !== false) {
                    $plainText = quoted_printable_decode($plainText);
                }
            } else {
                // Fallback: extract from HTML part and clean it
                if (preg_match('/Content-Type:\s*text\/html.*?\n\n(.*?)(?=\n--)/s', $body, $matches)) {
                    $htmlContent = $matches[1];
                    
                    // Handle quoted-printable encoding in HTML part
                    if (strpos($body, 'Content-Transfer-Encoding: quoted-printable') !== false) {
                        $htmlContent = quoted_printable_decode($htmlContent);
                    }
                    
                    // Strip HTML tags and decode entities
                    $plainText = strip_tags(html_entity_decode($htmlContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                } else {
                    // Last resort: use the whole body
                    $plainText = $body;
                }
            }
        } else {
            // Single part message
            $plainText = $body;
            
            // Handle quoted-printable encoding
            if (strpos($body, 'Content-Transfer-Encoding: quoted-printable') !== false) {
                $plainText = quoted_printable_decode($plainText);
            }

            // Handle base64 encoding  
            if (strpos($body, 'Content-Transfer-Encoding: base64') !== false) {
                $plainText = base64_decode($plainText);
            }
        }

        // Clean up the text thoroughly
        $plainText = trim($plainText);
        
        // Remove MIME boundaries and headers that leaked through
        $plainText = preg_replace('/--Apple-Mail-[A-F0-9-]+/', '', $plainText);
        $plainText = preg_replace('/Content-Type:.*?\n/', '', $plainText);
        $plainText = preg_replace('/Content-Transfer-Encoding:.*?\n/', '', $plainText);
        $plainText = preg_replace('/Content-Disposition:.*?\n/', '', $plainText);
        $plainText = preg_replace('/charset=.*?\n/', '', $plainText);
        
        // Remove any remaining HTML if it leaked through
        if (strpos($plainText, '<html>') !== false || strpos($plainText, '<div>') !== false) {
            $plainText = strip_tags(html_entity_decode($plainText, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        
        // Normalize line endings and remove excessive whitespace
        $plainText = preg_replace('/\r\n|\r/', "\n", $plainText);
        $plainText = preg_replace('/\n{3,}/', "\n\n", $plainText);
        $plainText = preg_replace('/[ \t]{2,}/', ' ', $plainText);
        
        // Remove HTML entities that might remain
        $plainText = html_entity_decode($plainText, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($plainText);
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

    /**
     * Prepare email content for hybrid pipeline processing
     */
    private function prepareEmailContextForPipeline(array $emailData): string
    {
        $context = '';
        
        // Add email metadata as context
        if (!empty($emailData['from_name']) || !empty($emailData['from_email'])) {
            $fromInfo = trim(($emailData['from_name'] ?? '') . ' <' . ($emailData['from_email'] ?? '') . '>');
            $context .= "SENDER: " . $fromInfo . "\n";
        }
        
        if (!empty($emailData['to_email'])) {
            $context .= "RECIPIENT: " . $emailData['to_email'] . "\n";
        }
        
        if (!empty($emailData['subject'])) {
            $context .= "SUBJECT: " . $emailData['subject'] . "\n";
        }
        
        if (!empty($emailData['date'])) {
            $context .= "DATE: " . $emailData['date'] . "\n";
        }
        
        $context .= "\n--- EMAIL CONTENT ---\n";
        $context .= $emailData['body'] ?? '';
        
        return $context;
    }


}
