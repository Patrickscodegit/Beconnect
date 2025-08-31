<?php

namespace App\Utils;

use App\Services\Extraction\HybridExtractionPipeline;

class EmailQuotationProcessor
{
    private HybridExtractionPipeline $pipeline;
    private static array $processedEmails = [];

    public function __construct(HybridExtractionPipeline $pipeline)
    {
        $this->pipeline = $pipeline;
    }

    /**
     * Process email and map to quotation fields with deduplication
     */
    public function processEmailForQuotation(string $emailContent, ?string $emailId = null): array
    {
        // Generate content hash for deduplication
        $contentHash = md5($emailContent);
        
        // Check if already processed
        if ($emailId && isset(self::$processedEmails[$emailId])) {
            return [
                'status' => 'duplicate',
                'message' => 'Email already processed',
                'original_result' => self::$processedEmails[$emailId]
            ];
        }
        
        if (isset(self::$processedEmails[$contentHash])) {
            return [
                'status' => 'duplicate_content',
                'message' => 'Content already processed',
                'original_result' => self::$processedEmails[$contentHash]
            ];
        }

        // Extract sender email from full email headers if provided
        $senderEmail = $this->extractSenderEmail($emailContent);
        
        // Extract plain text content
        $plainText = $this->extractPlainText($emailContent);
        
        if (!$plainText) {
            return [
                'status' => 'error',
                'message' => 'Could not extract plain text content',
                'data' => null
            ];
        }

        // Perform extraction
        $result = $this->pipeline->extract($plainText, 'email');
        $data = $result['data'] ?? [];

        // Enhance with header email if missing
        if (!data_get($data, 'contact.email') && $senderEmail) {
            data_set($data, 'contact.email', $senderEmail);
        }

        // Map to quotation fields
        $quotationMapping = $this->mapToQuotationFields($data);
        
        // Determine processing recommendation
        $recommendation = $this->getProcessingRecommendation($quotationMapping, $data);
        
        $processedResult = [
            'status' => 'success',
            'extraction_data' => $data,
            'quotation_fields' => $quotationMapping,
            'robaws_fields' => RobawsPayloadMapper::mapExtraFields($data),
            'recommendation' => $recommendation,
            'metadata' => [
                'processed_at' => now()->toISOString(),
                'content_hash' => $contentHash,
                'sender_email' => $senderEmail,
                'extraction_quality' => data_get($data, 'final_validation.quality_score', 0),
                'completeness_score' => data_get($data, 'final_validation.completeness_score', 0)
            ]
        ];

        // Cache result for deduplication
        if ($emailId) {
            self::$processedEmails[$emailId] = $processedResult;
        }
        self::$processedEmails[$contentHash] = $processedResult;

        return $processedResult;
    }

    /**
     * Extract sender email from email headers
     */
    private function extractSenderEmail(string $emailContent): ?string
    {
        if (preg_match('/From:.*?<(.+?)>/', $emailContent, $matches)) {
            return trim($matches[1]);
        } elseif (preg_match('/From:\s*([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $emailContent, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    /**
     * Extract plain text content from email
     */
    private function extractPlainText(string $emailContent): ?string
    {
        // Try multipart plain text extraction
        if (preg_match('/Content-Type: text\/plain.*?\n\n(.+?)\n\n--Apple-Mail/s', $emailContent, $matches)) {
            return quoted_printable_decode($matches[1]);
        }
        
        // Try quoted-printable extraction
        if (preg_match('/Content-Transfer-Encoding: quoted-printable\s*\n\s*\n(.+?)\n--/s', $emailContent, $matches)) {
            return quoted_printable_decode($matches[1]);
        }
        
        // Try base64 extraction
        if (preg_match('/Content-Transfer-Encoding: base64\s*\n\s*\n(.+?)\n--/s', $emailContent, $matches)) {
            return base64_decode($matches[1]);
        }
        
        // Fallback: try to extract any text after headers
        if (preg_match('/\n\n(.+?)$/s', $emailContent, $matches)) {
            return trim($matches[1]);
        }
        
        return null;
    }

    /**
     * Map extraction data to quotation fields
     */
    private function mapToQuotationFields(array $data): array
    {
        return [
            // Customer information
            'customer_name' => data_get($data, 'contact.name'),
            'customer_email' => data_get($data, 'contact.email'),
            'customer_phone' => data_get($data, 'contact.phone'),
            'customer_company' => data_get($data, 'contact.company'),
            
            // Routing
            'por' => data_get($data, 'shipment.origin'),
            'pol' => data_get($data, 'shipment.origin'),
            'pod' => data_get($data, 'shipment.destination'),
            
            // Cargo
            'cargo_description' => $this->buildCargoDescription($data),
            'cargo_quantity' => 1,
            'cargo_unit' => 'x used vehicle',
            
            // Dimensions
            'cargo_length' => data_get($data, 'vehicle.dimensions.length_m'),
            'cargo_width' => data_get($data, 'vehicle.dimensions.width_m'),
            'cargo_height' => data_get($data, 'vehicle.dimensions.height_m'),
            'dim_before_delivery' => $this->buildDimensionString($data),
            
            // Transport
            'transport_mode' => data_get($data, 'shipment.shipping_type'),
            'service_type' => data_get($data, 'shipping.method', 'RoRo'),
            
            // Quality metrics
            'extraction_quality' => round((data_get($data, 'final_validation.quality_score', 0) * 100), 1),
            'data_completeness' => round((data_get($data, 'final_validation.completeness_score', 0) * 100), 1),
        ];
    }

    /**
     * Build cargo description from vehicle data
     */
    private function buildCargoDescription(array $data): ?string
    {
        $brand = data_get($data, 'vehicle.brand');
        $model = data_get($data, 'vehicle.model');
        
        if ($brand && $model) {
            return "$brand $model";
        } elseif ($brand) {
            return $brand . ' vehicle';
        } elseif ($model) {
            return $model;
        }
        
        return null;
    }

    /**
     * Build dimension string
     */
    private function buildDimensionString(array $data): ?string
    {
        $length = data_get($data, 'vehicle.dimensions.length_m');
        $width = data_get($data, 'vehicle.dimensions.width_m');
        $height = data_get($data, 'vehicle.dimensions.height_m');
        
        if ($length && $width && $height) {
            return "$length x $width x $height m";
        }
        
        return null;
    }

    /**
     * Get processing recommendation
     */
    private function getProcessingRecommendation(array $quotationFields, array $extractionData): array
    {
        $missingFields = array_keys(array_filter($quotationFields, fn($v) => !$v));
        $qualityScore = data_get($extractionData, 'final_validation.quality_score', 0);
        $completenessScore = data_get($extractionData, 'final_validation.completeness_score', 0);
        
        if (count($missingFields) <= 2 && $qualityScore >= 0.8) {
            return [
                'action' => 'auto_process',
                'confidence' => 'high',
                'message' => 'Ready for automatic quotation processing',
                'missing_fields' => $missingFields
            ];
        } elseif (count($missingFields) <= 4 && $qualityScore >= 0.7) {
            return [
                'action' => 'review_required',
                'confidence' => 'medium',
                'message' => 'Good extraction but requires manual review',
                'missing_fields' => $missingFields
            ];
        } else {
            return [
                'action' => 'manual_processing',
                'confidence' => 'low',
                'message' => 'Requires manual processing due to incomplete data',
                'missing_fields' => $missingFields
            ];
        }
    }

    /**
     * Clear processing cache
     */
    public static function clearCache(): void
    {
        self::$processedEmails = [];
    }
}
