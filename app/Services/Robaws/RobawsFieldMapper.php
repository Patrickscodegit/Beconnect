<?php

namespace App\Services\Robaws;

use Illuminate\Support\Facades\Log;

/**
 * Robust field mapping service for Robaws API extraFields
 * 
 * Handles field name variations (spaces vs underscores, case differences)
 * and provides fallback mechanisms for field extraction.
 */
class RobawsFieldMapper
{
    /**
     * Field name mapping with variations
     * Each key is the canonical field name, values are possible variations
     */
    private const FIELD_MAPPINGS = [
        'parent_item' => [
            'PARENT ITEM',
            'PARENT_ITEM',
            'PARENTITEM',
            'IS_PARENT_ITEM',
        ],
        'shipping_line' => [
            'SHIPPING LINE',
            'SHIPPING_LINE',
            'SHIPPINGLINE',
        ],
        'transport_mode' => [
            'TRANSPORT MODE',
            'TRANSPORT_MODE',
            'SERVICE TYPE',
            'SERVICE_TYPE',
            'SERVICETYPE',
        ],
        'pol_terminal' => [
            'POL TERMINAL',
            'POL_TERMINAL',
            'POLTERMINAL',
        ],
        'update_date' => [
            'UPDATE DATE',
            'UPDATE_DATE',
            'UPDATEDATE',
        ],
        'validity_date' => [
            'VALIDITY DATE',
            'VALIDITY_DATE',
            'VALIDITYDATE',
        ],
        'info' => [
            'INFO',
            'ARTICLE INFO',
            'ARTICLE_INFO',
        ],
        'pol' => [
            'POL',
            'PORT OF LOADING',
            'PORT_OF_LOADING',
        ],
        'por' => [
            'POR',
            'POINT OF RECEIPT',
            'POINT_OF_RECEIPT',
        ],
        'pod' => [
            'POD',
            'PORT OF DISCHARGE',
            'PORT_OF_DISCHARGE',
        ],
        'fdest' => [
            'FDEST',
            'FINAL DESTINATION',
            'FINAL_DESTINATION',
        ],
        'type' => [
            'TYPE',
            'COMMODITY TYPE',
            'COMMODITY_TYPE',
        ],
        'article_type' => [
            'ARTICLE TYPE',
            'ARTICLE_TYPE',
            'ARTICLETYPE',
        ],
        'cost_side' => [
            'COST SIDE',
            'COST_SIDE',
            'COSTSIDE',
        ],
        'is_mandatory' => [
            'IS MANDATORY',
            'IS_MANDATORY',
            'MANDATORY',
        ],
        'mandatory_condition' => [
            'MANDATORY CONDITION',
            'MANDATORY_CONDITION',
            'MANDATORYCONDITION',
        ],
        'notes' => [
            'NOTES',
            'NOTE',
            'INTERNAL NOTES',
            'INTERNAL_NOTES',
        ],
        'pol_code' => [
            'POL CODE',
            'POL_CODE',
        ],
        'pod_code' => [
            'POD CODE',
            'POD_CODE',
        ],
    ];

    /**
     * Find a field value in extraFields using flexible matching
     * 
     * @param array $extraFields The extraFields array from Robaws API
     * @param string $canonicalField The canonical field name (e.g., 'parent_item')
     * @return mixed|null The field value or null if not found
     */
    public function findFieldValue(array $extraFields, string $canonicalField)
    {
        $variations = self::FIELD_MAPPINGS[$canonicalField] ?? [$canonicalField];
        
        foreach ($variations as $fieldName) {
            if (isset($extraFields[$fieldName])) {
                $field = $extraFields[$fieldName];
                
                // Extract value from different Robaws API formats
                $value = $field['stringValue'] 
                       ?? $field['booleanValue'] 
                       ?? $field['numberValue']
                       ?? $field['value'] 
                       ?? null;
                
                if ($value !== null) {
                    Log::debug('Found field using variation', [
                        'canonical' => $canonicalField,
                        'found_as' => $fieldName,
                        'value' => $value
                    ]);
                    
                    return $value;
                }
            }
        }
        
        // Log when field is not found (for debugging)
        Log::debug('Field not found in extraFields', [
            'canonical' => $canonicalField,
            'tried_variations' => $variations,
            'available_fields' => array_keys($extraFields)
        ]);
        
        return null;
    }

    /**
     * Check if a field exists in extraFields using flexible matching
     * 
     * @param array $extraFields The extraFields array from Robaws API
     * @param string $canonicalField The canonical field name
     * @return bool True if field exists, false otherwise
     */
    public function hasField(array $extraFields, string $canonicalField): bool
    {
        $variations = self::FIELD_MAPPINGS[$canonicalField] ?? [$canonicalField];
        
        foreach ($variations as $fieldName) {
            if (isset($extraFields[$fieldName])) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get the actual field name that was found in extraFields
     * 
     * @param array $extraFields The extraFields array from Robaws API
     * @param string $canonicalField The canonical field name
     * @return string|null The actual field name found, or null if not found
     */
    public function getActualFieldName(array $extraFields, string $canonicalField): ?string
    {
        $variations = self::FIELD_MAPPINGS[$canonicalField] ?? [$canonicalField];
        
        foreach ($variations as $fieldName) {
            if (isset($extraFields[$fieldName])) {
                return $fieldName;
            }
        }
        
        return null;
    }

    /**
     * Extract boolean value (for checkboxes like PARENT ITEM)
     * 
     * @param array $extraFields The extraFields array from Robaws API
     * @param string $canonicalField The canonical field name
     * @return bool|null The boolean value or null if not found
     */
    public function getBooleanValue(array $extraFields, string $canonicalField): ?bool
    {
        $value = $this->findFieldValue($extraFields, $canonicalField);
        
        if ($value === null) {
            return null;
        }
        
        // Handle various boolean representations from Robaws
        if (is_bool($value)) {
            return $value;
        }
        
        if (is_numeric($value)) {
            return (bool) ((int) $value);
        }
        
        if (is_string($value)) {
            $lower = strtolower(trim($value));
            return in_array($lower, ['true', 'yes', '1', 'on']);
        }
        
        return false;
    }

    /**
     * Extract string value
     * 
     * @param array $extraFields The extraFields array from Robaws API
     * @param string $canonicalField The canonical field name
     * @return string|null The string value or null if not found
     */
    public function getStringValue(array $extraFields, string $canonicalField): ?string
    {
        $value = $this->findFieldValue($extraFields, $canonicalField);
        
        if ($value === null) {
            return null;
        }
        
        return (string) $value;
    }

    /**
     * Get all available field mappings for debugging
     * 
     * @return array The complete field mappings
     */
    public function getFieldMappings(): array
    {
        return self::FIELD_MAPPINGS;
    }
}
