<?php

namespace App\Services\Robaws;

use App\Models\RobawsArticleCache;
use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class RobawsArticlePushService
{
    private RobawsApiClient $apiClient;
    
    private function debugLog(string $location, string $message, array $data = []): void
    {
        // Use Laravel Log facade for production compatibility
        Log::debug($message, array_merge(['location' => $location], $data));
    }
    
    /**
     * Field mapping configuration for Robaws extraFields
     */
    private const FIELD_MAPPING = [
        'update_date' => [
            'robaws_field' => 'UPDATE DATE',
            'type' => 'TEXT',
            'group' => 'IMPORTANT INFO',
            'getter' => 'effective_update_date',
            'formatter' => 'format_mdy',
        ],
        'validity_date' => [
            'robaws_field' => 'VALIDITY DATE',
            'type' => 'TEXT',
            'group' => 'IMPORTANT INFO',
            'getter' => 'effective_validity_date',
            'formatter' => 'format_mdy',
        ],
        // Note: SALES PRICE removed - price is stored in main article 'price' field, not extraFields
        'shipping_line' => [
            'robaws_field' => 'SHIPPING LINE',
            'type' => 'SELECT',
            'group' => 'ARTICLE INFO',
            'getter' => 'shippingCarrier.name',
            'formatter' => 'string',
            'fallback' => 'shipping_line',
        ],
        'service_type' => [
            'robaws_field' => 'SERVICE TYPE',
            'type' => 'SELECT',
            'group' => 'ARTICLE INFO',
            'getter' => 'service_type',
            'formatter' => 'string',
        ],
        'pol_terminal' => [
            'robaws_field' => 'POL TERMINAL',
            'type' => 'SELECT',
            'group' => 'ARTICLE INFO',
            'getter' => 'pol_terminal',
            'formatter' => 'string',
        ],
        'pol' => [
            'robaws_field' => 'POL',
            'type' => 'SELECT',
            'group' => 'ARTICLE INFO',
            'getter' => 'polPort.formatFull',
            'formatter' => 'string',
            'fallback' => 'pol',
        ],
        'pod' => [
            'robaws_field' => 'POD',
            'type' => 'SELECT',
            'group' => 'ARTICLE INFO',
            'getter' => 'podPort.formatFull',
            'formatter' => 'string',
            'fallback' => 'pod',
        ],
        'parent_item' => [
            'robaws_field' => 'PARENT ITEM',
            'type' => 'CHECKBOX',
            'group' => 'ARTICLE INFO',
            'getter' => 'is_parent_item',
            'formatter' => 'boolean',
        ],
        'cost_side' => [
            'robaws_field' => 'COST SIDE',
            'type' => 'SELECT',
            'group' => 'ARTICLE INFO',
            'getter' => 'cost_side',
            'formatter' => 'string_upper',
        ],
        'article_type' => [
            'robaws_field' => 'ARTICLE TYPE',
            'type' => 'SELECT',
            'group' => 'ARTICLE INFO',
            'getter' => 'article_type',
            'formatter' => 'string',
        ],
        'transport_mode' => [
            'robaws_field' => 'TRANSPORT MODE',
            'type' => 'SELECT',
            'group' => 'ARTICLE INFO',
            'getter' => 'transport_mode',
            'formatter' => 'string',
        ],
        'pol_code' => [
            'robaws_field' => 'POL CODE',
            'type' => 'SELECT',
            'group' => 'ARTICLE INFO',
            'getter' => 'pol_code',
            'formatter' => 'string',
        ],
        'pod_code' => [
            'robaws_field' => 'POD CODE',
            'type' => 'SELECT',
            'group' => 'ARTICLE INFO',
            'getter' => 'pod_code',
            'formatter' => 'string',
        ],
        'is_mandatory' => [
            'robaws_field' => 'IS MANDATORY',
            'type' => 'CHECKBOX',
            'group' => 'ARTICLE INFO',
            'getter' => 'is_mandatory',
            'formatter' => 'boolean',
        ],
        'mandatory_condition' => [
            'robaws_field' => 'MANDATORY CONDITION',
            'type' => 'TEXT',
            'group' => 'ARTICLE INFO',
            'getter' => 'mandatory_condition',
            'formatter' => 'string',
        ],
        'notes' => [
            'robaws_field' => 'NOTES',
            'type' => 'TEXT',
            'group' => 'ARTICLE INFO',
            'getter' => 'notes',
            'formatter' => 'string',
        ],
        'article_info' => [
            'robaws_field' => 'INFO',
            'type' => 'TEXT',
            'group' => 'IMPORTANT INFO',
            'getter' => 'article_info',
            'formatter' => 'string',
        ],
        'purchase_price' => [
            'robaws_field' => 'PURCHASE PRICE',
            'type' => 'TEXT',
            'group' => 'IMPORTANT INFO',
            'getter' => 'purchase_price_breakdown',
            'formatter' => 'format_purchase_price_breakdown',
        ],
        'max_dimensions' => [
            'robaws_field' => 'MAX DIM AND WEIGHT',
            'type' => 'TEXT',
            'group' => 'IMPORTANT INFO',
            'getter' => 'max_dimensions_breakdown',
            'formatter' => 'format_max_dimensions_breakdown',
        ],
    ];

    public function __construct(RobawsApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
    }

    /**
     * Main article fields mapping (fields that go in the main article payload, not extraFields)
     */
    private const MAIN_ARTICLE_FIELDS = [
        'unit_price' => [
            'robaws_field' => 'salePrice',
            'label' => 'Unit Price',
            'group' => 'PRICING',
        ],
        'cost_price' => [
            'robaws_field' => 'costPrice',
            'label' => 'Cost Price',
            'group' => 'PRICING',
        ],
    ];

    /**
     * Get list of pushable fields with metadata
     */
    public function getPushableFields(): array
    {
        $fields = [];
        
        // Add main article fields
        foreach (self::MAIN_ARTICLE_FIELDS as $key => $config) {
            $fields[] = [
                'key' => $key,
                'label' => $config['label'],
                'robaws_field' => $config['robaws_field'],
                'type' => 'MAIN_FIELD',
                'group' => $config['group'],
                'is_main_field' => true,
            ];
        }
        
        // Add extraFields
        foreach (array_keys(self::FIELD_MAPPING) as $key) {
            $config = self::FIELD_MAPPING[$key];
            $fields[] = [
                'key' => $key,
                'label' => $this->getFieldLabel($key),
                'robaws_field' => $config['robaws_field'],
                'type' => $config['type'],
                'group' => $config['group'],
                'is_main_field' => false,
            ];
        }
        
        return $fields;
    }

    /**
     * Get human-readable label for field key
     */
    private function getFieldLabel(string $key): string
    {
        // Check main article fields first
        if (isset(self::MAIN_ARTICLE_FIELDS[$key])) {
            return self::MAIN_ARTICLE_FIELDS[$key]['label'];
        }
        
        $labels = [
            'update_date' => 'Update Date',
            'validity_date' => 'Validity Date',
            'shipping_line' => 'Shipping Line',
            'service_type' => 'Service Type',
            'pol_terminal' => 'POL Terminal',
            'pol' => 'POL',
            'pod' => 'POD',
            'parent_item' => 'Parent Item',
            'cost_side' => 'Cost Side',
            'article_type' => 'Article Type',
            'transport_mode' => 'Transport Mode',
            'pol_code' => 'POL Code',
            'pod_code' => 'POD Code',
            'is_mandatory' => 'Is Mandatory',
            'mandatory_condition' => 'Mandatory Condition',
            'notes' => 'Notes',
            'article_info' => 'Article Info',
            'unit_price' => 'Unit Price',
            'purchase_price' => 'Purchase Price',
            'max_dimensions' => 'Max Dimensions & Weight',
        ];

        return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * Build extraFields payload for Robaws API
     */
    public function buildExtraFieldsPayload(RobawsArticleCache $article, array $fieldsToPush): array
    {
        // #region agent log
        $this->debugLog('RobawsArticlePushService.php:194', 'buildExtraFieldsPayload entry', [
            'article_id' => $article->id,
            'robaws_article_id' => $article->robaws_article_id,
            'fields_to_push' => $fieldsToPush,
            'hypothesisId' => 'A,B,C',
        ]);
        // #endregion
        
        $extraFields = [];

        foreach ($fieldsToPush as $fieldKey) {
            if (!isset(self::FIELD_MAPPING[$fieldKey])) {
                Log::warning("Unknown field key for push: {$fieldKey}");
                continue;
            }

            $config = self::FIELD_MAPPING[$fieldKey];
            $value = $this->getFieldValue($article, $config);

            // #region agent log
            $this->debugLog('RobawsArticlePushService.php:205', 'Field value retrieved', [
                'field_key' => $fieldKey,
                'robaws_field' => $config['robaws_field'],
                'raw_value' => $value,
                'value_type' => gettype($value),
                'is_null' => $value === null,
                'is_empty_string' => $value === '',
                'hypothesisId' => 'A',
            ]);
            // #endregion

            if ($value === null && !isset($config['allow_null'])) {
                // #region agent log
                $this->debugLog('RobawsArticlePushService.php:207', 'Skipping null field', [
                    'field_key' => $fieldKey,
                    'robaws_field' => $config['robaws_field'],
                    'hypothesisId' => 'A',
                ]);
                // #endregion
                continue; // Skip null values unless explicitly allowed
            }

            $fieldData = [
                'type' => $config['type'],
            ];

            // Add group if specified
            // SELECT fields DO need group when updating (see PushRobawsCostSide for reference)
            if (isset($config['group'])) {
                $fieldData['group'] = $config['group'];
            }

            // Format value based on type
            switch ($config['formatter']) {
                case 'format_mdy':
                    if ($value instanceof Carbon) {
                        $fieldData['stringValue'] = $value->format('m/d/Y');
                    } elseif (is_string($value)) {
                        try {
                            $date = Carbon::parse($value);
                            $fieldData['stringValue'] = $date->format('m/d/Y');
                        } catch (\Exception $e) {
                            Log::warning("Invalid date format for {$fieldKey}: {$value}");
                            continue 2; // Skip this field
                        }
                    }
                    break;

                case 'float':
                case 'number':
                    $floatValue = (float) $value;
                    // Skip invalid numbers (NaN, infinite, or empty string coerced to 0)
                    if (!is_finite($floatValue) || ($value === '' && $floatValue === 0.0)) {
                        continue 2; // Skip this field
                    }
                    // Robaws API expects decimalValue or integerValue, not numberValue
                    // Use decimalValue if it has a decimal point, otherwise integerValue
                    if (is_float($floatValue) || strpos((string) $value, '.') !== false) {
                        $fieldData['decimalValue'] = $floatValue;
                    } else {
                        $fieldData['integerValue'] = (int) $floatValue;
                    }
                    break;

                case 'boolean':
                    // For checkboxes, we need to handle both true and false
                    // Checkboxes that are false might need to be unset in Robaws (if currently true)
                    // We'll build the field data here and let the comparison logic decide
                    if ((bool) $value === false) {
                        // For false checkboxes, we still need to check if they're true in Robaws
                        // If they're true in Robaws but false in Bconnect, we need to unset them
                        // Store false value for comparison - we'll handle sending it later
                        $fieldData['booleanValue'] = false;
                    } else {
                        $fieldData['booleanValue'] = true;
                    }
                    break;

                case 'string_upper':
                    $stringValue = strtoupper(trim((string) $value));
                    // Skip empty strings unless explicitly allowed
                    if (empty($stringValue) && !isset($config['allow_empty'])) {
                        continue 2; // Skip this field
                    }
                    $fieldData['stringValue'] = $stringValue;
                    break;

                case 'format_purchase_price_breakdown':
                    $text = $this->formatPurchasePriceBreakdown($value);
                    if (empty($text)) {
                        continue 2; // Skip if no content
                    }
                    $fieldData['stringValue'] = $text;
                    break;

                case 'format_max_dimensions_breakdown':
                    $text = $this->formatMaxDimensionsBreakdown($value);
                    if (empty($text)) {
                        continue 2; // Skip if no content
                    }
                    $fieldData['stringValue'] = $text;
                    break;

                case 'string':
                default:
                    $stringValue = trim((string) $value);
                    // Skip empty strings unless explicitly allowed
                    if (empty($stringValue) && !isset($config['allow_empty'])) {
                        continue 2; // Skip this field
                    }
                    $fieldData['stringValue'] = $stringValue;
                    break;
            }

            // #region agent log
            $this->debugLog('RobawsArticlePushService.php:276', 'Field added to payload', [
                'field_key' => $fieldKey,
                'robaws_field' => $config['robaws_field'],
                'field_data' => $fieldData,
                'hypothesisId' => 'B',
            ]);
            // #endregion

            $extraFields[$config['robaws_field']] = $fieldData;
        }

        // #region agent log
        $this->debugLog('RobawsArticlePushService.php:283', 'buildExtraFieldsPayload exit', [
            'article_id' => $article->id,
            'extra_fields_count' => count($extraFields),
            'extra_fields_keys' => array_keys($extraFields),
            'hypothesisId' => 'C',
        ]);
        // #endregion

        return $extraFields;
    }

    /**
     * Build main article payload for Robaws API
     * Main article fields go at the root level (not in extraFields)
     * 
     * @param RobawsArticleCache $article
     * @param array $fieldsToPush Array of field keys to push
     * @return array Main article payload (e.g., ['salePrice' => 100.00])
     */
    private function buildMainArticlePayload(RobawsArticleCache $article, array $fieldsToPush): array
    {
        $mainFields = [];
        
        // Filter to only main article fields
        $mainFieldsToPush = array_intersect($fieldsToPush, array_keys(self::MAIN_ARTICLE_FIELDS));
        
        foreach ($mainFieldsToPush as $fieldKey) {
            $config = self::MAIN_ARTICLE_FIELDS[$fieldKey];
            $robawsField = $config['robaws_field'];
            
            // Get value from article
            $value = $article->$fieldKey;
            
            // Handle unit_price → salePrice and cost_price → costPrice
            if ($fieldKey === 'unit_price' || $fieldKey === 'cost_price') {
                // Allow 0, but skip null
                if ($value === null) {
                    Log::warning("{$fieldKey} is null, skipping from push", [
                        'article_id' => $article->id,
                        'robaws_article_id' => $article->robaws_article_id,
                    ]);
                    continue;
                }
                
                // Ensure it's a float
                $floatValue = (float) $value;
                $mainFields[$robawsField] = $floatValue;
            }
        }
        
        return $mainFields;
    }

    /**
     * Get field value from article using getter configuration
     */
    private function getFieldValue(RobawsArticleCache $article, array $config)
    {
        $getter = $config['getter'];

        // Handle relationship access (e.g., 'shippingCarrier.name', 'polPort.formatFull')
        if (str_contains($getter, '.')) {
            [$relation, $attribute] = explode('.', $getter, 2);
            
            if ($relation === 'shippingCarrier') {
                $carrier = $article->shippingCarrier;
                if ($carrier) {
                    return $carrier->$attribute;
                }
                
                // Fallback to shipping_line if carrier not linked
                if (isset($config['fallback'])) {
                    return $article->{$config['fallback']};
                }
                
                return null;
            }
            
            if ($relation === 'polPort') {
                $port = $article->polPort;
                if ($port) {
                    // Handle method calls like 'formatFull'
                    if (method_exists($port, $attribute)) {
                        return $port->$attribute();
                    }
                    // Handle attribute access
                    return $port->$attribute;
                }
                
                // Fallback to pol column if port not linked
                if (isset($config['fallback'])) {
                    return $article->{$config['fallback']};
                }
                
                return null;
            }
            
            if ($relation === 'podPort') {
                $port = $article->podPort;
                if ($port) {
                    // Handle method calls like 'formatFull'
                    if (method_exists($port, $attribute)) {
                        return $port->$attribute();
                    }
                    // Handle attribute access
                    return $port->$attribute;
                }
                
                // Fallback to pod column if port not linked
                if (isset($config['fallback'])) {
                    return $article->{$config['fallback']};
                }
                
                return null;
            }
        }

        // Handle accessor methods (e.g., 'effective_update_date')
        if (method_exists($article, 'get' . str_replace('_', '', ucwords($getter, '_')) . 'Attribute')) {
            return $article->$getter;
        }

        // Direct attribute access
        return $article->$getter ?? null;
    }

    /**
     * Format purchase price breakdown as readable text
     */
    private function formatPurchasePriceBreakdown($breakdown): string
    {
        if (empty($breakdown) || !is_array($breakdown)) {
            return '';
        }

        $lines = [];
        $currency = $breakdown['currency'] ?? 'EUR';
        
        // Total
        if (isset($breakdown['total'])) {
            $total = number_format((float) $breakdown['total'], 2, '.', '');
            $lines[] = "Total: {$currency} {$total}";
            $lines[] = ''; // Empty line
        }
        
        // Base Freight
        $baseFreight = $breakdown['base_freight'] ?? null;
        if ($baseFreight && isset($baseFreight['amount'])) {
            $amount = number_format((float) $baseFreight['amount'], 2, '.', '');
            $unit = $baseFreight['unit'] ?? 'LUMPSUM';
            $lines[] = "Base Freight: {$currency} {$amount} ({$unit})";
            $lines[] = ''; // Empty line
        }
        
        // Surcharges
        $surcharges = $breakdown['surcharges'] ?? [];
        if (!empty($surcharges)) {
            $lines[] = 'Surcharges:';
            $labels = [
                'baf' => 'BAF',
                'ets' => 'ETS',
                'port_additional' => 'Port Additional',
                'admin_fxe' => 'Admin Fee',
                'thc' => 'THC',
                'measurement_costs' => 'Measurement Costs',
                'congestion_surcharge' => 'Congestion Surcharge',
                'iccm' => 'ICCM',
            ];
            
            foreach ($surcharges as $key => $surcharge) {
                if (isset($surcharge['amount']) && $surcharge['amount'] > 0) {
                    $amount = number_format((float) $surcharge['amount'], 2, '.', '');
                    $unit = $surcharge['unit'] ?? 'LUMPSUM';
                    $label = $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
                    $lines[] = "- {$label}: {$currency} {$amount} ({$unit})";
                }
            }
            $lines[] = ''; // Empty line after surcharges
        }
        
        // Metadata
        if (!empty($breakdown['carrier_name'])) {
            $lines[] = "Carrier: {$breakdown['carrier_name']}";
        }
        
        if (!empty($breakdown['effective_from'])) {
            try {
                $date = Carbon::parse($breakdown['effective_from'])->format('d-m-Y');
                $lines[] = "Effective From: {$date}";
            } catch (\Exception $e) {
                $lines[] = "Effective From: {$breakdown['effective_from']}";
            }
        }
        
        if (!empty($breakdown['effective_to'])) {
            try {
                $date = Carbon::parse($breakdown['effective_to'])->format('d-m-Y');
                $lines[] = "Effective To: {$date}";
            } catch (\Exception $e) {
                $lines[] = "Effective To: {$breakdown['effective_to']}";
            }
        }
        
        if (!empty($breakdown['source'])) {
            $lines[] = "Source: " . ucfirst($breakdown['source']);
        }
        
        if (!empty($breakdown['update_date'])) {
            try {
                $date = Carbon::parse($breakdown['update_date'])->format('d-m-Y');
                $lines[] = "Update Date: {$date}";
            } catch (\Exception $e) {
                $lines[] = "Update Date: {$breakdown['update_date']}";
            }
        }
        
        if (!empty($breakdown['validity_date'])) {
            try {
                $date = Carbon::parse($breakdown['validity_date'])->format('d-m-Y');
                $lines[] = "Validity Date: {$date}";
            } catch (\Exception $e) {
                $lines[] = "Validity Date: {$breakdown['validity_date']}";
            }
        }
        
        return implode("\n", $lines);
    }

    /**
     * Format max dimensions breakdown as readable text
     */
    private function formatMaxDimensionsBreakdown($breakdown): string
    {
        if (empty($breakdown) || !is_array($breakdown)) {
            return '';
        }

        $lines = [];
        
        // Max Dimensions
        if (isset($breakdown['max_length_cm']) || isset($breakdown['max_width_cm']) || isset($breakdown['max_height_cm'])) {
            $dims = [];
            if (isset($breakdown['max_length_cm'])) {
                $dims[] = 'L: ' . number_format((float) $breakdown['max_length_cm'], 0, '.', '') . 'cm';
            }
            if (isset($breakdown['max_width_cm'])) {
                $dims[] = 'W: ' . number_format((float) $breakdown['max_width_cm'], 0, '.', '') . 'cm';
            }
            if (isset($breakdown['max_height_cm'])) {
                $dims[] = 'H: ' . number_format((float) $breakdown['max_height_cm'], 0, '.', '') . 'cm';
            }
            if (!empty($dims)) {
                $lines[] = 'Max Dimensions: ' . implode(' × ', $dims);
                $lines[] = ''; // Empty line
            }
        }
        
        // Max Weight
        if (isset($breakdown['max_weight_kg'])) {
            $weight = number_format((float) $breakdown['max_weight_kg'], 0, '.', '');
            $lines[] = "Max Weight: {$weight}kg";
            $lines[] = ''; // Empty line
        }
        
        // Max CBM
        if (isset($breakdown['max_cbm'])) {
            $cbm = number_format((float) $breakdown['max_cbm'], 2, '.', '');
            $lines[] = "Max CBM: {$cbm}";
            $lines[] = ''; // Empty line
        }
        
        // Metadata
        if (!empty($breakdown['carrier_name'])) {
            $lines[] = "Carrier: {$breakdown['carrier_name']}";
        }
        
        if (!empty($breakdown['port_name'])) {
            $lines[] = "Port: {$breakdown['port_name']}";
        }
        
        if (!empty($breakdown['vehicle_category'])) {
            $lines[] = "Vehicle Category: " . ucfirst(str_replace('_', ' ', $breakdown['vehicle_category']));
        }
        
        if (!empty($breakdown['effective_from'])) {
            try {
                $date = Carbon::parse($breakdown['effective_from'])->format('d-m-Y');
                $lines[] = "Effective From: {$date}";
            } catch (\Exception $e) {
                $lines[] = "Effective From: {$breakdown['effective_from']}";
            }
        }
        
        if (!empty($breakdown['effective_to'])) {
            try {
                $date = Carbon::parse($breakdown['effective_to'])->format('d-m-Y');
                $lines[] = "Effective To: {$date}";
            } catch (\Exception $e) {
                $lines[] = "Effective To: {$breakdown['effective_to']}";
            }
        }
        
        if (!empty($breakdown['update_date'])) {
            try {
                $date = Carbon::parse($breakdown['update_date'])->format('d-m-Y');
                $lines[] = "Update Date: {$date}";
            } catch (\Exception $e) {
                $lines[] = "Update Date: {$breakdown['update_date']}";
            }
        }
        
        if (!empty($breakdown['validity_date'])) {
            try {
                $date = Carbon::parse($breakdown['validity_date'])->format('d-m-Y');
                $lines[] = "Validity Date: {$date}";
            } catch (\Exception $e) {
                $lines[] = "Validity Date: {$breakdown['validity_date']}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Push single article to Robaws with validation and error handling
     */
    public function pushArticleToRobaws(RobawsArticleCache $article, array $fieldsToPush, int $sleepMs = 0, bool $retryOnFailure = false, int $maxRetries = 1): array
    {
        // #region agent log
        $this->debugLog('RobawsArticlePushService.php:326', 'pushArticleToRobaws entry', [
            'article_id' => $article->id,
            'robaws_article_id' => $article->robaws_article_id,
            'article_code' => $article->article_code,
            'fields_to_push' => $fieldsToPush,
            'hypothesisId' => 'A,B,C,D',
        ]);
        // #endregion
        
        // Validation
        if (!$article->robaws_article_id) {
            return [
                'success' => false,
                'error' => 'Article has no Robaws article ID',
                'article_code' => $article->article_code,
            ];
        }

        if (empty($fieldsToPush)) {
            return [
                'success' => false,
                'error' => 'No fields selected to push',
                'article_code' => $article->article_code,
            ];
        }

        // Validate field keys (allow both main article fields and extraFields)
        $validFieldKeys = array_merge(
            array_keys(self::FIELD_MAPPING),
            array_keys(self::MAIN_ARTICLE_FIELDS)
        );
        $invalidFields = array_diff($fieldsToPush, $validFieldKeys);
        if (!empty($invalidFields)) {
            return [
                'success' => false,
                'error' => 'Invalid field keys: ' . implode(', ', $invalidFields),
                'article_code' => $article->article_code,
            ];
        }

        // Separate main article fields from extraFields
        $mainArticleFields = array_intersect($fieldsToPush, array_keys(self::MAIN_ARTICLE_FIELDS));
        $extraFieldKeys = array_intersect($fieldsToPush, array_keys(self::FIELD_MAPPING));

        $attempt = 0;
        $lastError = null;

        while ($attempt <= $maxRetries) {
            try {
                // Build main article payload
                $mainArticlePayload = $this->buildMainArticlePayload($article, $mainArticleFields);
                
                // Build extraFields payload (only with extraFields, not main fields)
                $extraFields = [];
                if (!empty($extraFieldKeys)) {
                    $extraFields = $this->buildExtraFieldsPayload($article, $extraFieldKeys);
                }

                // Check if we have any fields to push
                if (empty($mainArticlePayload) && empty($extraFields)) {
                    return [
                        'success' => false,
                        'error' => 'No valid fields to push (all values are null or invalid)',
                        'article_code' => $article->article_code,
                    ];
                }

                // Validate payload structure
                foreach ($extraFields as $fieldName => $fieldData) {
                    if (!isset($fieldData['type'])) {
                        Log::warning("Missing type in extraField: {$fieldName}", ['field' => $fieldData]);
                    }
                }

                // Fetch current article state from Robaws for comparison
                $currentArticle = null;
                $currentExtraFields = null;
                try {
                    $currentResponse = $this->apiClient->getArticle($article->robaws_article_id, ['extraFields']);
                    if ($currentResponse['success'] ?? false) {
                        $currentArticle = $currentResponse['data'] ?? null;
                        $currentExtraFields = $currentArticle['extraFields'] ?? [];
                        // #region agent log
                        $this->debugLog('RobawsArticlePushService.php:385', 'Current Robaws article fetched', [
                            'article_id' => $article->id,
                            'current_extraFields_keys' => array_keys($currentExtraFields ?? []),
                            'current_extraFields' => $currentExtraFields,
                            'current_salePrice' => $currentArticle['salePrice'] ?? null,
                            'hypothesisId' => 'D',
                        ]);
                        // #endregion
                    }
                } catch (\Exception $e) {
                    // #region agent log
                    $this->debugLog('RobawsArticlePushService.php:389', 'Failed to fetch current article', [
                        'article_id' => $article->id,
                        'error' => $e->getMessage(),
                        'hypothesisId' => 'D',
                    ]);
                    // #endregion
                    // Ignore fetch errors
                }
                
                // Filter main article fields by checking for changes
                $filteredMainArticlePayload = [];
                if (!empty($mainArticlePayload)) {
                    foreach ($mainArticlePayload as $robawsField => $newValue) {
                        $currentValue = $currentArticle[$robawsField] ?? null;
                        
                        // Compare values (handle float comparison)
                        $changed = false;
                        if ($robawsField === 'salePrice' || $robawsField === 'costPrice') {
                            if ($currentValue === null) {
                                // If current value is null but new value is not, it's a change
                                $changed = $newValue !== null;
                            } else {
                                $currentFloat = (float) $currentValue;
                                $newFloat = (float) $newValue;
                                $changed = abs($currentFloat - $newFloat) > 0.01; // Allow for floating point precision
                            }
                        } else {
                            $changed = $currentValue !== $newValue;
                        }
                        
                        if ($changed) {
                            $filteredMainArticlePayload[$robawsField] = $newValue;
                        }
                    }
                }
                
                // Filter out fields that are already set to the same value in Robaws
                // CRITICAL: SELECT fields that don't exist in Robaws cannot be created - Robaws silently rejects them
                // Only push SELECT fields if they already exist in Robaws (can be updated) or if we're updating an existing value
                $filteredExtraFields = [];
                foreach ($extraFields as $fieldName => $fieldData) {
                    // Check if field exists in Robaws (key exists, even if value is null)
                    $fieldExistsInRobaws = array_key_exists($fieldName, $currentExtraFields);
                    $currentField = $currentExtraFields[$fieldName] ?? null;
                    
                    // For SELECT fields that don't exist in Robaws (key doesn't exist), skip them
                    // Robaws requires SELECT field values to be predefined options - you can't create new ones via API
                    // TEXT and CHECKBOX fields can be created if they don't exist
                    // Note: A SELECT field can exist in Robaws with a null value, so we check key existence, not value null
                    if ($fieldData['type'] === 'SELECT' && !$fieldExistsInRobaws) {
                        // #region agent log
                        $this->debugLog('RobawsArticlePushService.php:489', 'Skipping SELECT field (does not exist in Robaws)', [
                            'field_name' => $fieldName,
                            'field_type' => $fieldData['type'],
                            'new_value' => $fieldData['stringValue'] ?? null,
                            'field_exists_in_robaws' => $fieldExistsInRobaws,
                            'current_field_value' => $currentField,
                            'hypothesisId' => 'E',
                        ]);
                        // #endregion
                        continue;
                    }
                    
                    $currentValue = null;
                    $newValue = null;
                    
                    // Extract current value
                    if ($currentField && isset($currentField['stringValue'])) {
                        $currentValue = $currentField['stringValue'];
                    } elseif ($currentField && isset($currentField['decimalValue'])) {
                        $currentValue = $currentField['decimalValue'];
                    } elseif ($currentField && isset($currentField['integerValue'])) {
                        $currentValue = $currentField['integerValue'];
                    } elseif ($currentField && isset($currentField['numberValue'])) {
                        // Fallback for old format
                        $currentValue = $currentField['numberValue'];
                    } elseif ($currentField && isset($currentField['booleanValue'])) {
                        $currentValue = $currentField['booleanValue'];
                    }
                    
                    // Extract new value
                    if (isset($fieldData['stringValue'])) {
                        $newValue = $fieldData['stringValue'];
                    } elseif (isset($fieldData['decimalValue'])) {
                        $newValue = $fieldData['decimalValue'];
                    } elseif (isset($fieldData['integerValue'])) {
                        $newValue = $fieldData['integerValue'];
                    } elseif (isset($fieldData['numberValue'])) {
                        // Fallback for old format
                        $newValue = $fieldData['numberValue'];
                    } elseif (isset($fieldData['booleanValue'])) {
                        $newValue = $fieldData['booleanValue'];
                    }
                    
                    // Special handling for CHECKBOX fields
                    // If checkbox is false in Bconnect, check if it needs to be unset in Robaws
                    if ($fieldData['type'] === 'CHECKBOX' && isset($fieldData['booleanValue']) && $fieldData['booleanValue'] === false) {
                        // Checkbox is false in Bconnect
                        if ($currentValue === true) {
                            // Checkbox is true in Robaws but false in Bconnect - attempt to unset it
                            // NOTE: Robaws API may reject booleanValue: false, but we'll try anyway
                            // The API call will fail if it doesn't accept false, and we'll log the error
                            // #region agent log
                            $this->debugLog('RobawsArticlePushService.php:595', 'Checkbox needs to be unchecked (true→false)', [
                                'field_name' => $fieldName,
                                'current_value_robaws' => $currentValue,
                                'new_value_bconnect' => $newValue,
                                'will_attempt' => true,
                                'hypothesisId' => 'F',
                            ]);
                            // #endregion
                            // Include it to attempt unsetting - let the API tell us if it rejects
                            $filteredExtraFields[$fieldName] = $fieldData;
                            continue;
                        } else {
                            // Checkbox is false in both - no change needed
                            // #region agent log
                            $this->debugLog('RobawsArticlePushService.php:605', 'Checkbox filtered out (false in both)', [
                                'field_name' => $fieldName,
                                'current_value' => $currentValue,
                                'new_value' => $newValue,
                                'hypothesisId' => 'D',
                            ]);
                            // #endregion
                            continue;
                        }
                    }
                    
                    // Only include if value changed or field doesn't exist in Robaws (for non-SELECT fields)
                    if ($currentValue !== $newValue || $currentField === null) {
                        $filteredExtraFields[$fieldName] = $fieldData;
                        // #region agent log
                        $this->debugLog('RobawsArticlePushService.php:618', 'Field included (changed or new)', [
                            'field_name' => $fieldName,
                            'field_type' => $fieldData['type'],
                            'current_value' => $currentValue,
                            'new_value' => $newValue,
                            'current_field_exists' => $currentField !== null,
                            'hypothesisId' => 'D',
                        ]);
                        // #endregion
                    } else {
                        // #region agent log
                        $this->debugLog('RobawsArticlePushService.php:628', 'Field filtered out (unchanged)', [
                            'field_name' => $fieldName,
                            'current_value' => $currentValue,
                            'new_value' => $newValue,
                            'values_match' => $currentValue === $newValue,
                            'hypothesisId' => 'D',
                        ]);
                        // #endregion
                    }
                }
                
                // Check if we have any fields to push after filtering
                if (empty($filteredMainArticlePayload) && empty($filteredExtraFields)) {
                    // #region agent log
                    $this->debugLog('RobawsArticlePushService.php:450', 'No fields to update - all unchanged', [
                        'article_id' => $article->id,
                        'original_extraFields_count' => count($extraFields),
                        'filtered_extraFields_count' => count($filteredExtraFields),
                        'original_mainFields_count' => count($mainArticlePayload),
                        'filtered_mainFields_count' => count($filteredMainArticlePayload),
                        'hypothesisId' => 'D',
                    ]);
                    // #endregion
                    return [
                        'success' => false,
                        'error' => 'No fields to update (all values match current state in Robaws)',
                        'article_code' => $article->article_code,
                    ];
                }
                
                // Build combined payload
                $payload = [];
                if (!empty($filteredMainArticlePayload)) {
                    $payload = array_merge($payload, $filteredMainArticlePayload);
                }
                if (!empty($filteredExtraFields)) {
                    $payload['extraFields'] = $filteredExtraFields;
                }
                
                // Enhanced logging for production debugging
                Log::info('Robaws push payload structure', [
                    'article_id' => $article->id,
                    'robaws_article_id' => $article->robaws_article_id,
                    'article_code' => $article->article_code,
                    'payload_structure' => $payload,
                    'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                    'main_fields_count' => count($filteredMainArticlePayload),
                    'main_fields_keys' => array_keys($filteredMainArticlePayload),
                    'filtered_extraFields_count' => count($filteredExtraFields),
                    'filtered_extraFields_keys' => array_keys($filteredExtraFields),
                    'filtered_extraFields_detail' => $filteredExtraFields,
                ]);
                
                // #region agent log
                $this->debugLog('RobawsArticlePushService.php:465', 'Payload before API call', [
                    'article_id' => $article->id,
                    'payload' => $payload,
                    'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'main_fields_count' => count($filteredMainArticlePayload),
                    'main_fields_keys' => array_keys($filteredMainArticlePayload),
                    'filtered_extraFields_count' => count($filteredExtraFields),
                    'filtered_extraFields_keys' => array_keys($filteredExtraFields),
                    'hypothesisId' => 'C,D',
                ]);
                // #endregion
                
                // Rate limiting: check if we need to wait
                // The RobawsApiClient handles rate limiting internally, but we add additional delay here
                if ($sleepMs > 0 && $attempt === 0) {
                    usleep($sleepMs * 1000);
                }

                // Make API call
                $response = $this->apiClient->updateArticle($article->robaws_article_id, $payload);
                
                // Enhanced error logging
                if (!($response['success'] ?? false)) {
                    Log::error('Robaws push API call failed', [
                        'article_id' => $article->id,
                        'robaws_article_id' => $article->robaws_article_id,
                        'status' => $response['status'] ?? null,
                        'error' => $response['error'] ?? null,
                        'response_body' => $response['body'] ?? null,
                        'payload_sent' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ]);
                }

                // #region agent log
                $this->debugLog('RobawsArticlePushService.php:475', 'API response received', [
                    'article_id' => $article->id,
                    'response_success' => $response['success'] ?? false,
                    'response_status' => $response['status'] ?? null,
                    'response_error' => $response['error'] ?? null,
                    'response_body' => $response['body'] ?? null,
                    'hypothesisId' => 'A,B,C,D,E',
                ]);
                // #endregion

                if ($response['success'] ?? false) {
                    // Verify the update by fetching the article again
                    $verificationFields = null;
                    try {
                        $verifyResponse = $this->apiClient->getArticle($article->robaws_article_id, ['extraFields']);
                        if ($verifyResponse['success'] ?? false) {
                            $verifyData = $verifyResponse['data'] ?? null;
                            $verificationFields = $verifyData['extraFields'] ?? [];
                            // #region agent log
                            $this->debugLog('RobawsArticlePushService.php:490', 'Verification fetch after push', [
                                'article_id' => $article->id,
                                'verification_extraFields' => $verificationFields,
                                'fields_we_sent' => array_keys($filteredExtraFields),
                                'hypothesisId' => 'E',
                            ]);
                            // #endregion
                        }
                    } catch (\Exception $e) {
                        // #region agent log
                        $this->debugLog('RobawsArticlePushService.php:500', 'Verification fetch failed', [
                            'article_id' => $article->id,
                            'error' => $e->getMessage(),
                            'hypothesisId' => 'E',
                        ]);
                        // #endregion
                    }
                    
                    // Update tracking timestamp
                    $article->update(['last_pushed_to_robaws_at' => now()]);

                    // Build list of pushed fields for response
                    $pushedFields = [];
                    if (!empty($filteredMainArticlePayload)) {
                        // Map Robaws field names back to local field keys
                        foreach ($filteredMainArticlePayload as $robawsField => $value) {
                            foreach (self::MAIN_ARTICLE_FIELDS as $localKey => $config) {
                                if ($config['robaws_field'] === $robawsField) {
                                    $pushedFields[] = $localKey;
                                    break;
                                }
                            }
                        }
                    }
                    if (!empty($filteredExtraFields)) {
                        // Map Robaws field names back to local field keys for extraFields
                        foreach ($filteredExtraFields as $robawsField => $value) {
                            foreach (self::FIELD_MAPPING as $localKey => $config) {
                                if ($config['robaws_field'] === $robawsField) {
                                    $pushedFields[] = $localKey;
                                    break;
                                }
                            }
                        }
                    }

                    Log::info('Successfully pushed article to Robaws', [
                        'article_id' => $article->id,
                        'robaws_article_id' => $article->robaws_article_id,
                        'article_code' => $article->article_code,
                        'fields_pushed' => $pushedFields,
                        'main_fields_pushed' => array_keys($filteredMainArticlePayload),
                        'extraFields_pushed' => array_keys($filteredExtraFields),
                    ]);

                    return [
                        'success' => true,
                        'fields_pushed' => $pushedFields,
                        'article_code' => $article->article_code,
                        'attempts' => $attempt + 1,
                    ];
                }

                // Handle API errors
                $status = $response['status'] ?? 0;
                $error = $response['error'] ?? 'Unknown error';
                $lastError = $error;

                // Check if error is retryable
                $isRetryable = in_array($status, [429, 500, 502, 503, 504]); // Rate limit or server errors

                if (!$isRetryable || !$retryOnFailure || $attempt >= $maxRetries) {
                    Log::warning('Failed to push article to Robaws', [
                        'article_id' => $article->id,
                        'robaws_article_id' => $article->robaws_article_id,
                        'article_code' => $article->article_code,
                        'fields' => $fieldsToPush,
                        'status' => $status,
                        'error' => $error,
                        'attempts' => $attempt + 1,
                    ]);

                    return [
                        'success' => false,
                        'error' => $error,
                        'status' => $status,
                        'article_code' => $article->article_code,
                        'attempts' => $attempt + 1,
                    ];
                }

                // Retry with exponential backoff
                $attempt++;
                $backoffMs = min(1000 * pow(2, $attempt), 10000); // Max 10 seconds
                Log::info("Retrying push after {$backoffMs}ms", [
                    'article_code' => $article->article_code,
                    'attempt' => $attempt,
                ]);
                usleep($backoffMs * 1000);

            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                
                Log::error('Exception while pushing article to Robaws', [
                    'article_id' => $article->id,
                    'robaws_article_id' => $article->robaws_article_id,
                    'article_code' => $article->article_code,
                    'fields' => $fieldsToPush,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'attempt' => $attempt + 1,
                ]);

                // Don't retry on non-retryable exceptions
                if (!$retryOnFailure || $attempt >= $maxRetries) {
                    return [
                        'success' => false,
                        'error' => $e->getMessage(),
                        'article_code' => $article->article_code,
                        'attempts' => $attempt + 1,
                    ];
                }

                // Retry on exception
                $attempt++;
                $backoffMs = min(1000 * pow(2, $attempt), 10000);
                usleep($backoffMs * 1000);
            }
        }

        // Should not reach here, but handle just in case
        return [
            'success' => false,
            'error' => $lastError ?? 'Max retries exceeded',
            'article_code' => $article->article_code,
            'attempts' => $attempt + 1,
        ];
    }

    /**
     * Push multiple articles to Robaws with rate limiting and error handling
     */
    public function pushBulkArticles(Collection $articles, array $fieldsToPush, int $sleepMs = 100, bool $retryOnFailure = true): array
    {
        $results = [
            'total' => $articles->count(),
            'success' => 0,
            'failed' => 0,
            'errors' => [],
            'fields_pushed' => [],
        ];

        $validArticles = $articles->filter(fn ($article) => !empty($article->robaws_article_id));
        
        if ($validArticles->isEmpty()) {
            return [
                ...$results,
                'error' => 'No articles with Robaws article IDs found',
            ];
        }

        foreach ($validArticles as $index => $article) {
            // Add delay between requests (except for first article)
            if ($index > 0 && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }

            $result = $this->pushArticleToRobaws($article, $fieldsToPush, 0, $retryOnFailure);

            if ($result['success']) {
                $results['success']++;
                // Track which fields were successfully pushed
                if (isset($result['fields_pushed'])) {
                    $results['fields_pushed'] = array_unique(array_merge(
                        $results['fields_pushed'],
                        $result['fields_pushed']
                    ));
                }
            } else {
                $results['failed']++;
                $results['errors'][] = [
                    'article_code' => $article->article_code ?? 'N/A',
                    'article_name' => $article->article_name ?? 'N/A',
                    'robaws_article_id' => $article->robaws_article_id ?? 'N/A',
                    'error' => $result['error'] ?? 'Unknown error',
                    'status' => $result['status'] ?? null,
                    'attempts' => $result['attempts'] ?? 1,
                ];
            }
        }

        return $results;
    }

    /**
     * Get fields that have changed since last push
     */
    public function getChangedFieldsSinceLastPush(RobawsArticleCache $article): array
    {
        $changedFields = [];

        if (!$article->last_pushed_to_robaws_at) {
            // Never pushed, return all fields that have values
            // Include main article fields
            foreach (array_keys(self::MAIN_ARTICLE_FIELDS) as $fieldKey) {
                $value = $article->$fieldKey;
                if ($value !== null) {
                    $changedFields[] = $fieldKey;
                }
            }
            // Include extraFields
            foreach (array_keys(self::FIELD_MAPPING) as $fieldKey) {
                $config = self::FIELD_MAPPING[$fieldKey];
                $value = $this->getFieldValue($article, $config);
                
                if ($value !== null) {
                    $changedFields[] = $fieldKey;
                }
            }
            return $changedFields;
        }

        // Check if article was modified after last push
        if ($article->updated_at && $article->updated_at->gt($article->last_pushed_to_robaws_at)) {
            // For simplicity, return all fields that have values
            // In a more sophisticated implementation, we could track which specific fields changed
            // Include main article fields
            foreach (array_keys(self::MAIN_ARTICLE_FIELDS) as $fieldKey) {
                $value = $article->$fieldKey;
                if ($value !== null) {
                    $changedFields[] = $fieldKey;
                }
            }
            // Include extraFields
            foreach (array_keys(self::FIELD_MAPPING) as $fieldKey) {
                $config = self::FIELD_MAPPING[$fieldKey];
                $value = $this->getFieldValue($article, $config);
                
                if ($value !== null) {
                    $changedFields[] = $fieldKey;
                }
            }
        }

        return $changedFields;
    }
}
