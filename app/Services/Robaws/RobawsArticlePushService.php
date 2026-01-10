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
        'sales_price' => [
            'robaws_field' => 'SALES PRICE',
            'type' => 'NUMBER',
            'group' => 'ARTICLE INFO',
            'getter' => 'unit_price',
            'formatter' => 'float',
        ],
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
            'getter' => 'pol',
            'formatter' => 'string',
        ],
        'pod' => [
            'robaws_field' => 'POD',
            'type' => 'SELECT',
            'group' => 'ARTICLE INFO',
            'getter' => 'pod',
            'formatter' => 'string',
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
    ];

    public function __construct(RobawsApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
    }

    /**
     * Get list of pushable fields with metadata
     */
    public function getPushableFields(): array
    {
        return array_map(function ($key, $config) {
            return [
                'key' => $key,
                'label' => $this->getFieldLabel($key),
                'robaws_field' => $config['robaws_field'],
                'type' => $config['type'],
                'group' => $config['group'],
            ];
        }, array_keys(self::FIELD_MAPPING), self::FIELD_MAPPING);
    }

    /**
     * Get human-readable label for field key
     */
    private function getFieldLabel(string $key): string
    {
        $labels = [
            'update_date' => 'Update Date',
            'validity_date' => 'Validity Date',
            'sales_price' => 'Sales Price',
            'shipping_line' => 'Shipping Line',
            'service_type' => 'Service Type',
            'pol_terminal' => 'POL Terminal',
            'pol' => 'POL',
            'pod' => 'POD',
            'parent_item' => 'Parent Item',
            'cost_side' => 'Cost Side',
            'article_type' => 'Article Type',
        ];

        return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * Build extraFields payload for Robaws API
     */
    public function buildExtraFieldsPayload(RobawsArticleCache $article, array $fieldsToPush): array
    {
        $extraFields = [];

        foreach ($fieldsToPush as $fieldKey) {
            if (!isset(self::FIELD_MAPPING[$fieldKey])) {
                Log::warning("Unknown field key for push: {$fieldKey}");
                continue;
            }

            $config = self::FIELD_MAPPING[$fieldKey];
            $value = $this->getFieldValue($article, $config);

            if ($value === null && !isset($config['allow_null'])) {
                continue; // Skip null values unless explicitly allowed
            }

            $fieldData = [
                'type' => $config['type'],
            ];

            // Add group if specified
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
                    $fieldData['numberValue'] = (float) $value;
                    break;

                case 'boolean':
                    $fieldData['booleanValue'] = (bool) $value;
                    break;

                case 'string_upper':
                    $fieldData['stringValue'] = strtoupper((string) $value);
                    break;

                case 'string':
                default:
                    $fieldData['stringValue'] = (string) $value;
                    break;
            }

            $extraFields[$config['robaws_field']] = $fieldData;
        }

        return $extraFields;
    }

    /**
     * Get field value from article using getter configuration
     */
    private function getFieldValue(RobawsArticleCache $article, array $config)
    {
        $getter = $config['getter'];

        // Handle relationship access (e.g., 'shippingCarrier.name')
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
        }

        // Handle accessor methods (e.g., 'effective_update_date')
        if (method_exists($article, 'get' . str_replace('_', '', ucwords($getter, '_')) . 'Attribute')) {
            return $article->$getter;
        }

        // Direct attribute access
        return $article->$getter ?? null;
    }

    /**
     * Push single article to Robaws with validation and error handling
     */
    public function pushArticleToRobaws(RobawsArticleCache $article, array $fieldsToPush, int $sleepMs = 0, bool $retryOnFailure = false, int $maxRetries = 1): array
    {
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

        // Validate field keys
        $invalidFields = array_diff($fieldsToPush, array_keys(self::FIELD_MAPPING));
        if (!empty($invalidFields)) {
            return [
                'success' => false,
                'error' => 'Invalid field keys: ' . implode(', ', $invalidFields),
                'article_code' => $article->article_code,
            ];
        }

        $attempt = 0;
        $lastError = null;

        while ($attempt <= $maxRetries) {
            try {
                // Build payload
                $extraFields = $this->buildExtraFieldsPayload($article, $fieldsToPush);

                if (empty($extraFields)) {
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

                $payload = ['extraFields' => $extraFields];
                
                // Rate limiting: check if we need to wait
                // The RobawsApiClient handles rate limiting internally, but we add additional delay here
                if ($sleepMs > 0 && $attempt === 0) {
                    usleep($sleepMs * 1000);
                }

                // Make API call
                $response = $this->apiClient->updateArticle($article->robaws_article_id, $payload);

                if ($response['success'] ?? false) {
                    // Update tracking timestamp
                    $article->update(['last_pushed_to_robaws_at' => now()]);

                    Log::info('Successfully pushed article to Robaws', [
                        'article_id' => $article->id,
                        'robaws_article_id' => $article->robaws_article_id,
                        'article_code' => $article->article_code,
                        'fields_pushed' => array_keys($extraFields),
                    ]);

                    return [
                        'success' => true,
                        'fields_pushed' => array_keys($extraFields),
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
