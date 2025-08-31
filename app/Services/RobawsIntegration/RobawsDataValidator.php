<?php

namespace App\Services\RobawsIntegration;

final class RobawsDataValidator
{
    public static function validate(array $data): array
    {
        $errors = [];
        $warnings = [];
        
        // Get required fields from config
        $required = config('robaws.validation.required_fields', ['customer', 'por', 'pod', 'cargo']);
        foreach ($required as $field) {
            if (empty($data[$field] ?? null)) {
                $errors[] = "Missing required field: {$field}";
            }
        }
        
        // Get recommended fields from config
        $recommended = config('robaws.validation.recommended_fields', ['client_email', 'customer_reference', 'dim_bef_delivery']);
        foreach ($recommended as $field) {
            if (empty($data[$field] ?? null)) {
                $warnings[] = "Missing recommended field: {$field}";
            }
        }
        
        // Email validation
        if (!empty($data['client_email'])) {
            $strictValidation = config('robaws.validation.strict_email_validation', true);
            if ($strictValidation && !filter_var($data['client_email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Invalid email format: {$data['client_email']}";
            }
        }
        
        // Routing validation
        if (empty($data['por']) && empty($data['pod'])) {
            $errors[] = "Origin and destination are required";
        }
        
        // Cargo validation
        if (empty($data['cargo']) || $data['cargo'] === '1 x Vehicle') {
            $warnings[] = "Generic cargo description detected";
        }
        
        // Client ID validation
        if (empty($data['clientId'])) {
            $errors[] = "Missing client ID";
        }
        
        return [
            'is_valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'score' => self::calculateScore($data, $errors, $warnings),
        ];
    }
    
    /**
     * Calculate a quality score for the mapped data (0-100)
     */
    public static function calculateScore(array $data, array $errors, array $warnings): int
    {
        $score = 100;
        
        // Deduct points for errors (more severe)
        $score -= count($errors) * 20;
        
        // Deduct points for warnings (less severe)
        $score -= count($warnings) * 5;
        
        // Bonus points for having optional enrichment fields
        $bonusFields = ['vehicle_brand', 'vehicle_model', 'vehicle_year', 'dimensions', 'special_requirements'];
        foreach ($bonusFields as $field) {
            if (!empty($data[$field])) {
                $score += 2;
            }
        }
        
        return max(0, min(100, $score));
    }
    
    /**
     * Quick validation check for export readiness
     */
    public static function isReadyForExport(array $data): bool
    {
        $validation = self::validate($data);
        return $validation['is_valid'] && $validation['score'] >= 70;
    }
    
    /**
     * Get validation summary as a human-readable string
     */
    public static function getSummary(array $data): string
    {
        $validation = self::validate($data);
        
        $parts = [];
        $parts[] = $validation['is_valid'] ? '✅ Valid' : '❌ Invalid';
        $parts[] = "Score: {$validation['score']}/100";
        
        if (!empty($validation['errors'])) {
            $parts[] = count($validation['errors']) . ' error(s)';
        }
        
        if (!empty($validation['warnings'])) {
            $parts[] = count($validation['warnings']) . ' warning(s)';
        }
        
        return implode(' | ', $parts);
    }
}
