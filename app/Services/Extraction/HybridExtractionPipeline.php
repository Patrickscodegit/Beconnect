<?php

namespace App\Services\Extraction;

use App\Services\AiRouter;
use App\Services\VehicleDatabase\VehicleDatabaseService;
use App\Services\Extraction\Strategies\PatternExtractor;
use App\Services\Extraction\Strategies\DatabaseExtractor;
use App\Services\Extraction\Strategies\AiExtractor;
use App\Support\JsonExtractorTrait;
use App\Utils\ArraySanitizer;
use Illuminate\Support\Facades\Log;

class HybridExtractionPipeline
{
    use JsonExtractorTrait;

    // Strategy precedence: higher number = higher priority
    const STRATEGY_PRECEDENCE = [
        'pattern' => 1,
        'database' => 2,
        'ai' => 3,
    ];

    // Critical fields where AI should take precedence
    const CRITICAL_FIELDS = [
        'vehicle.brand',
        'vehicle.model',
        'shipment.origin',
        'shipment.destination',
        'contact.email',
        'contact.phone',
        'dates.pickup',
        'dates.delivery',
    ];

    // Location stopwords that should not be considered as places
    private const LOCATION_STOPWORDS = [
        'bonjour','cordialement','salutations','merci','client','madame','monsieur',
        'hello','regards','thank','thanks','dear','sir','madam','mister','miss',
        'de','le','la','les','du','des','et','ou','pour','avec','vers','sur',
        'from','to','the','and','or','for','with','towards','on','at','in',
    ];
    
    public function __construct(
        private PatternExtractor $patternExtractor,
        private DatabaseExtractor $databaseEnhancer,
        private AiExtractor $aiExtractor,
        private VehicleDatabaseService $vehicleDb
    ) {}

    /**
     * Debug toggle based on environment variable
     */
    private function debug(): bool
    {
        // set EXTRACT_DEBUG=true in .env to enable
        return (bool) env('EXTRACT_DEBUG', false);
    }

    /**
     * Debug logger with prefix
     */
    private function dlog(string $msg, array $ctx = []): void
    {
        if ($this->debug()) {
            Log::debug('[HybridMerge] ' . $msg, $ctx);
        }
    }

    /**
     * Check if a value is filled (not null, empty string, or empty array)
     */
    private function filledValue($value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        if (is_array($value) && empty($value)) {
            return false;
        }
        return true;
    }

    /**
     * Check if a value is a placeholder (like "UNKNOWN", "N/A", etc.)
     */
    private function isPlaceholderValue($value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        
        $v = mb_strtolower(trim($value));
        return in_array($v, [
            'unknown', 'n/a', 'na', 'n\\a', '--', '-', '(unknown)',
            'not available', 'not found', 'tbd', 'to be determined', 
            'pending', 'none', 'null', 'undefined', '???', 'missing', 
            'empty', 'no data'
        ], true);
    }
    
    /**
     * Extract route information from any AI output shape
     */
    private function extractRouteFromAiShapes(array $ai): array
    {
        $cands = fn(array $paths) => collect($paths)->map(fn($p) => data_get($ai, $p))->first(fn($v) => $this->filledValue($v));

        $origin = $cands([
            'shipping.route.origin.city',
            'shipping_route.origin.city',
            'shipping.origin.city',
            'origin_location.city',
            'route.origin_city',
            'transport_request.origin',
            'transport_request.route.origin',
            'route.origin',
            'origin',
        ]);

        $destination = $cands([
            'shipping.route.destination.city',
            'shipping_route.destination.city',
            'shipping.destination.city',
            'destination_location.city',
            'route.destination_city',
            'transport_request.destination',
            'transport_request.route.destination',
            'route.destination',
            'destination',
        ]);

        $method = $cands([
            'shipping.method',
            'shipping_method',
            'transport_mode',
            'transport_request.transport_mode',
            'shipment.shipping_type',
        ]);

        return compact('origin', 'destination', 'method');
    }

    /**
     * Normalize AI extractor output to a consistent structure
     */
    private function normalizeAiShape(array $ai): array
    {
        $root = $ai;

        // NEW: canonical route extraction
        $r = $this->extractRouteFromAiShapes($ai);
        if ($this->filledValue($r['origin']) || $this->filledValue($r['destination']) || $this->filledValue($r['method'])) {
            // write modern structure
            if ($this->filledValue($r['origin'])) {
                data_set($root, 'shipping.route.origin.city', $r['origin']);
            }
            if ($this->filledValue($r['destination'])) {
                data_set($root, 'shipping.route.destination.city', $r['destination']);
            }
            if ($this->filledValue($r['method'])) {
                data_set($root, 'shipping.method', $r['method']);
            }
            // and mirror to legacy
            if ($this->filledValue($r['origin'])) data_set($root, 'shipment.origin', $r['origin']);
            if ($this->filledValue($r['destination'])) data_set($root, 'shipment.destination', $r['destination']);
            if ($this->filledValue($r['method'])) data_set($root, 'shipment.shipping_type', ArraySanitizer::canonicalShipType($r['method']));
        }

        // --- FALLBACK SHIPMENT NORMALIZATION (for legacy compatibility) ---
        // Try multiple paths for shipment data
        $shipmentData = $root['shipment']
            ?? data_get($root, 'transport_request')
            ?? data_get($root, 'shipping_route')
            ?? [];

        // Extract origin/destination from various possible structures if not already set
        $origin = data_get($root, 'shipment.origin') ?: $this->extractPlace(
            $shipmentData['origin']
            ?? data_get($root, 'shipping.route.origin')
            ?? data_get($root, 'shipping_route.origin')
            ?? data_get($root, 'origin_location')
            ?? $root['origin']
            ?? null
        );

        $destination = data_get($root, 'shipment.destination') ?: $this->extractPlace(
            $shipmentData['destination']
            ?? data_get($root, 'shipping.route.destination')
            ?? data_get($root, 'shipping_route.destination')
            ?? data_get($root, 'destination_location')
            ?? $root['destination']
            ?? null
        );

        // Extract shipping method/type if not already set
        $shippingType = data_get($root, 'shipment.shipping_type') ?: (
            $shipmentData['shipping_type']
            ?? $shipmentData['method']
            ?? data_get($root, 'shipping.method')
            ?? data_get($root, 'transport_mode')
            ?? null
        );

        $shipment = array_filter([
            'origin' => $origin,
            'destination' => $destination,
            'shipping_type' => $shippingType,
        ], fn($v) => $this->filledValue($v));

        // --- CONTACT NORMALIZATION ---
        // Accept contact, contact_details, shipping.contact
        $contactRaw = $root['contact']
            ?? ($root['contact_details'] ?? data_get($root, 'shipping.contact', []));
        $contact = array_filter([
            'name' => $contactRaw['name'] ?? null,
            'email' => $contactRaw['email'] ?? null,
            'phone' => $contactRaw['phone'] ?? null,
            'company' => $contactRaw['company'] ?? null,
        ], fn($v) => $this->filledValue($v));

        // --- VEHICLE NORMALIZATION ---
        $vehicleRaw = $root['vehicle'] ?? data_get($root, 'shipment.vehicle', []) ?? [];
        $vehicle = $this->normalizeVehicleData($vehicleRaw);

        // --- BUILD NORMALIZED STRUCTURE ---
        $normalized = array_filter([
            'vehicle' => $vehicle,
            'shipment' => $shipment,
            'contact' => $contact,
            'pricing' => $root['pricing'] ?? [],
            'dates' => $root['dates'] ?? [],
            'shipping' => $root['shipping'] ?? [],
            'database_validation' => $root['database_validation'] ?? [],
        ], fn($v) => !empty($v));

        // Apply legacy field mappings for compatibility
        $this->applyLegacyMappings($normalized, $root);

        return $normalized;
    }

    /**
     * Extract a place name from several possible shapes
     */
    private function extractPlace($val): ?string
    {
        if (!$val) return null;

        if (is_string($val)) {
            return $this->filledValue($val) ? trim($val) : null;
        }

        if (is_array($val)) {
            $city = $val['city'] ?? $val['name'] ?? null;
            if ($this->filledValue($city)) return trim($city);

            // Fallback: try to assemble from parts if city missing but country present
            $parts = array_filter([
                $val['city'] ?? null,
                $val['region'] ?? null,
                $val['country'] ?? null,
            ], fn($v) => $this->filledValue($v));
            if (!empty($parts)) {
                return trim(implode(', ', $parts));
            }
        }

        return null;
    }

    /**
     * Normalize vehicle data from various AI output formats
     */
    private function normalizeVehicleData(array $vehicleRaw): array
    {
        if (empty($vehicleRaw)) return [];

        $vehicle = array_filter([
            'brand' => $vehicleRaw['brand'] ?? $vehicleRaw['make'] ?? $vehicleRaw['vehicle_brand'] ?? null,
            'model' => $vehicleRaw['model'] ?? $vehicleRaw['vehicle_model'] ?? null,
            'year' => $vehicleRaw['year'] ?? $vehicleRaw['manufacturing_year'] ?? null,
            'vin' => $vehicleRaw['vin'] ?? $vehicleRaw['VIN'] ?? null,
            'color' => $vehicleRaw['color'] ?? null,
            'condition' => $vehicleRaw['condition'] ?? null,
            'fuel_type' => $vehicleRaw['fuel_type'] ?? $vehicleRaw['fuel'] ?? null,
            'weight_kg' => $vehicleRaw['weight_kg'] ?? $vehicleRaw['weight'] ?? null,
            'engine_cc' => $vehicleRaw['engine_cc'] ?? $vehicleRaw['engine_CC'] ?? $vehicleRaw['engine_displacement_CC'] ?? null,
            'dimensions' => $vehicleRaw['dimensions'] ?? null,
        ], fn($v) => $this->filledValue($v));

        // Set needs_dimension_lookup to false if dimensions are present
        if (!empty($vehicle['dimensions']['length_m']) 
            && !empty($vehicle['dimensions']['width_m']) 
            && !empty($vehicle['dimensions']['height_m'])) {
            $vehicle['needs_dimension_lookup'] = false;
        }

        return $vehicle;
    }

    /**
     * Apply legacy field mappings for backward compatibility
     */
    private function applyLegacyMappings(array &$normalized, array $root): void
    {
        $legacyMappings = [
            // Vehicle field mappings
            'vehicle_brand' => 'vehicle.brand',
            'vehicleBrand' => 'vehicle.brand',
            'car_brand' => 'vehicle.brand',
            'vehicle_model' => 'vehicle.model',
            'vehicleModel' => 'vehicle.model',
            'car_model' => 'vehicle.model',
            
            // Additional shipment field mappings
            'origin_city' => 'shipment.origin',
            'departure' => 'shipment.origin',
            'from' => 'shipment.origin',
            'destination_city' => 'shipment.destination',
            'arrival' => 'shipment.destination',
            'to' => 'shipment.destination',
            
            // Contact field mappings
            'email_address' => 'contact.email',
            'phone_number' => 'contact.phone',
            'telephone' => 'contact.phone',
            
            // Date field mappings
            'pickup_date' => 'dates.pickup',
            'delivery_date' => 'dates.delivery',
        ];

        foreach ($legacyMappings as $aiField => $standardField) {
            if (isset($root[$aiField])) {
                $existingValue = data_get($normalized, $standardField);
                if (!$this->filledValue($existingValue)) {
                    $this->setNestedValue($normalized, $standardField, $root[$aiField]);
                }
            }
        }
    }

    /**
     * Final data polishing and sanitization
     */
    private function finalPolishing(array &$data): void
    {
        // 1. Set needs_dimension_lookup to false if dimensions are present
        if (data_get($data, 'vehicle.dimensions.length_m')
            && data_get($data, 'vehicle.dimensions.width_m')
            && data_get($data, 'vehicle.dimensions.height_m')) {
            data_set($data, 'vehicle.needs_dimension_lookup', false);
        }
        
        // 2. Normalize shipping type
        $shippingType = ArraySanitizer::canonicalShipType(
            data_get($data, 'shipment.shipping_type')
            ?? data_get($data, 'shipping.method')
            ?? data_get($data, 'shipping.shipping_type')
        );
        if ($shippingType) {
            data_set($data, 'shipment.shipping_type', $shippingType);
        }
        
        // 3. Infer company from contact data
        $contact = data_get($data, 'contact', []);
        $company = ArraySanitizer::inferCompany($contact);
        if ($company) {
            data_set($data, 'contact.company', $company);
        }
        
        // 4. Clean all placeholder values
        $data = ArraySanitizer::cleanPlaceholders($data);
    }

    /**
     * Post-merge contact fallback from shipping.contact
     */
    private function backfillLegacyContact(array &$data): void
    {
        // If contact.name is empty but shipping.contact.name exists, copy it
        if (!data_get($data, 'contact.name') && data_get($data, 'shipping.contact.name')) {
            data_set($data, 'contact.name', data_get($data, 'shipping.contact.name'));
        }
        
        // Also handle other contact fields
        $contactFields = ['email', 'phone', 'company'];
        foreach ($contactFields as $field) {
            if (!data_get($data, "contact.{$field}") && data_get($data, "shipping.contact.{$field}")) {
                data_set($data, "contact.{$field}", data_get($data, "shipping.contact.{$field}"));
            }
        }
    }

    /**
     * Mirror shipping data between modern and legacy structures
     */
    private function mirrorShipmentAndShipping(array &$data): void
    {
        // read from modern
        $o = data_get($data, 'shipping.route.origin.city') ?? data_get($data, 'shipping.route.origin');
        $d = data_get($data, 'shipping.route.destination.city') ?? data_get($data, 'shipping.route.destination');
        $m = data_get($data, 'shipping.method') ?? data_get($data, 'shipping.shipping_type');

        // fill legacy if missing
        if (!$this->filledValue(data_get($data, 'shipment.origin')) && $this->filledValue($o)) {
            data_set($data, 'shipment.origin', $o);
        }
        if (!$this->filledValue(data_get($data, 'shipment.destination')) && $this->filledValue($d)) {
            data_set($data, 'shipment.destination', $d);
        }
        if (!$this->filledValue(data_get($data, 'shipment.shipping_type')) && $this->filledValue($m)) {
            data_set($data, 'shipment.shipping_type', ArraySanitizer::canonicalShipType($m));
        }

        // fill modern if missing
        if (!$this->filledValue($o) && $this->filledValue(data_get($data, 'shipment.origin'))) {
            data_set($data, 'shipping.route.origin.city', data_get($data, 'shipment.origin'));
        }
        if (!$this->filledValue($d) && $this->filledValue(data_get($data, 'shipment.destination'))) {
            data_set($data, 'shipping.route.destination.city', data_get($data, 'shipment.destination'));
        }
        if (!$this->filledValue($m) && $this->filledValue(data_get($data, 'shipment.shipping_type'))) {
            data_set($data, 'shipping.method', data_get($data, 'shipment.shipping_type'));
        }
    }

    /**
     * Backfill legacy shipment fields from modern shipping.route structure
     */
    private function backfillLegacyShipment(array &$data): void
    {
        // If legacy shipment is empty, populate from shipping.route
        $route = data_get($data, 'shipping.route');
        if (!$route || !is_array($route)) return;

        $origin = data_get($route, 'origin.city') ?: data_get($route, 'origin.name') ?: data_get($route, 'origin');
        $dest = data_get($route, 'destination.city') ?: data_get($route, 'destination.name') ?: data_get($route, 'destination');

        // Skip placeholders
        $origin = $this->meaningfulOrNull($origin);
        $dest = $this->meaningfulOrNull($dest);

        if (!data_get($data, 'shipment.origin') && $origin) {
            data_set($data, 'shipment.origin', $origin);
        }
        if (!data_get($data, 'shipment.destination') && $dest) {
            data_set($data, 'shipment.destination', $dest);
        }

        // Also copy shipping_type if present
        $stype = data_get($data, 'shipping.method') ?: data_get($data, 'shipping.shipping_type');
        if (!data_get($data, 'shipment.shipping_type') && $stype) {
            data_set($data, 'shipment.shipping_type', strtolower($stype));
        }
    }

    /**
     * Check if a value is meaningful (not a placeholder)
     */
    private function meaningfulOrNull($v)
    {
        if (!is_string($v)) return $v ?: null;
        $t = trim($v);
        $placeholders = ['n/a', 'na', 'unknown', 'unk', '-', '--', '(unknown)'];
        return $t === '' || in_array(mb_strtolower($t), $placeholders, true) ? null : $t;
    }

    /**
     * Helper method to set nested array values using dot notation
     */
    protected function setNestedValue(array &$array, string $path, $value): void
    {
        $keys = explode('.', $path);
        $current = &$array;
        
        foreach ($keys as $key) {
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }
        
        $current = $value;
    }

    /**
     * Extract data using multiple strategies and merge results intelligently
     */
    public function extract(string $content, string $documentType = 'email'): array
    {
        $startTime = microtime(true);
        
        Log::info('Starting hybrid extraction pipeline', [
            'document_type' => $documentType,
            'content_length' => strlen($content)
        ]);
        
        $results = [];
        $confidence = [];
        $strategyTimes = [];
        
        // Phase 1: Pattern-based extraction (fast, deterministic)
        $phaseStart = microtime(true);
        try {
            $results['pattern'] = $this->patternExtractor->extract($content);
            $confidence['pattern'] = $this->calculateConfidence($results['pattern']);
            $strategyTimes['pattern'] = microtime(true) - $phaseStart;
            
            Log::info('Pattern extraction completed', [
                'confidence' => $confidence['pattern'],
                'time_ms' => round($strategyTimes['pattern'] * 1000, 2),
                'vehicle_found' => !empty($results['pattern']['vehicle']['brand'])
            ]);
        } catch (\Exception $e) {
            Log::error('Pattern extraction failed', ['error' => $e->getMessage()]);
            $results['pattern'] = $this->getEmptyResult();
            $confidence['pattern'] = 0;
        }
        
        // Phase 2: Database enhancement (if we have initial vehicle data)
        if (!empty($results['pattern']['vehicle'])) {
            $phaseStart = microtime(true);
            try {
                $results['database'] = $this->databaseEnhancer->enhance(
                    $results['pattern'], 
                    $content
                );
                $confidence['database'] = $this->calculateConfidence($results['database']);
                $strategyTimes['database'] = microtime(true) - $phaseStart;
                
                Log::info('Database enhancement completed', [
                    'confidence' => $confidence['database'],
                    'time_ms' => round($strategyTimes['database'] * 1000, 2),
                    'database_match' => !empty($results['database']['vehicle']['database_match'])
                ]);
            } catch (\Exception $e) {
                Log::error('Database enhancement failed', ['error' => $e->getMessage()]);
                $results['database'] = $results['pattern'];
                $confidence['database'] = $confidence['pattern'];
            }
        }
        
        // Phase 3: AI extraction (expensive, but comprehensive)
        // Only if we need more data or low confidence
        if ($this->needsAiExtraction($results, $confidence)) {
            $phaseStart = microtime(true);
            try {
                $aiPrompt = $this->buildSmartPrompt($content, $results);
                $schema = $this->getEnhancedSchema();
                
                $results['ai'] = $this->aiExtractor->extract($aiPrompt, $schema);
                
                // Debug: Log raw AI response before normalization
                if ($this->debug()) {
                    Log::debug('[DEBUG] Raw AI response before normalization', [
                        'ai_data' => $results['ai']
                    ]);
                }
                
                // Normalize AI output to match expected schema
                $results['ai'] = $this->normalizeAiShape($results['ai']);
                
                // Debug: Log AI response after normalization
                if ($this->debug()) {
                    Log::debug('[DEBUG] AI response after normalization', [
                        'ai_data' => $results['ai']
                    ]);
                }
                
                $confidence['ai'] = $this->calculateConfidence($results['ai']);
                $strategyTimes['ai'] = microtime(true) - $phaseStart;
                
                Log::info('AI extraction completed', [
                    'confidence' => $confidence['ai'],
                    'time_ms' => round($strategyTimes['ai'] * 1000, 2),
                    'prompt_length' => strlen($aiPrompt)
                ]);
            } catch (\Exception $e) {
                Log::error('AI extraction failed', ['error' => $e->getMessage()]);
                $results['ai'] = $this->getEmptyResult();
                $confidence['ai'] = 0;
            }
        } else {
            Log::info('AI extraction skipped', [
                'reason' => 'sufficient_confidence',
                'max_confidence' => max($confidence)
            ]);
        }
        
        // Phase 4: Merge and validate
        $merged = $this->mergeResults($results, $confidence);
        
        // Phase 5: Final database validation and enrichment
        $validated = $this->finalValidation($merged);
        
        $totalTime = microtime(true) - $startTime;
        
        Log::info('Hybrid extraction pipeline completed', [
            'total_time_ms' => round($totalTime * 1000, 2),
            'strategies_used' => array_keys($results),
            'final_confidence' => $this->calculateOverallConfidence($confidence)
        ]);
        
        return [
            'data' => $validated,
            'metadata' => [
                'extraction_strategies' => array_keys($results),
                'confidence_scores' => $confidence,
                'overall_confidence' => $this->calculateOverallConfidence($confidence),
                'database_validated' => !empty($validated['vehicle']['database_match']),
                'processing_time_ms' => round($totalTime * 1000, 2),
                'strategy_times' => array_map(fn($time) => round($time * 1000, 2), $strategyTimes),
                'extraction_pipeline_version' => '1.0'
            ]
        ];
    }
    
    /**
     * Determine if AI extraction is needed
     */
    private function needsAiExtraction(array $results, array $confidence): bool
    {
        // Always use AI if confidence is low
        $maxConfidence = max($confidence);
        if ($maxConfidence < 0.7) {
            Log::info('AI needed: low confidence', ['max_confidence' => $maxConfidence]);
            return true;
        }
        
        // Check if critical fields are missing
        $criticalFields = [
            'vehicle.brand',
            'vehicle.model', 
            'vehicle.dimensions', // Add dimensions as critical field
            'shipment.origin',
            'shipment.destination',
            'contact.email'
        ];
        
        $missingCritical = [];
        foreach ($criticalFields as $field) {
            $found = false;
            foreach ($results as $strategyName => $data) {
                if (!empty(data_get($data, $field))) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missingCritical[] = $field;
            }
        }
        
        if (!empty($missingCritical)) {
            Log::info('AI needed: missing critical fields', ['missing' => $missingCritical]);
            return true;
        }
        
        // Check if we have incomplete vehicle data
        if (!empty($results['pattern']['vehicle']) || !empty($results['database']['vehicle'])) {
            $vehicleData = $results['database']['vehicle'] ?? $results['pattern']['vehicle'];
            $importantVehicleFields = ['year', 'vin', 'engine_cc', 'dimensions'];
            
            $missingVehicleFields = 0;
            foreach ($importantVehicleFields as $field) {
                if (empty($vehicleData[$field])) {
                    $missingVehicleFields++;
                }
            }
            
            // Always trigger AI if dimensions are missing and we have vehicle make/model
            if (empty($vehicleData['dimensions']) && 
                !empty($vehicleData['brand']) && 
                !empty($vehicleData['model'])) {
                Log::info('AI needed: missing dimensions for known vehicle', [
                    'vehicle' => $vehicleData['brand'] . ' ' . $vehicleData['model'],
                    'year' => $vehicleData['year'] ?? 'unknown'
                ]);
                return true;
            }
            
            if ($missingVehicleFields >= 2) {
                Log::info('AI needed: incomplete vehicle data', [
                    'missing_fields' => $missingVehicleFields
                ]);
                return true;
            }
        }
        
        Log::info('AI not needed: sufficient data extracted');
        return false;
    }
    
    /**
     * Build a smart prompt that includes what we've already found
     */
    private function buildSmartPrompt(string $content, array $existingResults): string
    {
        $prompt = "Extract shipping and vehicle information from this document. Pay special attention to vehicle brand and model identification.\n\n";
        
        // Add context about what we already know
        $bestResult = $this->getBestExistingResult($existingResults);
        
        if (!empty($bestResult['vehicle'])) {
            $vehicle = $bestResult['vehicle'];
            
            // Check if we have incomplete brand/model info
            $brand = $vehicle['brand'] ?? 'UNKNOWN';
            $model = $vehicle['model'] ?? 'UNKNOWN';
            
            if ($brand !== 'UNKNOWN' && $model === 'UNKNOWN') {
                $prompt .= "IMPORTANT: We detected the vehicle brand as '$brand' but the model is not identified.\n";
                $prompt .= "Please carefully read the text to find the specific model name for this $brand vehicle.\n";
                $prompt .= "Look for model names like 'X5', 'E-Class', 'Série 7', 'Camry', etc.\n\n";
            } elseif ($brand !== 'UNKNOWN' && $model !== 'UNKNOWN') {
                $prompt .= "Context: This appears to be about a ";
                if (!empty($vehicle['year'])) $prompt .= $vehicle['year'] . " ";
                $prompt .= "$brand $model\n\n";
            } else {
                $prompt .= "IMPORTANT: Please identify the vehicle brand and model from the text.\n\n";
            }
            
            // Specify what we still need
            $needed = [];
            if ($brand === 'UNKNOWN') $needed[] = "vehicle brand/manufacturer";
            if ($model === 'UNKNOWN') $needed[] = "vehicle model name";
            if (empty($vehicle['vin'])) $needed[] = "VIN number";
            if (empty($vehicle['year'])) $needed[] = "manufacturing year";
            if (empty($vehicle['engine_cc'])) $needed[] = "engine displacement (CC)";
            if (empty($vehicle['fuel_type'])) $needed[] = "fuel type";
            if (empty($vehicle['weight_kg'])) $needed[] = "weight";
            if (empty($vehicle['condition'])) $needed[] = "condition (new/used/damaged)";
            if (empty($vehicle['color'])) $needed[] = "color";
            
            // Special handling for dimensions - always request if missing
            if (empty($vehicle['dimensions'])) {
                $needed[] = "DIMENSIONS (Length x Width x Height in meters)";
                
                if ($brand !== 'UNKNOWN' && $model !== 'UNKNOWN') {
                    $prompt .= "IMPORTANT: This document mentions a $brand $model";
                    if (!empty($vehicle['year'])) $prompt .= " from " . $vehicle['year'];
                    $prompt .= ".\n";
                    $prompt .= "Please provide the standard factory dimensions for this specific vehicle model.\n";
                    $prompt .= "Use accurate manufacturer specifications for Length x Width x Height in meters.\n";
                    $prompt .= "Example format: {'length_m': 5.299, 'width_m': 1.946, 'height_m': 1.405}\n\n";
                }
            }
            
            if (!empty($needed)) {
                $prompt .= "Still needed for vehicle: " . implode(", ", $needed) . "\n\n";
            }
        } else {
            $prompt .= "IMPORTANT: Please identify the vehicle brand and model from the text.\n\n";
        }
        
        if (!empty($bestResult['shipment'])) {
            $shipment = $bestResult['shipment'];
            $needed = [];
            if (empty($shipment['origin'])) $needed[] = "origin location";
            if (empty($shipment['destination'])) $needed[] = "destination location";
            if (empty($shipment['shipping_type'])) $needed[] = "shipping method (RORO/Container)";
            
            if (!empty($needed)) {
                $prompt .= "Still needed for shipment: " . implode(", ", $needed) . "\n\n";
            }
        }
        
        // Add specific extraction instructions
        $prompt .= "Extract the following with high precision:\n";
        $prompt .= "- Complete vehicle specifications (year, make, model, VIN, engine CC, fuel type, weight, color, condition)\n";
        $prompt .= "- VEHICLE DIMENSIONS: **CRITICAL** - Provide accurate manufacturer dimensions:\n";
        $prompt .= "  * If document contains dimensions: Extract exactly as stated\n";
        $prompt .= "  * If NO dimensions in document: Look up standard factory specifications\n";
        $prompt .= "  * Use your knowledge of real vehicle specifications from manufacturers\n";
        $prompt .= "  * Format: Length × Width × Height in meters (e.g., 5.299 × 1.946 × 1.405)\n";
        $prompt .= "  * For Bentley Continental: Use actual Bentley factory specifications\n";
        $prompt .= "  * Convert all measurements to meters with 3 decimal precision\n";
        $prompt .= "- Shipping route (origin city/country → destination city/country, ports if mentioned)\n";
        $prompt .= "- Shipping method (RORO, 20ft container, 40ft container, 40HC container)\n";
        $prompt .= "- Timeline (pickup date, delivery date, ETD, ETA)\n";
        $prompt .= "- Pricing (amount, currency, incoterm like FOB/CIF)\n";
        $prompt .= "- Contact details (name, company, email, phone)\n\n";
        
        $prompt .= "Return ONLY the missing or more detailed information. Be precise with technical specifications.\n\n";
        $prompt .= "Email content:\n" . $content;
        
        return $prompt;
    }
    
    /**
     * Get the best existing result for context
     */
    private function getBestExistingResult(array $results): array
    {
        // Prefer database enhanced, then pattern, then empty
        if (!empty($results['database'])) {
            return $results['database'];
        }
        
        if (!empty($results['pattern'])) {
            return $results['pattern'];
        }
        
        return [];
    }
    
    /**
     * Enhanced schema for vehicle extraction
     */
    private function getEnhancedSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'vehicle' => [
                    'type' => 'object',
                    'required' => ['brand', 'model'],
                    'properties' => [
                        'brand' => [
                            'type' => 'string',
                            'description' => 'Vehicle manufacturer/brand (e.g., BMW, Mercedes, Toyota) - REQUIRED'
                        ],
                        'model' => [
                            'type' => 'string',
                            'description' => 'Vehicle model name (e.g., X5, E-Class, Camry, Série 7) - REQUIRED'
                        ],
                        'vin' => [
                            'type' => 'string', 
                            'pattern' => '^[A-HJ-NPR-Z0-9]{17}$',
                            'description' => 'Vehicle Identification Number - exactly 17 characters'
                        ],
                        'year' => [
                            'type' => 'integer', 
                            'minimum' => 1900, 
                            'maximum' => 2030,
                            'description' => 'Manufacturing year'
                        ],
                        'variant' => [
                            'type' => 'string',
                            'description' => 'Specific variant or trim level'
                        ],
                        'engine_cc' => [
                            'type' => 'integer',
                            'minimum' => 500,
                            'maximum' => 8000,
                            'description' => 'Engine displacement in cubic centimeters'
                        ],
                        'fuel_type' => [
                            'type' => 'string', 
                            'enum' => ['petrol', 'diesel', 'electric', 'hybrid', 'lpg', 'cng'],
                            'description' => 'Type of fuel the vehicle uses'
                        ],
                        'transmission' => [
                            'type' => 'string', 
                            'enum' => ['manual', 'automatic', 'cvt'],
                            'description' => 'Transmission type'
                        ],
                        'color' => [
                            'type' => 'string',
                            'description' => 'Vehicle exterior color'
                        ],
                        'condition' => [
                            'type' => 'string', 
                            'enum' => ['new', 'used', 'damaged'],
                            'description' => 'Vehicle condition'
                        ],
                        'mileage_km' => [
                            'type' => 'integer',
                            'minimum' => 0,
                            'description' => 'Odometer reading in kilometers'
                        ],
                        'weight_kg' => [
                            'type' => 'number',
                            'minimum' => 500,
                            'maximum' => 10000,
                            'description' => 'Vehicle weight in kilograms'
                        ],
                        'dimensions' => [
                            'type' => 'object',
                            'properties' => [
                                'length_m' => [
                                    'type' => 'number',
                                    'minimum' => 2,
                                    'maximum' => 15,
                                    'description' => 'Length in meters'
                                ],
                                'width_m' => [
                                    'type' => 'number',
                                    'minimum' => 1,
                                    'maximum' => 3,
                                    'description' => 'Width in meters'
                                ],
                                'height_m' => [
                                    'type' => 'number',
                                    'minimum' => 1,
                                    'maximum' => 4,
                                    'description' => 'Height in meters'
                                ]
                            ]
                        ]
                    ]
                ],
                'shipment' => [
                    'type' => 'object',
                    'properties' => [
                        'origin' => [
                            'type' => 'string',
                            'description' => 'Origin city and country'
                        ],
                        'destination' => [
                            'type' => 'string',
                            'description' => 'Destination city and country'
                        ],
                        'origin_port' => [
                            'type' => 'string',
                            'description' => 'Port of loading (POL)'
                        ],
                        'destination_port' => [
                            'type' => 'string',
                            'description' => 'Port of discharge (POD)'
                        ],
                        'shipping_type' => [
                            'type' => 'string', 
                            'enum' => ['roro', 'container', 'air'],
                            'description' => 'Method of shipping'
                        ],
                        'container_size' => [
                            'type' => 'string', 
                            'enum' => ['20ft', '40ft', '40hc'],
                            'description' => 'Container size if applicable'
                        ]
                    ]
                ],
                'dates' => [
                    'type' => 'object',
                    'properties' => [
                        'pickup_date' => [
                            'type' => 'string', 
                            'format' => 'date',
                            'description' => 'Vehicle pickup/collection date'
                        ],
                        'delivery_date' => [
                            'type' => 'string', 
                            'format' => 'date',
                            'description' => 'Expected delivery date'
                        ],
                        'etd' => [
                            'type' => 'string', 
                            'format' => 'date',
                            'description' => 'Estimated time of departure from port'
                        ],
                        'eta' => [
                            'type' => 'string', 
                            'format' => 'date',
                            'description' => 'Estimated time of arrival at port'
                        ]
                    ]
                ],
                'pricing' => [
                    'type' => 'object',
                    'properties' => [
                        'amount' => [
                            'type' => 'number',
                            'minimum' => 0,
                            'description' => 'Price amount'
                        ],
                        'currency' => [
                            'type' => 'string', 
                            'pattern' => '^[A-Z]{3}$',
                            'description' => 'Currency code (USD, EUR, GBP)'
                        ],
                        'incoterm' => [
                            'type' => 'string', 
                            'enum' => ['FOB', 'CIF', 'CFR', 'EXW', 'DDP', 'DAP'],
                            'description' => 'International commercial terms'
                        ]
                    ]
                ],
                'contact' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => 'Contact person name'
                        ],
                        'company' => [
                            'type' => 'string',
                            'description' => 'Company name'
                        ],
                        'email' => [
                            'type' => 'string', 
                            'format' => 'email',
                            'description' => 'Email address'
                        ],
                        'phone' => [
                            'type' => 'string',
                            'description' => 'Phone number'
                        ]
                    ]
                ]
            ]
        ];
    }
    
    /**
     * Check if a field path should prioritize strategy precedence over confidence
     */
    protected function isCriticalField(string $fieldPath): bool
    {
        return in_array($fieldPath, self::CRITICAL_FIELDS);
    }

    /**
     * Get strategy precedence value for field-level decision making
     */
    protected function getStrategyPrecedence(string $strategy): int
    {
        return self::STRATEGY_PRECEDENCE[$strategy] ?? 0;
    }

    /**
     * Determine field precedence based on strategy type and field importance
     */
    protected function pathPrecedence(string $strategy, string $fieldPath): int
    {
        $basePrecedence = $this->getStrategyPrecedence($strategy);
        
        // For critical fields, boost AI strategy even more
        if ($this->isCriticalField($fieldPath) && $strategy === 'ai') {
            return $basePrecedence + 10;
        }
        
        return $basePrecedence;
    }

    /**
     * Basic plausibility check for extracted values
     */
    protected function isPlausible(string $fieldPath, $value, array $context = []): bool
    {
        if (!$this->filledValue($value)) {
            return false;
        }

        // City/location plausibility checks
        if (in_array($fieldPath, ['shipment.origin', 'shipment.destination'], true)) {
            if (!is_string($value)) return false;

            $s = trim($value);
            $sl = mb_strtolower($s);

            // reject obvious non-places
            if (str_contains($s, '@')) return false;              // email
            if (preg_match('/^\d+$/', $s)) return false;          // digits only
            if (mb_strlen($sl) < 2) return false;                 // too short
            if (in_array($sl, self::LOCATION_STOPWORDS, true)) return false;

            // reject if equals sender name tokens
            $nameTokens = $context['contact_tokens'] ?? [];
            if (!empty($nameTokens) && in_array($sl, $nameTokens, true)) {
                return false;
            }

            // looks OK as a place-ish string
            return true;
        }

        // Email plausibility
        if ($fieldPath === 'contact.email') {
            return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
        }

        // Phone plausibility
        if ($fieldPath === 'contact.phone') {
            // Should contain mostly digits and common phone separators
            return preg_match('/^[\d\s\-\+\(\)\.]{7,}$/', $value);
        }

        // VIN plausibility
        if ($fieldPath === 'vehicle.vin') {
            return is_string($value) && (bool) preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $value);
        }

        return true;
    }
    
    /**
     * Merge results from different strategies intelligently
     */
    private function mergeResults(array $results, array $confidence): array
    {
        $merged = [];
        $fieldProvenance = [];
        $strategies = array_keys($results);
        usort($strategies, fn($a, $b) => ($confidence[$b] ?? 0) <=> ($confidence[$a] ?? 0));

        // build context (contact tokens) once
        $contactTokens = [];
        $bestContactName = data_get($results, 'ai.contact.name')
            ?? data_get($results, 'database.contact.name')
            ?? data_get($results, 'pattern.contact.name');
        if (is_string($bestContactName) && $bestContactName !== '') {
            $contactTokens = array_values(array_filter(
                preg_split('/[\s\-]+/u', mb_strtolower($bestContactName)) ?: []
            ));
        }
        $context = ['contact_tokens' => $contactTokens];

        $this->dlog('Starting merge with context', [
            'strategies' => $strategies,
            'contact_tokens' => $contactTokens,
            'confidence' => $confidence
        ]);

        foreach ($strategies as $strategy) {
            $data = $results[$strategy] ?? [];
            $weight = $confidence[$strategy] ?? 0.0;

            foreach ($data as $section => $sectionData) {
                if ($section === 'metadata') continue;
                if (!isset($merged[$section])) $merged[$section] = [];

                $before = $merged[$section];
                
                // Handle both array and scalar section data
                if (is_array($sectionData)) {
                    $after = $this->mergeSectionData($before, $sectionData, $section, $strategy, $weight, $context);
                } else {
                    // Handle scalar values directly
                    $path = $section;
                    $shouldAccept = $this->isPlausible($path, $sectionData, $context);
                    
                    if ($shouldAccept && (!$this->filledValue($before) || 
                        $this->pathPrecedence($strategy, $path) > $this->pathPrecedence('pattern', $path))) {
                        $after = $sectionData;
                        $this->dlog('accept-scalar', [
                            'path' => $path, 
                            'value' => $sectionData, 
                            'strategy' => $strategy
                        ]);
                    } else {
                        $after = $before;
                    }
                }

                // provenance for arrays only
                if (is_array($sectionData)) {
                    foreach ($sectionData as $k => $_) {
                        $val = $after[$k] ?? null;
                        if ($this->filledValue($val)) {
                            $fieldProvenance["$section.$k"] = [
                                'strategy' => $strategy,
                                'confidence' => $weight,
                            ];
                            $after['_source_strategy'][$k] = $strategy;
                            $after['_source_conf'][$k] = $weight;
                        }
                    }
                }
                $merged[$section] = $after;
            }
        }

        // cleanup helpers
        foreach (['vehicle', 'shipment', 'contact', 'pricing', 'dates'] as $sec) {
            if (isset($merged[$sec]['_source_strategy'])) unset($merged[$sec]['_source_strategy']);
            if (isset($merged[$sec]['_source_conf'])) unset($merged[$sec]['_source_conf']);
        }

        // post-merge cleanup: if origin/destination implausible, prefer AI's plausible values
        $merged = $this->postMergeFixLocations($merged, $results['ai'] ?? [], $context);
        
        // mirror shipping data between modern and legacy structures
        $this->mirrorShipmentAndShipping($merged);
        
        // backfill legacy structures from modern AI output
        $this->backfillLegacyContact($merged);
        $this->backfillLegacyShipment($merged);
        
        // final polishing and sanitization
        $this->finalPolishing($merged);

        Log::info('Merge results completed', [
            'strategies_processed' => array_keys($results),
            'field_provenance' => $fieldProvenance,
            'final_structure' => array_keys($merged)
        ]);

        return $merged;
    }

    /**
     * Merge data for a specific section with detailed logging and scoring
     */
    private function mergeSectionData(
        array $existing,
        array $new,
        string $section,
        string $strategy,
        float $strategyConfidence,
        array $context = []
    ): array {
        foreach ($new as $key => $val) {
            $path = "{$section}.{$key}";
            $hasExisting = array_key_exists($key, $existing);
            $old = $hasExisting ? $existing[$key] : null;

            // accept new if nothing there and plausible
            if (!$hasExisting || !$this->filledValue($old)) {
                $ok = $this->isPlausible($path, $val, $context);
                $this->dlog('accept-empty', compact('path', 'val', 'ok', 'strategy', 'strategyConfidence'));
                if ($ok) $existing[$key] = $val;
                continue;
            }

            // compute scores for old vs new
            $oldSrc = $existing['_source_strategy'][$key] ?? 'pattern';
            $oldPlaus = $this->isPlausible($path, $old, $context);
            $newPlaus = $this->isPlausible($path, $val, $context);

            $oldScore = ($oldPlaus ? 1 : 0) + 0.01;
            $newScore = ($newPlaus ? 1 : 0) + 0.01;

            // Heavily penalize placeholder values like "UNKNOWN"
            if ($this->isPlaceholderValue($old)) {
                $oldScore -= 2.0; // Strong penalty for placeholder
            }
            if ($this->isPlaceholderValue($val)) {
                $newScore -= 2.0; // Strong penalty for placeholder
            }

            $oldScore += $this->pathPrecedence($oldSrc, $path);
            $newScore += $this->pathPrecedence($strategy, $path);

            $oldScore += ($existing['_source_conf'][$key] ?? 0.0);
            $newScore += $strategyConfidence;

            // decision
            $override = $newScore > $oldScore;

            $this->dlog('merge-decision', [
                'path' => $path,
                'old' => $old,
                'new' => $val,
                'old_src' => $oldSrc,
                'new_src' => $strategy,
                'old_plaus' => $oldPlaus,
                'new_plaus' => $newPlaus,
                'old_score' => round($oldScore, 3),
                'new_score' => round($newScore, 3),
                'override' => $override,
            ]);

            if ($override) {
                $existing[$key] = $val;
            }
        }

        return $existing;
    }

    /**
     * Post-merge cleanup: if origin/destination implausible, prefer AI's plausible values
     */
    private function postMergeFixLocations(array $merged, array $ai, array $context): array
    {
        foreach (['origin', 'destination'] as $k) {
            $path = "shipment.$k";
            $final = data_get($merged, $path);
            $aiVal = data_get($ai, "shipment.$k");

            $finalOk = $this->isPlausible($path, $final, $context);
            $aiOk = $this->isPlausible($path, $aiVal, $context);

            if (!$finalOk && $aiOk && $this->filledValue($aiVal)) {
                data_set($merged, $path, $aiVal);
                $this->dlog('post-fix-location', ['field' => $path, 'ai_val' => $aiVal]);
            }
        }
        return $merged;
    }
    
    /**
     * Final validation and database enrichment
     */
    private function finalValidation(array $data): array
    {
        // Final vehicle enrichment if we have enough data
        if (!empty($data['vehicle']) && empty($data['vehicle']['database_match'])) {
            $enriched = $this->vehicleDb->enrichVehicleData($data['vehicle']);
            if (!empty($enriched['database_match'])) {
                $data['vehicle'] = $enriched;
                Log::info('Final vehicle enrichment successful', [
                    'database_id' => $enriched['database_id']
                ]);
            }
        }
        
        // Validate data consistency
        $validation = $this->performFinalValidation($data);
        $data['final_validation'] = $validation;
        
        return $data;
    }
    
    /**
     * Perform final data validation
     */
    private function performFinalValidation(array $data): array
    {
        $validation = [
            'valid' => true,
            'warnings' => [],
            'quality_score' => 1.0
        ];
        
        // Validate vehicle data if present
        if (!empty($data['vehicle'])) {
            $vehicleValidation = $this->vehicleDb->validateVehicleData($data['vehicle']);
            if (!$vehicleValidation['valid']) {
                $validation['quality_score'] *= 0.8;
                $validation['warnings'] = array_merge(
                    $validation['warnings'],
                    $vehicleValidation['warnings']
                );
            }
        }
        
        // Check data completeness
        $completeness = $this->calculateDataCompleteness($data);
        $validation['completeness_score'] = $completeness;
        
        if ($completeness < 0.5) {
            $validation['warnings'][] = "Low data completeness: {$completeness}";
            $validation['quality_score'] *= 0.9;
        }
        
        return $validation;
    }
    
    /**
     * Calculate data completeness score
     */
    private function calculateDataCompleteness(array $data): float
    {
        $totalFields = 0;
        $filledFields = 0;
        
        $sections = [
            'vehicle' => ['brand', 'model', 'year', 'vin', 'engine_cc', 'fuel_type', 'dimensions', 'weight_kg'],
            'contact' => ['email', 'name', 'phone', 'company'],
            'shipment' => ['origin', 'destination', 'shipping_type'],
            'pricing' => ['amount', 'currency'],
            'dates' => ['pickup_date', 'delivery_date']
        ];
        
        foreach ($sections as $section => $fields) {
            foreach ($fields as $field) {
                $totalFields++;
                if (!empty(data_get($data, "$section.$field"))) {
                    $filledFields++;
                }
            }
        }
        
        return $totalFields > 0 ? round($filledFields / $totalFields, 2) : 0;
    }
    
    /**
     * Calculate confidence for a result set
     */
    private function calculateConfidence(array $data): float
    {
        $score = 0;
        $maxScore = 0;
        
        // Vehicle confidence (most important)
        $vehicleWeights = [
            'brand' => 15, 'model' => 15, 'year' => 10, 'vin' => 20,
            'engine_cc' => 5, 'fuel_type' => 5, 'dimensions' => 10, 'weight_kg' => 5
        ];
        
        foreach ($vehicleWeights as $field => $weight) {
            $maxScore += $weight;
            if (!empty(data_get($data, "vehicle.$field"))) {
                $score += $weight;
            }
        }
        
        // Contact confidence
        $contactWeights = ['email' => 10, 'phone' => 5, 'name' => 3, 'company' => 2];
        foreach ($contactWeights as $field => $weight) {
            $maxScore += $weight;
            if (!empty(data_get($data, "contact.$field"))) {
                $score += $weight;
            }
        }
        
        // Shipment confidence
        $shipmentWeights = ['origin' => 8, 'destination' => 8, 'shipping_type' => 4];
        foreach ($shipmentWeights as $field => $weight) {
            $maxScore += $weight;
            if (!empty(data_get($data, "shipment.$field"))) {
                $score += $weight;
            }
        }
        
        return $maxScore > 0 ? round($score / $maxScore, 2) : 0;
    }
    
    /**
     * Calculate overall confidence from individual strategy confidences
     */
    private function calculateOverallConfidence(array $confidences): float
    {
        if (empty($confidences)) return 0;
        
        // Weighted average with preference for database validation
        $weights = [
            'pattern' => 0.3,
            'database' => 0.5,
            'ai' => 0.2
        ];
        
        $totalWeight = 0;
        $weightedSum = 0;
        
        foreach ($confidences as $strategy => $confidence) {
            $weight = $weights[$strategy] ?? 0.1;
            $weightedSum += $confidence * $weight;
            $totalWeight += $weight;
        }
        
        return $totalWeight > 0 ? round($weightedSum / $totalWeight, 2) : 0;
    }
    
    /**
     * Get empty result structure
     */
    private function getEmptyResult(): array
    {
        return [
            'vehicle' => [],
            'shipment' => [],
            'contact' => [],
            'dates' => [],
            'pricing' => [],
            'metadata' => []
        ];
    }
}
