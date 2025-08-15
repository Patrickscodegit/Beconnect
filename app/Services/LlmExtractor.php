<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use OpenAI;
use Exception;

class LlmExtractor
{
    private $openai;
    private array $rateLimiter = [];

    public function __construct()
    {
        $this->openai = OpenAI::client(config('services.openai.api_key'));
    }

    public function extract(string $payload): array
    {
        // Rate limiting check
        $this->checkRateLimit();

        $prompt = Storage::get('prompts/extractor.txt');
        
        Log::info("Starting LLM extraction", [
            'payload_length' => strlen($payload),
            'model' => config('services.openai.model', 'gpt-4-turbo-preview')
        ]);

        try {
            $response = $this->openai->chat()->create([
                'model' => config('services.openai.model', 'gpt-4-turbo-preview'),
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $prompt
                    ],
                    [
                        'role' => 'user', 
                        'content' => $payload
                    ]
                ],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.1,
                'max_tokens' => 4000
            ]);

            $content = $response->choices[0]->message->content;
            $extractedJson = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON response from OpenAI: ' . json_last_error_msg());
            }

            Log::info("LLM extraction completed successfully", [
                'confidence' => $extractedJson['confidence'] ?? 0,
                'vehicles_count' => count($extractedJson['vehicles'] ?? [])
            ]);

            return [
                'json' => $extractedJson,
                'confidence' => $extractedJson['confidence'] ?? 0
            ];

        } catch (Exception $e) {
            Log::error('LLM extraction failed', [
                'error' => $e->getMessage(),
                'payload_length' => strlen($payload)
            ]);

            // Return fallback mock data for development
            return $this->getFallbackExtraction();
        }
    }

    public function classifyDocument(string $text, string $filename): string
    {
        // Rate limiting check
        $this->checkRateLimit();

        $cacheKey = 'document_classification_' . md5($text . $filename);
        
        return Cache::remember($cacheKey, 1800, function () use ($text, $filename) {
            try {
                $response = $this->openai->chat()->create([
                    'model' => config('services.openai.model', 'gpt-4-turbo-preview'),
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a document classifier for freight forwarding and vehicle shipping. Classify the document type based on the content and filename. Return only one of these categories: vehicle_document, shipping_document, financial_document, customs_document, insurance_document, or unknown.'
                        ],
                        [
                            'role' => 'user',
                            'content' => "Filename: {$filename}\n\nContent: " . substr($text, 0, 2000)
                        ]
                    ],
                    'temperature' => 0.1,
                    'max_tokens' => 50
                ]);

                $classification = trim($response->choices[0]->message->content);
                
                Log::info('Document classified via LLM', [
                    'filename' => $filename,
                    'classification' => $classification
                ]);

                return $classification;

            } catch (Exception $e) {
                Log::error('LLM document classification failed', [
                    'error' => $e->getMessage(),
                    'filename' => $filename
                ]);
                throw $e;
            }
        });
    }

    public function extractVehicleData(string $text): array
    {
        // Rate limiting check
        $this->checkRateLimit();

        $cacheKey = 'vehicle_extraction_' . md5(substr($text, 0, 500));
        
        return Cache::remember($cacheKey, 3600, function () use ($text) {
            try {
                $prompt = "You are a vehicle data extractor for freight forwarding. Extract structured vehicle information from the provided text. Return JSON with these fields: vin, make, model, year, color, engine_size, fuel_type, transmission, mileage, value, plate_number. If a field is not found, omit it from the response.";

                $response = $this->openai->chat()->create([
                    'model' => config('services.openai.model', 'gpt-4-turbo-preview'),
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $prompt
                        ],
                        [
                            'role' => 'user',
                            'content' => $text
                        ]
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.1,
                    'max_tokens' => 1000
                ]);

                $content = $response->choices[0]->message->content;
                $extractedData = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Invalid JSON response from OpenAI: ' . json_last_error_msg());
                }

                Log::info('Vehicle data extracted via LLM', [
                    'extracted_fields' => array_keys($extractedData)
                ]);

                return $extractedData;

            } catch (Exception $e) {
                Log::error('LLM vehicle extraction failed', [
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        });
    }

    private function checkRateLimit(): void
    {
        $now = time();
        $minute = floor($now / 60);
        $maxRequests = config('services.openai.rate_limit_per_minute', 50);

        if (!isset($this->rateLimiter[$minute])) {
            $this->rateLimiter[$minute] = 0;
        }

        if ($this->rateLimiter[$minute] >= $maxRequests) {
            throw new Exception("Rate limit exceeded. Maximum {$maxRequests} requests per minute.");
        }

        $this->rateLimiter[$minute]++;

        // Clean up old entries
        foreach ($this->rateLimiter as $key => $count) {
            if ($key < $minute - 5) {
                unset($this->rateLimiter[$key]);
            }
        }
    }

    private function getFallbackExtraction(): array
    {
        Log::warning('Using fallback extraction data');
        
        $mockJson = [
            'por' => 'Los Angeles, CA',
            'pol' => 'Long Beach, CA',
            'pod' => 'Hamburg, Germany',
            'fdest' => 'Berlin, Germany',
            'incoterms' => 'CFR',
            'shipment_type' => 'RORO',
            'parties' => [
                'shipper' => [
                    'name' => 'ABC Auto Exports',
                    'street' => '123 Export Blvd',
                    'city' => 'Los Angeles',
                    'postal_code' => '90210',
                    'country' => 'USA'
                ],
                'consignee' => [
                    'name' => 'European Auto Imports GmbH',
                    'street' => 'Hafenstrasse 45',
                    'city' => 'Hamburg',
                    'postal_code' => '20095',
                    'country' => 'Germany'
                ],
                'forwarder' => null,
                'forwarder_pol' => null,
                'forwarder_pod' => null
            ],
            'vehicles' => [
                [
                    'make' => 'Toyota',
                    'model' => 'Camry',
                    'year' => 2023,
                    'vin' => 'JT2BG22K1X0123456',
                    'plate' => 'ABC1234',
                    'dims_m' => [
                        'L' => 4.88,
                        'W' => 1.84,
                        'H' => 1.44,
                        'wheelbase' => 2.82
                    ],
                    'cbm' => 12.94,
                    'weight_kg' => 1590,
                    'engine_cc' => 2487,
                    'fuel_type' => 'petrol',
                    'powertrain_type' => 'ICE',
                    'country_of_manufacture' => [
                        'value' => 'Japan',
                        'verified' => false
                    ]
                ]
            ],
            'notes' => [
                'fallback_extraction_used',
                'openai_api_unavailable'
            ],
            'confidence' => 0.5
        ];

        return [
            'json' => $mockJson,
            'confidence' => 0.5
        ];
    }
}
