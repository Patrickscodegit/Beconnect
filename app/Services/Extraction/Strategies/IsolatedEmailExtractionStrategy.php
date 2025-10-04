<?php

namespace App\Services\Extraction\Strategies;

use App\Models\Document;
use App\Services\AiRouter;
use App\Services\Extraction\Results\ExtractionResult;
use App\Support\DocumentStorage;
use Illuminate\Support\Facades\Log;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * ISOLATED EMAIL EXTRACTION STRATEGY
 * 
 * This strategy is completely isolated from PDF/Image enhancements.
 * It uses its own dedicated pipeline and won't be affected by changes
 * to other extraction strategies.
 */
class IsolatedEmailExtractionStrategy implements ExtractionStrategy
{
    public function __construct(
        private AiRouter $aiRouter
    ) {}

    public function getName(): string
    {
        return 'isolated_email_extraction';
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
            Log::info('Starting ISOLATED email extraction', [
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

            // Use dedicated email extraction pipeline (NOT shared HybridExtractionPipeline)
            $extractedData = $this->extractEmailData($emailData);

            // Add email-specific metadata
            $metadata = [
                'extraction_strategy' => $this->getName(),
                'email_metadata' => [
                    'from' => $emailData['from_name'] . ' <' . $emailData['from_email'] . '>',
                    'to' => $emailData['to_email'] ?? null,
                    'subject' => $emailData['subject'],
                    'date' => $emailData['date']
                ],
                'source' => 'isolated_email_extraction',
                'document_type' => 'email',
                'filename' => $document->filename,
                'isolation_level' => 'complete' // This strategy is completely isolated
            ];

            $confidence = $this->calculateConfidence($extractedData, $emailData);

            Log::info('ISOLATED email extraction completed', [
                'document_id' => $document->id,
                'confidence' => $confidence,
                'vehicle_found' => !empty($extractedData['vehicle']),
                'contact_found' => !empty($extractedData['contact']),
                'shipment_found' => !empty($extractedData['shipment']),
                'isolation_status' => 'protected'
            ]);

            return ExtractionResult::success(
                $extractedData,
                $confidence,
                $this->getName(),
                $metadata
            );

        } catch (\Exception $e) {
            Log::error('ISOLATED email extraction failed', [
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
     * DEDICATED EMAIL DATA EXTRACTION
     * This method is completely isolated and won't be affected by PDF/Image changes
     * Uses sophisticated pattern-based extraction like the original EmailExtractionStrategy
     */
    private function extractEmailData(array $emailData): array
    {
        $extractedData = [
            'contact' => [],
            'vehicle' => [],
            'shipment' => [],
            'pricing' => [],
            'dates' => [],
            'cargo' => [],
            'raw_data' => []
        ];

        // Extract contact information from email headers
        if (!empty($emailData['from_email'])) {
            $extractedData['contact']['email'] = $emailData['from_email'];
        }
        if (!empty($emailData['from_name'])) {
            $extractedData['contact']['name'] = $emailData['from_name'];
        }

        // CRITICAL: Pre-extract cargo information from email content before AI processing
        $this->extractCargoFromEmailContent($extractedData, $emailData['body'] ?? '');

        // Extract data from email body using AI (isolated call)
        $bodyContent = $emailData['body'] ?? '';
        if (!empty($bodyContent)) {
            // Prepare enriched email content for AI processing
            $enrichedContent = $this->prepareEmailContextForPipeline($emailData);
            
            $aiResult = $this->aiRouter->extract($enrichedContent, [], [
                'cheap' => true, // Use cheap model for email processing
                'reasoning' => false,
                'isolation_mode' => true // Flag for isolated processing
            ]);

            if (!empty($aiResult)) {
                // Merge AI results with email-specific data
                $extractedData = array_merge_recursive($extractedData, $aiResult);
            }
        }

        // CRITICAL: Ensure email sender contact is merged into main contact data
        $this->mergeEmailSenderIntoContact($extractedData, $emailData);

        return $extractedData;
    }

    /**
     * Prepare email content for AI processing (isolated version)
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

    /**
     * Extract cargo information from email content (isolated version)
     */
    private function extractCargoFromEmailContent(array &$enhancedData, string $emailBody): void
    {
        // Initialize cargo array if not exists
        if (!isset($enhancedData['cargo'])) {
            $enhancedData['cargo'] = [];
        }

        // Cargo extraction patterns for Dutch/German/English
        $cargoPatterns = [
            // Dutch patterns
            '/\b(\d+)\s*[xX]\s*(gebruikte|nieuwe|tweedehands|secondhand)\s+([^,\n]+?)(?:\s+met\s+afmeting\s+([^,\n]+?))?(?:[,\n]|$)/i',
            '/\b(\d+)\s*[xX]\s*([^,\n]+?)(?:\s+met\s+afmeting\s+([^,\n]+?))?(?:[,\n]|$)/i',
            
            // German patterns
            '/\b(\d+)\s*[xX]\s*(gebrauchte|neue|zweite)\s+([^,\n]+?)(?:\s+mit\s+Abmessung\s+([^,\n]+?))?(?:[,\n]|$)/i',
            '/\b(\d+)\s*[xX]\s*([^,\n]+?)(?:\s+mit\s+Abmessung\s+([^,\n]+?))?(?:[,\n]|$)/i',
            
            // English patterns
            '/\b(\d+)\s*[xX]\s*(used|new|second-hand)\s+([^,\n]+?)(?:\s+with\s+dimensions?\s+([^,\n]+?))?(?:[,\n]|$)/i',
            '/\b(\d+)\s*[xX]\s*([^,\n]+?)(?:\s+with\s+dimensions?\s+([^,\n]+?))?(?:[,\n]|$)/i',
            
            // Generic patterns
            '/\b(\d+)\s*[xX]\s*([^,\n]+?)(?:\s+([^,\n]+?))?(?:[,\n]|$)/i',
        ];

        $cargoFound = false;
        
        foreach ($cargoPatterns as $pattern) {
            if (preg_match($pattern, $emailBody, $matches)) {
                $quantity = (int)($matches[1] ?? 1);
                $description = trim($matches[2] ?? $matches[3] ?? '');
                $dimensions = trim($matches[4] ?? $matches[3] ?? '');
                
                // Clean up description
                $description = preg_replace('/\s+/', ' ', $description);
                $description = trim($description, ' .,;');
                
                // Extract dimensions if present
                if (!empty($dimensions)) {
                    // Try regex pattern first
                    if (preg_match('/(\d+(?:[.,]\d+)?)\s*[xX×]\s*(\d+(?:[.,]\d+)?)\s*[xX×]\s*(\d+(?:[.,]\d+)?)\s*(m|cm|mm)/i', $dimensions, $dimMatches)) {
                        $length = (float)str_replace(',', '.', $dimMatches[1]);
                        $width = (float)str_replace(',', '.', $dimMatches[2]);
                        $height = (float)str_replace(',', '.', $dimMatches[3]);
                        $unit = strtolower($dimMatches[4]);
                    } else {
                        // Fallback to manual parsing
                        $parts = preg_split('/\s*x\s*/i', $dimensions);
                        if (count($parts) >= 3) {
                            $length = (float)str_replace(',', '.', trim($parts[0]));
                            $width = (float)str_replace(',', '.', trim($parts[1]));
                            $height = (float)str_replace(',', '.', trim($parts[2]));
                            $unit = 'm'; // Default to meters
                        }
                    }
                    
                    if (isset($length) && isset($width) && isset($height)) {
                        // Convert to meters
                        $multiplier = match($unit) {
                            'cm' => 0.01,
                            'mm' => 0.001,
                            default => 1
                        };
                        
                        $enhancedData['cargo']['dimensions'] = [
                            'length_m' => round($length * $multiplier, 3),
                            'width_m' => round($width * $multiplier, 3),
                            'height_m' => round($height * $multiplier, 3),
                            'unit_source' => $unit
                        ];
                    }
                }
                
                // Set cargo information
                $enhancedData['cargo']['description'] = $description;
                $enhancedData['cargo']['quantity'] = $quantity;
                $enhancedData['cargo']['type'] = $description; // For compatibility
                
                // Also add to raw_data for field mapping
                if (!isset($enhancedData['raw_data'])) {
                    $enhancedData['raw_data'] = [];
                }
                $enhancedData['raw_data']['cargo'] = $description;
                $enhancedData['raw_data']['cargo_quantity'] = $quantity;
                
                if (isset($enhancedData['cargo']['dimensions'])) {
                    $enhancedData['raw_data']['dim_bef_delivery'] = sprintf(
                        '%.2f x %.2f x %.2f m',
                        $enhancedData['cargo']['dimensions']['length_m'],
                        $enhancedData['cargo']['dimensions']['width_m'],
                        $enhancedData['cargo']['dimensions']['height_m']
                    );
                }
                
                $cargoFound = true;
                
                Log::info('Cargo extracted from email content', [
                    'description' => $description,
                    'quantity' => $quantity,
                    'dimensions' => $enhancedData['cargo']['dimensions'] ?? null,
                    'pattern_used' => $pattern
                ]);
                
                break; // Use first match
            }
        }
        
        if (!$cargoFound) {
            Log::info('No cargo information found in email content', [
                'content_length' => strlen($emailBody),
                'content_preview' => substr($emailBody, 0, 200)
            ]);
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

            Log::info('Email parsed successfully (ISOLATED strategy)', [
                'document_id' => $document->id,
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'to_email' => $toEmail,
                'subject' => $subject,
                'body_length' => strlen($body)
            ]);

            return [
                'headers' => [],
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'to_email' => $toEmail,
                'subject' => $subject,
                'body' => trim($body),
                'date' => $date,
            ];

        } catch (\Throwable $e) {
            Log::error('Failed to parse email file (ISOLATED strategy)', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return null;
        }
    }

    /**
     * Get email content from document with resilient cross-environment handling
     */
    private function getEmailContent(Document $document): string
    {
        try {
            Log::info('Retrieving email content via DocumentStorage gateway (ISOLATED)', [
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

            Log::info('Successfully retrieved email content (ISOLATED)', [
                'document_id' => $document->id,
                'content_length' => strlen($content),
                'strategy' => 'DocumentStorage gateway (ISOLATED)'
            ]);

            return $content;

        } catch (\Exception $e) {
            Log::error('Email content retrieval failed (ISOLATED)', [
                'document_id' => $document->id,
                'storage_disk' => $document->storage_disk,
                'file_path' => $document->file_path,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
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
}
