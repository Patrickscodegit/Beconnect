<?php

namespace App\Services\Extraction\Strategies;

use App\Models\Document;
use App\Services\AiRouter;
use App\Services\Extraction\HybridExtractionPipeline;
use App\Services\Extraction\Results\ExtractionResult;
use App\Support\DocumentStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use ZBateson\MailMimeParser\MailMimeParser;

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

            // CRITICAL FIX: Ensure email sender contact is merged into main contact data
            $this->mergeEmailSenderIntoContact($enhancedData, $emailData);

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
     * Parse email file and extract headers and content using robust MIME parser
     */
    private function parseEmailFile(Document $document): ?array
    {
        try {
            $content = $this->getEmailContent($document);
            
            if ($content === '') {
                Log::error('Email content empty', [
                    'document_id' => $document->id,
                    'file_path' => $document->file_path,
                    'storage_disk' => $document->storage_disk
                ]);
                return null;
            }

            $parser = new MailMimeParser();
            $message = $parser->parse($content, false);

            // Extract sender information with fallbacks
            $fromHeaderObject = $message->getHeader('from');
            $fromHeader = $fromHeaderObject ? $fromHeaderObject->getRawValue() : null;
            $replyToHeader = $message->getHeaderValue('reply-to');
            
            $fromEmail = null;
            $fromName = null;
            
            // Parse FROM header first
            if ($fromHeader) {
                if (preg_match('/^(.+?)\s*<([^>]+)>/', $fromHeader, $matches)) {
                    $fromName = trim($matches[1], ' "\'');
                    $fromEmail = trim($matches[2]);
                } elseif (preg_match('/^([^\s]+@[^\s]+)/', $fromHeader, $matches)) {
                    $fromEmail = trim($matches[1]);
                }
            }
            
            // Fallback to Reply-To if FROM is missing
            if (!$fromEmail && $replyToHeader) {
                if (preg_match('/<([^>]+)>/', $replyToHeader, $matches)) {
                    $fromEmail = trim($matches[1]);
                } elseif (preg_match('/^([^\s]+@[^\s]+)/', $replyToHeader, $matches)) {
                    $fromEmail = trim($matches[1]);
                }
            }

            // Fallback: infer a name from the email local part if missing
            if (!$fromName && $fromEmail) {
                $fromName = $this->inferNameFromEmailLocalPart($fromEmail);
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

            // Extract body content - prefer text, fallback to HTML
            $textContent = $message->getTextContent();
            $htmlContent = $message->getHtmlContent();
            
            $body = '';
            if (!empty($textContent)) {
                $body = $textContent;
            } elseif (!empty($htmlContent)) {
                $body = strip_tags(html_entity_decode($htmlContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }

            Log::info('Email parsed successfully (modern MIME parser)', [
                'document_id' => $document->id,
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'to_email' => $toEmail,
                'subject' => $subject,
                'body_length' => strlen($body)
            ]);

            return [
                'headers' => [], // keep minimal; you can add selected headers if needed
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'to_email' => $toEmail,
                'subject' => $subject,
                'body' => trim($body),
                'date' => $date,
            ];

        } catch (\Throwable $e) {
            Log::error('Failed to parse email file (modern MIME)', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return null;
        }
    }

    /**
     * Helper: Infer a name from email local part if missing
     */
    private function inferNameFromEmailLocalPart(string $email): ?string
    {
        if (!str_contains($email, '@')) return null;
        
        [$local] = explode('@', $email, 2);
        $local = preg_replace('/[._-]+/', ' ', $local);
        $local = ucwords(trim(preg_replace('/\s+/', ' ', $local)));
        
        return $local !== '' ? $local : null;
    }

    /**
     * Get email content from document with resilient cross-environment handling
     */
    private function getEmailContent(Document $document): string
    {
        try {
            Log::info('Attempting to retrieve email content via DocumentStorage gateway', [
                'document_id' => $document->id,
                'storage_disk' => $document->storage_disk,
                'file_path' => $document->file_path,
                'environment' => app()->environment()
            ]);

            $content = DocumentStorage::getContent($document);
            
            if ($content === null) {
                $guidance = $this->getEnvironmentSpecificGuidance($document);
                throw new \RuntimeException("source_unavailable: cannot read document from storage (disk={$document->storage_disk}, path={$document->file_path}). {$guidance}");
            }

            Log::info('Successfully retrieved email content', [
                'document_id' => $document->id,
                'content_length' => strlen($content),
                'strategy' => 'DocumentStorage gateway'
            ]);

            return $content;

        } catch (\Exception $e) {
            Log::error('Email content retrieval failed', [
                'document_id' => $document->id,
                'storage_disk' => $document->storage_disk,
                'file_path' => $document->file_path,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Return empty string to satisfy return type and let parsing handle the error gracefully
            return '';
        }
    }

    /**
     * Get environment-specific guidance for storage issues
     */
    private function getEnvironmentSpecificGuidance(Document $document): string
    {
        $env = app()->environment();
        $originalDisk = $document->storage_disk;

        if ($env === 'local' && $originalDisk === 'spaces') {
            return 'Document stored on DigitalOcean Spaces but running locally. Consider: 1) Configure DO Spaces credentials in .env, 2) Copy file to local storage, 3) Use storage:migrate command';
        }

        if ($env === 'production' && $originalDisk === 'local') {
            return 'Document stored locally but running in production. Consider migrating to cloud storage.';
        }

        return 'Cross-environment storage mismatch detected. Check storage configuration and file locations.';
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

    /**
     * CRITICAL FIX: Merge email sender information into contact data
     * This ensures the sender (customer) email/name is properly extracted as the contact
     */
    private function mergeEmailSenderIntoContact(array &$enhancedData, array $emailData): void
    {
        // If no contact extracted or contact is incomplete, use email sender as primary contact
        $currentContact = $enhancedData['contact'] ?? [];
        
        // Email sender has high confidence as the customer/contact
        $emailSender = [
            'name' => $emailData['from_name'] ?? null,
            'email' => $emailData['from_email'] ?? null,
        ];
        
        // Clean sender name
        if ($emailSender['name']) {
            $emailSender['name'] = trim($emailSender['name'], ' "\'');
        }
        
        // Merge strategy: Email sender wins if current contact is empty/incomplete
        $mergedContact = [];
        
        // Use sender name if current name is missing or looks generic
        if (empty($currentContact['name']) || 
            in_array(strtolower($currentContact['name'] ?? ''), ['unknown', 'customer', 'client', 'sender'])) {
            $mergedContact['name'] = $emailSender['name'];
        } else {
            $mergedContact['name'] = $currentContact['name'];
        }
        
        // Use sender email if current email is missing
        if (empty($currentContact['email'])) {
            $mergedContact['email'] = $emailSender['email'];
        } else {
            $mergedContact['email'] = $currentContact['email'];
        }
        
        // Preserve other contact fields
        $mergedContact['phone'] = $currentContact['phone'] ?? null;
        $mergedContact['company'] = $currentContact['company'] ?? null;
        
        // Infer company from email domain if missing
        if (empty($mergedContact['company']) && !empty($mergedContact['email'])) {
            $mergedContact['company'] = $this->inferCompanyFromEmail($mergedContact['email']);
        }
        
        // Remove empty values
        $enhancedData['contact'] = array_filter($mergedContact, fn($v) => !is_null($v) && $v !== '');
        
        Log::info('Email sender merged into contact data', [
            'sender_name' => $emailSender['name'],
            'sender_email' => $emailSender['email'],
            'final_contact' => $enhancedData['contact']
        ]);
    }
    
    /**
     * Infer company name from email domain
     */
    private function inferCompanyFromEmail(string $email): ?string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        
        $domain = explode('@', $email)[1] ?? '';
        $domainParts = explode('.', $domain);
        $mainDomain = $domainParts[0] ?? '';
        
        // Skip common personal email providers
        $personalProviders = ['gmail', 'yahoo', 'hotmail', 'outlook', 'live', 'icloud', 'aol'];
        if (in_array(strtolower($mainDomain), $personalProviders)) {
            return null;
        }
        
        // Clean and format company name
        return ucfirst(strtolower($mainDomain));
    }


}
