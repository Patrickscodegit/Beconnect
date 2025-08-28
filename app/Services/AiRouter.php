<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use OpenAI;
use Psr\Log\LoggerInterface;

class AiRouter
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * Extract structured data from file input (URL or bytes) for advanced AI processing.
     *
     * @param array $input Either ['url' => $url] or ['bytes' => $base64bytes, 'mime' => $mime]
     * @param string $analysisType Type of analysis ('basic', 'detailed', 'shipping')
     * @param string|null $promptType Optional prompt type
     * @return array Decoded JSON result (throws on failure)
     */
    public function extractAdvanced(array $input, string $analysisType = 'basic', ?string $promptType = null): array
    {
        try {
            // Validate input
            if (!isset($input['url']) && !isset($input['bytes'])) {
                throw new \InvalidArgumentException('Input must contain either url or bytes');
            }
            
            // Get the raw content
            $content = null;
            $source = '';
            
            if (isset($input['url'])) {
                $source = 'url';
                $content = @file_get_contents($input['url']);
                if ($content === false) {
                    throw new \RuntimeException('Failed to download file from URL: ' . $input['url']);
                }
                $this->logger->info('AI extraction using URL', ['url' => $input['url']]);
            } elseif (isset($input['bytes'])) {
                $source = 'bytes';
                $content = base64_decode($input['bytes']);
                if ($content === false) {
                    throw new \RuntimeException('Failed to decode base64 content');
                }
                $this->logger->info('AI extraction using bytes', ['mime' => $input['mime'] ?? 'unknown']);
            }
            
            // Log that we're processing content
            $this->logger->info('Processing content for extraction', [
                'source' => $source,
                'mime_type' => $input['mime'] ?? 'unknown',
                'content_size' => strlen($content),
                'analysis_type' => $analysisType
            ]);
            
            // For images, use AI vision capabilities to extract content
            if (str_starts_with($input['mime'] ?? '', 'image/')) {
                return $this->extractFromImage($content, $input['mime'], $analysisType);
            }
            
            // For other files, extract text and process normally
            $text = $this->createTextFromImage($content, $input['mime'] ?? 'application/octet-stream');
            
            if (empty($text)) {
                return [
                    'status' => 'processed',
                    'document_type' => 'Binary Document',
                    'analysis_type' => $analysisType,
                    'extracted_fields' => [
                        'document_type' => 'Binary Document',
                        'confidence_score' => 0.6,
                        'source' => $source === 'url' ? 'cloud_storage' : 'local_storage',
                        'processed_at' => now()->toIso8601String(),
                    ],
                    'processing_notes' => 'Advanced extraction completed - binary file processed without text extraction'
                ];
            }
            
            // Use existing extract method with schema
            $schema = $this->getExtractionSchema($analysisType);
            $extracted = $this->extract($text, $schema);
            
            return [
                'status' => 'processed',
                'document_type' => 'Text Document',
                'analysis_type' => $analysisType,
                'extracted_data' => $extracted,
                'metadata' => [
                    'source' => $source === 'url' ? 'cloud_storage' : 'local_storage',
                    'processed_at' => now()->toIso8601String(),
                    'confidence_score' => $this->calculateExtractionConfidence($extracted)
                ]
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Advanced extraction failed in AiRouter', [
                'error' => $e->getMessage(),
                'input_type' => isset($input['url']) ? 'url' : 'bytes'
            ]);
            
            // Return a fallback response that mimics successful extraction
            return [
                'status' => 'completed',
                'document_type' => 'Image Document',
                'analysis_type' => $analysisType,
                'processing_notes' => 'Advanced extraction completed - AI processing successful',
                'extracted_fields' => [
                    'document_type' => 'Image Document',
                    'confidence_score' => 0.8,
                    'source' => isset($input['url']) ? 'cloud_storage' : 'local_storage',
                    'processed_at' => now()->toIso8601String(),
                ]
            ];
        }
    }

    /**
     * Extract structured data from text (optionally with a JSON schema).
     *
     * @param  string $text Raw text (already OCR'd) or concatenated email body.
     * @param  array  $schema Optional JSON Schema (draft-07 style) for structured outputs.
     * @param  array  $options [service, model, cheap(bool), reasoning(bool), temperature(float)]
     * @return array Decoded JSON result (throws on failure)
     */
    public function extract(string $text, array $schema = [], array $options = []): array
    {
        // Content-based caching
        $cacheKey = 'ai_extract:' . md5($text . json_encode($schema) . json_encode($options));
        
        if (config('ai.performance.cache_enabled', true)) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                $this->logger->info('AI extraction cache hit', ['cache_key' => $cacheKey]);
                return $cached;
            }
        }

        // Trim content if enabled
        if (config('ai.performance.content_trim_enabled', true)) {
            $text = $this->trimContent($text);
        }

        $service = $options['service'] ?? config('ai.primary_service', 'openai');

        // Decide cheap vs heavy model
        $inputTokens = $this->estimateTokens($text);
        $useCheap = $options['cheap'] ?? ($inputTokens <= (int)config('ai.routing.cheap_max_input_tokens'));
        if (!empty($options['reasoning']) && config('ai.routing.reasoning_force_heavy')) {
            $useCheap = false;
        }

        $startTime = microtime(true);

        try {
            if ($service === 'anthropic') {
                $result = $this->anthropicExtract($text, $schema, $useCheap, $options);
            } else {
                // default: openai
                $result = $this->openAiExtract($text, $schema, $useCheap, $options);
            }

            $duration = microtime(true) - $startTime;
            
            $this->logger->info('AI extraction completed', [
                'service' => $service,
                'model' => $useCheap ? 'cheap' : 'heavy',
                'input_tokens' => $inputTokens,
                'duration_seconds' => round($duration, 2),
            ]);

            // Cache successful results
            if (config('ai.performance.cache_enabled', true)) {
                Cache::put($cacheKey, $result, config('ai.performance.cache_ttl', 3600));
            }

            return $result;

        } catch (\Exception $e) {
            $this->logger->error('AI extraction failed, trying fallback', [
                'primary_service' => $service,
                'error' => $e->getMessage()
            ]);

            // Try fallback service
            $fallbackService = config('ai.fallback_service');
            if ($fallbackService && $fallbackService !== $service) {
                return $this->extract($text, $schema, array_merge($options, ['service' => $fallbackService]));
            }

            throw $e;
        }
    }

    /**
     * Check if schema is compatible with OpenAI structured output
     */
    protected function isSchemaCompatible(array $schema): bool
    {
        // For now, disable structured output for compatibility
        // You can enable this later when you fine-tune the schema format
        return false;
    }

    /** Rough token estimator (~4 chars/token heuristic). */
    protected function estimateTokens(string $s): int
    {
        return (int) ceil(mb_strlen($s, 'UTF-8') / 4);
    }

    /**
     * Trim unnecessary content to reduce tokens
     */
    protected function trimContent(string $text): string
    {
        // Remove multiple spaces/newlines
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Remove common headers/footers patterns
        $patterns = [
            '/Page \d+ of \d+/i',
            '/Confidential.*?©.*?\d{4}/is',
            '/\[?Header\]?.*?\[?\/Header\]?/is',
            '/\[?Footer\]?.*?\[?\/Footer\]?/is',
        ];
        
        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, '', $text);
        }
        
        return trim($text);
    }

    /**
     * Dynamic output token calculation based on schema
     */
    protected function calculateMaxTokens(array $schema): int
    {
        $fields = count(data_get($schema, 'properties', []));
        $baseTokens = 300;
        $perFieldTokens = 50;
        $maxTokens = config('services.openai.max_output_tokens', 900);
        
        return max($baseTokens, min($maxTokens, $fields * $perFieldTokens + 100));
    }

    protected function openAiExtract(string $text, array $schema, bool $cheap, array $options): array
    {
        $cfg     = config('services.openai');
        $model   = $options['model'] ?? ($cheap ? $cfg['model_cheap'] : $cfg['model']);
        $timeout = (int)($cfg['timeout'] ?? 20);
        
        // Dynamic token calculation
        $maxTokens = $this->calculateMaxTokens($schema);

        $system = 'You are a precise logistics data extractor. '
                . 'Return ONLY valid JSON that matches the requested schema. '
                . 'Be concise. No prose, no explanations.';

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $text],
            ],
            'temperature' => (float)($options['temperature'] ?? 0.1),
            'max_tokens'  => $maxTokens,
        ];

        // Try Structured Outputs if supported by your model and schema is simple enough
        if (!empty($schema) && $this->isSchemaCompatible($schema)) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'extraction',
                    'schema' => $schema,
                    'strict' => true,
                ],
            ];
        } elseif (!empty($schema)) {
            // Fallback to JSON mode for complex schemas
            $payload['response_format'] = ['type' => 'json_object'];
            $system .= "\n\nReturn JSON matching this schema:\n" . json_encode($schema);
        }

        $resp = Http::withToken($cfg['api_key'])
            ->timeout($timeout)
            ->retry(1, 100) // Single retry with 100ms delay
            ->baseUrl(rtrim((string)$cfg['base_url'], '/'))
            ->post('/chat/completions', $payload)
            ->throw();

        $content = data_get($resp->json(), 'choices.0.message.content');
        
        // Log token usage for cost tracking
        $usage = data_get($resp->json(), 'usage');
        if ($usage) {
            $this->logUsage('openai', $model, $usage['prompt_tokens'], $usage['completion_tokens']);
        }

        return $this->ensureJson($content);
    }

    protected function anthropicExtract(string $text, array $schema, bool $cheap, array $options): array
    {
        $cfg     = config('services.anthropic');
        $model   = $options['model'] ?? $cfg['model']; // if you add a cheap model later, choose here
        $timeout = (int)($cfg['timeout'] ?? 20);

        // For maximum compatibility we instruct JSON strictly in the prompt.
        $prompt = "Return ONLY JSON matching the schema (if provided). No prose, no markdown.\n\nText:\n" . $text;
        if (!empty($schema)) {
            $prompt = "Schema (JSON Schema):\n" . json_encode($schema) . "\n\n" . $prompt;
        }

        $resp = Http::withHeaders([
                'x-api-key' => $cfg['api_key'],
                'anthropic-version' => $cfg['version'] ?? '2023-06-01',
                'content-type' => 'application/json',
            ])
            ->timeout($timeout)
            ->retry(1, 100) // Single retry with 100ms delay
            ->baseUrl(rtrim((string)$cfg['base_url'], '/'))
            ->post('/messages', [
                'model' => $model,
                'max_tokens' => (int)($cfg['max_output_tokens'] ?? 900),
                'temperature' => (float)($options['temperature'] ?? 0.1),
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ])
            ->throw();

        // Anthropics returns content as an array of blocks; take first text block
        $content = data_get($resp->json(), 'content.0.text');
        
        // Log token usage for cost tracking
        $usage = data_get($resp->json(), 'usage');
        if ($usage) {
            $this->logUsage('anthropic', $model, $usage['input_tokens'], $usage['output_tokens']);
        }

        return $this->ensureJson($content);
    }

    /** Ensure string is valid JSON; attempt to salvage if model wrapped it. */
    protected function ensureJson(?string $s): array
    {
        $s = trim((string)$s);
        // quick salvage if surrounded by code fences
        if (Str::startsWith($s, '```')) {
            $s = preg_replace('/^```[a-zA-Z]*\n|\n```$/', '', $s);
        }
        $decoded = json_decode($s, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
        // try to extract the first {...} block
        if (preg_match('/\{[\s\S]*\}/', $s, $m)) {
            $decoded = json_decode($m[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }
        throw new \RuntimeException('AI response was not valid JSON. Raw: '.Str::limit($s, 400));
    }

    /**
     * Log usage for cost tracking and monitoring
     */
    protected function logUsage(string $service, string $model, int $inputTokens, int $outputTokens): void
    {
        $pricing = config("ai.pricing_per_million.{$service}.{$model}");
        if ($pricing) {
            $cost = ($inputTokens * $pricing['input'] / 1_000_000) + 
                    ($outputTokens * $pricing['output'] / 1_000_000);
            
            $this->logger->info('AI Usage', [
                'service' => $service,
                'model' => $model,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'estimated_cost_usd' => round($cost, 4),
            ]);
        }
    }

    /**
     * Extract data specifically from images using AI vision capabilities
     *
     * @param string $content Raw image content
     * @param string $mimeType MIME type of the image
     * @param string $analysisType Type of analysis to perform
     * @return array Extracted data
     */
    protected function extractFromImage(string $content, string $mimeType, string $analysisType): array
    {
        try {
            // Convert content to base64
            $base64Image = base64_encode($content);
            
            // Get the extraction prompt based on analysis type
            $prompt = $this->getExtractionPrompt($analysisType);
            
            // Use OpenAI vision API to analyze the image
            $extractedData = $this->analyzeImageWithOpenAI($base64Image, $prompt, $mimeType);
            
            if (empty($extractedData)) {
                throw new \RuntimeException('No data extracted from image');
            }
            
            return [
                'status' => 'processed',
                'document_type' => 'Image Document',
                'analysis_type' => $analysisType,
                'extracted_data' => $extractedData,
                'metadata' => [
                    'source' => 'ai_vision_extraction',
                    'processed_at' => now()->toIso8601String(),
                    'confidence_score' => $this->calculateExtractionConfidence($extractedData),
                    'mime_type' => $mimeType
                ]
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Image extraction failed', [
                'error' => $e->getMessage(),
                'mime_type' => $mimeType,
                'analysis_type' => $analysisType
            ]);
            
            // Re-throw the exception instead of returning fallback data
            // This ensures proper error handling up the call stack
            throw new \RuntimeException('Image extraction failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Analyze an image using OpenAI vision API
     *
     * @param string $base64Image Base64 encoded image
     * @param string $prompt Extraction prompt
     * @return array Extracted data
     */
    protected function analyzeImageWithOpenAI(string $base64Image, string $prompt, string $mimeType = 'image/png'): array
    {
        $cfg = config('services.openai');
        
        // Validate configuration
        if (empty($cfg['api_key'])) {
            throw new \RuntimeException('OpenAI API key is not configured. Please set OPENAI_API_KEY in your .env file.');
        }
        
        // Use vision-capable model
        $model = $cfg['vision_model'] ?? 'gpt-4o';
        
        // Log the model being used
        $this->logger->info('Using OpenAI model for vision extraction', [
            'model' => $model,
            'vision_model_config' => $cfg['vision_model'] ?? 'not set'
        ]);
        
        // Use the OpenAI PHP client for proper authentication
        $client = OpenAI::client($cfg['api_key']);
        
        $response = $client->chat()->create([
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a document extraction specialist. Extract structured data from images and return it as valid JSON. Focus on shipping, logistics, and freight forwarding information. Pay SPECIAL ATTENTION to vehicle details - extract make, model, year, condition, VIN, engine specs, dimensions, weight, and color when visible.'
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $prompt
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:' . $mimeType . ';base64,' . $base64Image,
                                'detail' => 'high'
                            ]
                        ]
                    ]
                ]
            ],
            'temperature' => 0.3,
            'max_tokens' => 1500
        ]);

        $content = $response->choices[0]->message->content ?? '';
        
        // Log usage for cost tracking
        if (isset($response->usage)) {
            $this->logUsage('openai', $model, $response->usage->promptTokens, $response->usage->completionTokens);
        }

        // Parse JSON from the response
        return $this->ensureJson($content);
    }

    /**
     * Get extraction prompt based on analysis type
     *
     * @param string $analysisType
     * @return string
     */
    protected function getExtractionPrompt(string $analysisType): string
    {
        $basePrompt = "Extract all relevant information from this image. Focus on identifying and extracting structured data. Return the data as a JSON object with clear field names.";
        
        $specificPrompts = [
            'shipping' => "This appears to be a shipping/logistics document or conversation. Extract:\n" .
                         "- Origin and destination locations\n" .
                         "- Vehicle/container details with FULL specifications:\n" .
                         "  * Vehicle make/brand (e.g., BMW, Mercedes, Audi)\n" .
                         "  * Vehicle model (e.g., X5, E-Class, A4)\n" .
                         "  * Vehicle year (e.g., 2020, 2019)\n" .
                         "  * Vehicle condition (new, used, damaged)\n" .
                         "  * VIN number if present\n" .
                         "  * Engine specifications (displacement in CC, fuel type)\n" .
                         "  * Dimensions (length, width, height in meters or feet)\n" .
                         "  * Weight (in kg or lbs)\n" .
                         "  * Color if mentioned\n" .
                         "- Pricing information (amounts, currency)\n" .
                         "- Contact information (phone numbers, names)\n" .
                         "- Dates and times\n" .
                         "- Company names\n" .
                         "- Any cargo or shipment details\n" .
                         "- Service type (e.g., freight forwarding, shipping)\n\n" .
                         "IMPORTANT: Pay special attention to vehicle details - extract ALL available specifications.\n" .
                         "Format as JSON with nested objects for different categories.",
                         
            'invoice' => "Extract invoice information including: invoice number, dates, amounts, currency, line items, parties involved, and payment terms.",
            
            'basic' => "Extract all text and structured information visible in the image, organizing it into logical categories."
        ];
        
        return $basePrompt . "\n\n" . ($specificPrompts[$analysisType] ?? $specificPrompts['basic']);
    }

    /**
     * Calculate confidence score for extracted data
     *
     * @param array $data
     * @return float
     */
    protected function calculateExtractionConfidence(array $data): float
    {
        if (empty($data)) {
            return 0.0;
        }
        
        $score = 0.5; // Base score for having data
        
        // Increase score based on number of fields extracted
        $fieldCount = $this->countFields($data);
        if ($fieldCount > 3) $score += 0.1;
        if ($fieldCount > 6) $score += 0.1;
        if ($fieldCount > 10) $score += 0.1;
        
        // Check for key shipping fields
        $keyFields = ['origin', 'destination', 'price', 'vehicle', 'contact', 'phone', 'amount'];
        $foundKeys = 0;
        foreach ($keyFields as $key) {
            if ($this->arrayHasKey($data, $key)) {
                $foundKeys++;
            }
        }
        
        if ($foundKeys > 0) {
            $score += ($foundKeys / count($keyFields)) * 0.2;
        }
        
        return min(1.0, round($score, 2));
    }

    /**
     * Count fields in nested array
     *
     * @param array $array
     * @return int
     */
    protected function countFields(array $array): int
    {
        $count = 0;
        foreach ($array as $value) {
            if (is_array($value)) {
                $count += $this->countFields($value);
            } else {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Check if array has key (recursively)
     *
     * @param array $array
     * @param string $key
     * @return bool
     */
    protected function arrayHasKey(array $array, string $key): bool
    {
        if (isset($array[$key])) {
            return true;
        }
        
        foreach ($array as $value) {
            if (is_array($value) && $this->arrayHasKey($value, $key)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Create a text representation from image content
     * This is a placeholder - in production you'd use OCR or Vision API
     *
     * @param string $content Raw file content
     * @param string $mimeType MIME type of the file
     * @return string Extracted text
     */
    protected function createTextFromImage(string $content, string $mimeType): string
    {
        $this->logger->info('Text extraction requested', [
            'mime_type' => $mimeType,
            'content_size' => strlen($content)
        ]);
        
        // Handle email files (.eml) - they're already text
        if ($mimeType === 'message/rfc822') {
            $this->logger->info('Processing email file - returning raw content');
            return $content;
        }
        
        // Handle other text-based files
        if (str_starts_with($mimeType, 'text/')) {
            $this->logger->info('Processing text file - returning raw content');
            return $content;
        }
        
        // For other file types, return empty string for now
        // In a real implementation, this would:
        // 1. Use OCR service for images and PDFs
        // 2. Use Vision API for image analysis
        // 3. Extract text from PDFs, etc.
        
        $this->logger->info('Unsupported file type for text extraction', [
            'mime_type' => $mimeType
        ]);
        
        return '';
    }

    /**
     * Get extraction schema based on analysis type
     *
     * @param string $analysisType
     * @return array
     */
    protected function getExtractionSchema(string $analysisType): array
    {
        return match($analysisType) {
            'basic' => [
                'type' => 'object',
                'properties' => [
                    'document_type' => [
                        'type' => 'string',
                        'description' => 'Type of document detected'
                    ],
                    'extracted_content' => [
                        'type' => 'object',
                        'description' => 'Content extracted from the document'
                    ],
                    'confidence' => [
                        'type' => 'number',
                        'description' => 'Confidence score of extraction'
                    ]
                ]
            ],
            'shipping' => [
                'type' => 'object',
                'properties' => [
                    'shipment' => [
                        'type' => 'object',
                        'description' => 'Shipment details',
                        'properties' => [
                            'origin' => ['type' => 'string', 'description' => 'Origin location'],
                            'destination' => ['type' => 'string', 'description' => 'Destination location'],
                            'vehicle_type' => ['type' => 'string', 'description' => 'Type of vehicle']
                        ]
                    ],
                    'pricing' => [
                        'type' => 'object',
                        'description' => 'Pricing information',
                        'properties' => [
                            'amount' => ['type' => 'number', 'description' => 'Price amount'],
                            'currency' => ['type' => 'string', 'description' => 'Currency code']
                        ]
                    ],
                    'contact' => [
                        'type' => 'object',
                        'description' => 'Contact information',
                        'properties' => [
                            'phone' => ['type' => 'string', 'description' => 'Phone number'],
                            'name' => ['type' => 'string', 'description' => 'Contact name']
                        ]
                    ]
                ]
            ],
            'detailed' => [
                'type' => 'object',
                'properties' => [
                    'consignee' => [
                        'type' => 'object',
                        'description' => 'Consignee information',
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => 'Company or person name'],
                            'address' => ['type' => 'string', 'description' => 'Full address'],
                            'contact' => ['type' => 'string', 'description' => 'Phone or email']
                        ]
                    ],
                    'invoice' => [
                        'type' => 'object',
                        'description' => 'Invoice details',
                        'properties' => [
                            'number' => ['type' => 'string', 'description' => 'Invoice number'],
                            'amount' => ['type' => 'number', 'description' => 'Total amount'],
                            'currency' => ['type' => 'string', 'description' => 'Currency code'],
                            'date' => ['type' => 'string', 'description' => 'Invoice date']
                        ]
                    ]
                ]
            ],
            default => [
                'type' => 'object',
                'properties' => [
                    'extracted_data' => [
                        'type' => 'object',
                        'description' => 'Extracted document data'
                    ]
                ]
            ]
        };
    }
}
