<?php

namespace App\Services\Extraction\Strategies;

use App\Services\VehicleDatabase\VehicleDatabaseService;
use Illuminate\Support\Facades\Log;

class PatternExtractor
{
    private array $vehiclePatterns;
    private array $shippingPatterns;
    private array $contactPatterns;
    private array $datePatterns;
    private array $pricePatterns;
    
    public function __construct(
        private VehicleDatabaseService $vehicleDb
    ) {
        $this->initializePatterns();
    }
    
    /**
     * Extract data using pattern matching
     */
    public function extract(string $content): array
    {
        $extracted = [
            'vehicle' => $this->extractVehicleData($content),
            'shipment' => $this->extractShippingData($content),
            'contact' => $this->extractContactData($content),
            'dates' => $this->extractDateData($content),
            'pricing' => $this->extractPricingData($content),
            'raw_text' => $content, // Include raw text for routing analysis
            'metadata' => [
                'extraction_method' => 'pattern_matching',
                'extracted_at' => now()->toIso8601String()
            ]
        ];
        
        Log::info('Pattern extraction completed', [
            'vehicle_found' => !empty($extracted['vehicle']['brand']),
            'contact_found' => !empty($extracted['contact']['email']),
            'pricing_found' => !empty($extracted['pricing']['amount'])
        ]);
        
        return $extracted;
    }
    
    /**
     * Initialize all extraction patterns
     */
    private function initializePatterns(): void
    {
        $this->vehiclePatterns = [
            'vin' => '/\b([A-HJ-NPR-Z0-9]{17})\b/',
            'year' => '/\b(19|20)(\d{2})\b/',
            'engine_cc' => '/(\d{3,4})\s*(cc|CC|cm³|CM³|ccm|CCM)/',
            // Enhanced dimension patterns - LxWxH format
            'dimensions_metric' => '/(\d+\.?\d*)\s*[×x×X*]\s*(\d+\.?\d*)\s*[×x×X*]\s*(\d+\.?\d*)\s*(m|M|cm|CM|mm|MM|meters?|centimeters?|millimeters?)/',
            'dimensions_imperial' => '/(\d+\.?\d*)\s*[×x×X*]\s*(\d+\.?\d*)\s*[×x×X*]\s*(\d+\.?\d*)\s*(ft|FT|feet|FEET|in|IN|inch|inches|foot)/',
                        // NEW: German separated dimensions pattern for "800cm lang, 204cm breit, 232cm hoch"
            'dimensions_separated_german' => '/(\d+)\s*cm\s+lang[,]?\s*(\d+)\s*cm\s+breit[,]?\s*(\d+)\s*cm\s+hoch/i',
            // NEW: Dimensions separated pattern for "800cm long, 204cm wide, 232cm high"
            'dimensions_separated' => '/(\d+)\s*cm\s+long[,]?\s*(\d+)\s*cm\s+wide[,]?\s*(\d+)\s*cm\s+high/i',
            // Individual dimension fields with labels - very specific to avoid false positives
            'length_labeled' => '/(?:^|\b)(?:vehicle\s+)?length[\s:=]+(\d+\.?\d*)\s*(m|cm|mm|ft|feet|in|inches)\b/i',
            'width_labeled' => '/(?:^|\b)(?:vehicle\s+)?width[\s:=]+(\d+\.?\d*)\s*(m|cm|mm|ft|feet|in|inches)\b/i',
            'height_labeled' => '/(?:^|\b)(?:vehicle\s+)?height[\s:=]+(\d+\.?\d*)\s*(m|cm|mm|ft|feet|in|inches)\b/i',
            // Single letter labels only when followed by units
            'length_single_letter' => '/\bL[\s:=]+(\d+\.?\d*)\s*(m|cm|mm|ft|feet|in|inches)\b/i',
            'width_single_letter' => '/\bW[\s:=]+(\d+\.?\d*)\s*(m|cm|mm|ft|feet|in|inches)\b/i',
            'height_single_letter' => '/\bH[\s:=]+(\d+\.?\d*)\s*(m|cm|mm|ft|feet|in|inches)\b/i',
            // Dimension context patterns
            'dimensions_labeled' => '/(?:dimensions?|size|measurements?)[\s:=]+(\d+\.?\d*)\s*[×x×X*]\s*(\d+\.?\d*)\s*[×x×X*]\s*(\d+\.?\d*)\s*(m|cm|mm|ft|feet|in|inches)?/i',
            'dimensions_lwh' => '/(?:L\s*[×x×X*]\s*W\s*[×x×X*]\s*H|LWH)[\s:=]*(\d+\.?\d*)\s*[×x×X*]\s*(\d+\.?\d*)\s*[×x×X*]\s*(\d+\.?\d*)\s*(m|cm|mm|ft|feet|in|inches)?/i',
            'dimensions_parentheses' => '/\((\d+\.?\d*)\s*[×x×X*]\s*(\d+\.?\d*)\s*[×x×X*]\s*(\d+\.?\d*)\s*(m|cm|mm|ft|feet|in|inches)?\)/',
            'weight_kg' => '/(?:total\s+weight\s+of\s+approximately\s+)?(\d+\.?\d*)\s*(kg|KG|Kg|kilo|kilos|kilogram|kilograms)/',
            'weight_lbs' => '/(\d+\.?\d*)\s*(lbs|LBS|lb|LB|pounds|POUNDS)/',
            'mileage_km' => '/(\d+[\d,\.]*)\s*(km|KM|kilometer|kilometers|kilometre|kilometres)/',
            'mileage_miles' => '/(\d+[\d,\.]*)\s*(miles|mi|MI|mile)/',
            'fuel_types' => '/\b(diesel|petrol|gasoline|benzine|electric|hybrid|lpg|cng|gas)\b/i',
            'transmission' => '/\b(automatic|manual|auto|stick|CVT|cvt)\b/i',
            'condition' => '/\b(new|used|pre[\s-]?owned|second[\s-]?hand|damaged|accident|salvage)\b/i',
            'colors' => '/\b(black|white|silver|grey|gray|red|blue|green|yellow|orange|brown|beige|gold|bronze|purple|metallic|pearl)\b/i',
            // NEW: Vehicle in parentheses pattern for "(Suzuki Samurai plus RS-Camp caravan)"
            'vehicle_parentheses' => '/\(([^)]+)\)/i',
            // NEW: Direct vehicle name pattern for "Suzuki Samurai plus..."
            'vehicle_direct' => '/\b([A-Z][a-z]+\s+[A-Z][a-z]+)\s+plus\b/i'
        ];
        
        $this->shippingPatterns = [
            'route_from_to' => '/from\s+([A-Za-z\s,\-\.]+?)\s+to\s+([A-Za-z\s,\-\.]+?)(?:[.,;]|\s+(?:incl|including|with)|$)/i',
            // NEW: German route pattern for "ab Deutschland nach X"
            'route_german' => '/ab\s+([A-Za-zÄÖÜäöüß\s,\-\.]+?)\s+nach\s+([A-Za-zÄÖÜäöüß\s,\-\.]+?)(?:[.,;]|\s|$)/i',
            'route_french' => '/de\s+([A-Za-zÀ-ÿ\s,\-\.]+?)\s+vers\s+([A-Za-zÀ-ÿ\s,\-\.]+?)(?:[.,;]|\s|par|$)/i',
            'route_dutch' => '/(?:vanaf|van)\s+([A-Za-zÀ-ÿ\s,\-\.]+?)\s+naar\s+([A-Za-zÀ-ÿ\s,\-\.]+?)(?:\s+(?:incl|inclusief|met)|[.,;]|$)/i',
            'route_arrow' => '/([A-Za-z\s,\-\.]+?)\s*[-–—→>\s]+\s*([A-Za-z\s,\-\.]+?)(?:[.,;]|\s|$)/',
            'origin' => '/(?:origin|from|loading|pickup|departure|vanaf|van):\s*([A-Za-z\s,\-\.]+?)(?:[.,;\n]|$)/i',
            'destination' => '/(?:destination|to|delivery|discharge|arrival|naar):\s*([A-Za-z\s,\-\.]+?)(?:[.,;\n]|$)/i',
            // NEW: Handle "to X or Y" pattern for destinations
            'destination_options' => '/to\s+([A-Za-z\s,\-\.]+?)\s+or\s+([A-Za-z\s,\-\.]+?)(?:[.,;\n]|$)/i',
            // NEW: German destination options pattern for "nach X oder Y"
            'destination_options_german' => '/nach\s+([A-Za-zÄÖÜäöüß\s,\-\.]+?)\s+oder\s+([A-Za-zÄÖÜäöüß\s,\-\.]+?)(?:[.,;\n]|$)/i',
            'pol' => '/POL:\s*([A-Za-z\s,\-\.]+?)(?:[.,;\n]|$)/i',
            'pod' => '/POD:\s*([A-Za-z\s,\-\.]+?)(?:[.,;\n]|$)/i',
            'roro' => '/\b(ro[\s-]?ro|RORO|RoRo|roll[\s-]?on[\s-]?roll[\s-]?off)\b/i',
            'container_20' => '/\b(20)\s*(ft|FT|feet|FEET|\')\s*(container|CONTAINER|ctr|CTR)\b/i',
            'container_40' => '/\b(40)\s*(ft|FT|feet|FEET|\')\s*(container|CONTAINER|ctr|CTR)\b/i',
            'container_40hc' => '/\b(40)\s*(hc|HC|high[\s-]?cube)\b/i'
        ];
        
        $this->contactPatterns = [
            'email' => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
            'phone_international' => '/\+?\d{1,3}[\s\-\.]?\(?\d{2,4}\)?[\s\-\.]?\d{3,4}[\s\-\.]?\d{3,4}/',
            'phone_us' => '/\b\d{3}[\s\-\.]\d{3}[\s\-\.]\d{4}\b/',
            'name_from_email' => '/^(.+?)\s*<(.+?)>$/',
            'signature_name' => '/\n\s*([A-Za-zÀ-ÿ]{2,}(?:\s+[A-Za-zÀ-ÿ]{2,}){1,2})\s*\n?(?:\s*$|(?=\s*(?:email|tel|phone|@|\+)))/i',
            'french_je_suis' => '/\b(?:je\s+suis|moi\s+c\'est)\s+([A-ZÉÈÊÂÀÔÎÛÄËÏÖÜÇ][\p{L}\'\-]+(?:\s+[A-ZÉÈÊÂÀÔÎÛÄËÏÖÜÇ]?[a-zà-ÿ\'\-]+){0,2})(?=\s+(?:et|de|du|des|le|la|les|un|une|à|dans|pour|avec|sur|par|que|qui|comme|très|bien|mais|ou|car|donc|alors)\b|[.,;:]|$)/iu',
            'company_domain' => '/@([a-zA-Z0-9.-]+)\./'
        ];
        
        $this->datePatterns = [
            'date_dmy' => '/(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2,4})/',
            'date_ymd' => '/(\d{4})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})/',
            'date_text' => '/(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+(\d{1,2}),?\s+(\d{4})/i'
        ];
        
        $this->pricePatterns = [
            'currency_before' => '/(\$|€|£|USD|EUR|GBP)\s*(\d+[\d,\.]*\d*)/',
            'currency_after' => '/(\d+[\d,\.]*\d*)\s*(\$|€|£|USD|EUR|GBP)/',
            'amount_only' => '/\b(\d+[\d,\.]*\d*)\b/'
        ];
    }
    
    /**
     * Extract vehicle data using patterns and database matching
     */
    private function extractVehicleData(string $content): array
    {
        $vehicle = [];
        
        // Extract VIN first (most reliable)
        if (preg_match($this->vehiclePatterns['vin'], $content, $matches)) {
            $vehicle['vin'] = $matches[1];
            
            // Try to decode VIN for additional info
            $vinData = $this->vehicleDb->decodeVIN($vehicle['vin']);
            if ($vinData) {
                $vehicle['vin_decoded'] = $vinData;
                $vehicle['year'] = $vinData['year'] ?? $vehicle['year'] ?? null;
            }
        }
        
        // Extract equipment/vehicle type first (before brand/model extraction)
        $equipmentInfo = $this->extractEquipmentInfo($content);
        if ($equipmentInfo) {
            $vehicle = array_merge($vehicle, $equipmentInfo);
        }
        
        // Extract brand and model using database patterns
        $brandModelPatterns = $this->vehicleDb->getBrandModelPatterns();
        foreach ($brandModelPatterns as $pattern) {
            if (preg_match($pattern['regex'], $content, $matches)) {
                $vehicle['brand'] = $pattern['brand'];
                $vehicle['model'] = $pattern['model'];
                $vehicle['extraction_pattern'] = $pattern['pattern'];
                break; // Use first (longest) match
            }
        }
        
        // NEW: Check for vehicle info patterns before fallback patterns
        if (empty($vehicle['brand']) || empty($vehicle['model'])) {
            // Try parentheses pattern first for full vehicle context
            if (preg_match($this->vehiclePatterns['vehicle_parentheses'], $content, $matches)) {
                $vehicleInfo = trim($matches[1]);
                Log::info('Vehicle info found in parentheses', ['info' => $vehicleInfo]);
                
                // Parse "Suzuki Samurai plus RS-Camp caravan" format
                $parsedInfo = $this->parseVehicleInfo($vehicleInfo);
                if ($parsedInfo) {
                    $vehicle = array_merge($vehicle, $parsedInfo);
                }
            }
            // Try direct vehicle pattern if parentheses didn't work
            elseif (preg_match($this->vehiclePatterns['vehicle_direct'], $content, $matches)) {
                $vehicleName = trim($matches[1]);
                Log::info('Direct vehicle name found', ['name' => $vehicleName]);
                
                // Parse "Brand Model" format
                $nameParts = explode(' ', $vehicleName);
                if (count($nameParts) >= 2) {
                    $vehicle['brand'] = $nameParts[0];
                    $vehicle['model'] = implode(' ', array_slice($nameParts, 1));
                    $vehicle['extraction_pattern'] = 'vehicle_direct';
                    $vehicle['extraction_source'] = 'direct_text';
                    
                    // Also check if there's additional context in parentheses nearby
                    if (preg_match('/\(([^)]*caravan[^)]*)\)/i', $content, $contextMatches)) {
                        $vehicle['additional_info'] = trim($contextMatches[1]);
                        $vehicle['type'] = 'Trailer';
                        $vehicle['category'] = 'recreational_vehicle';
                    }
                }
            }
        }

        // Fallback: Extract brand and model using common patterns (when database is empty)
        if (empty($vehicle['brand']) || empty($vehicle['model'])) {
            $commonPatterns = $this->getCommonVehiclePatterns();
            foreach ($commonPatterns as $pattern) {
                if (preg_match($pattern['regex'], $content, $matches)) {
                    $vehicle['brand'] = $pattern['brand'];
                    
                    // If the pattern captured a model, use it; otherwise use the default
                    if (isset($matches[1]) && !empty(trim($matches[1]))) {
                        $vehicle['model'] = trim($matches[1]);
                    } else {
                        $vehicle['model'] = $pattern['model'];
                    }
                    
                    $vehicle['extraction_pattern'] = $pattern['pattern'];
                    $vehicle['extraction_source'] = 'fallback_patterns';
                    break; // Use first match
                }
            }
        }        // Extract year (context-aware)
        if (empty($vehicle['year'])) {
            // First, try to extract model year specifically
            if (preg_match('/Model:\s*(\d{4})/i', $content, $matches)) {
                $modelYear = (int)$matches[1];
                if ($modelYear >= 1900 && $modelYear <= date('Y')) {
                    $vehicle['year'] = $modelYear;
                    $vehicle['year_source'] = 'model_pattern';
                }
            }
            
            // If no model year found, use general year extraction
            if (empty($vehicle['year']) && preg_match_all($this->vehiclePatterns['year'], $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $year = (int)$match[0];
                    $position = $match[1];
                    
                    if ($this->isVehicleYear($year, $content, $position)) {
                        $vehicle['year'] = $year;
                        $vehicle['year_source'] = 'context_pattern';
                        break;
                    }
                }
            }
        }
        
        // Extract engine specifications
        if (preg_match($this->vehiclePatterns['engine_cc'], $content, $matches)) {
            $vehicle['engine_cc'] = (int)$matches[1];
        }
        
        // Extract dimensions (enhanced with weight extraction)
        $dimensionsData = $this->extractDimensions($content);
        if ($dimensionsData) {
            $vehicle['dimensions'] = $dimensionsData;
            
            // If weight was found in dimension context, add it
            if (isset($dimensionsData['weight_kg'])) {
                $vehicle['weight_kg'] = $dimensionsData['weight_kg'];
                unset($vehicle['dimensions']['weight_kg']); // Remove from dimensions array
            }
        }
        
        // Extract weight separately if not found in dimensions
        if (!isset($vehicle['weight_kg'])) {
            $vehicle['weight_kg'] = $this->extractWeight($content);
        }
        
        // Extract mileage
        $vehicle['mileage_km'] = $this->extractMileage($content);
        
        // Extract fuel type
        if (preg_match($this->vehiclePatterns['fuel_types'], $content, $matches)) {
            $vehicle['fuel_type'] = $this->standardizeFuelType($matches[1]);
        }
        
        // Extract transmission
        if (preg_match($this->vehiclePatterns['transmission'], $content, $matches)) {
            $vehicle['transmission'] = $this->standardizeTransmission($matches[1]);
        }
        
        // Extract condition
        if (preg_match($this->vehiclePatterns['condition'], $content, $matches)) {
            $vehicle['condition'] = $this->standardizeCondition($matches[1]);
        }
        
        // Default to "used" if no condition specified (typical for personal vehicle shipments)
        if (empty($vehicle['condition'])) {
            $vehicle['condition'] = 'used';
        }
        
        // Create full description if additional_info exists
        if (!empty($vehicle['additional_info'])) {
            $vehicle['full_description'] = ($vehicle['brand'] ?? '') . ' ' . ($vehicle['model'] ?? '') . ' connected to ' . $vehicle['additional_info'];
        }
        
        // Extract color
        if (preg_match($this->vehiclePatterns['colors'], $content, $matches)) {
            $vehicle['color'] = strtolower($matches[1]);
        }
        
        return array_filter($vehicle); // Remove empty values
    }
    
    /**
     * Parse vehicle information from parentheses like "(Suzuki Samurai plus RS-Camp caravan)"
     */
    private function parseVehicleInfo(string $vehicleInfo): ?array
    {
        $result = [];
        
        // Handle "Brand Model plus other info" format
        if (preg_match('/^([A-Za-z]+)\s+([A-Za-z0-9\s]+?)\s+plus\s+(.+)$/i', $vehicleInfo, $matches)) {
            $result['brand'] = trim($matches[1]);
            $result['model'] = trim($matches[2]);
            $result['additional_info'] = trim($matches[3]);
            
            // Check if additional info contains vehicle type
            if (preg_match('/\b(trailer|caravan|camper|rv)\b/i', $result['additional_info'], $typeMatch)) {
                $result['type'] = ucfirst(strtolower($typeMatch[1]));
                $result['category'] = 'recreational_vehicle';
            }
            
            Log::info('Parsed vehicle info from parentheses', $result);
            return $result;
        }
        
        // Handle simple "Brand Model" format
        if (preg_match('/^([A-Za-z]+)\s+([A-Za-z0-9\s]+)$/i', $vehicleInfo, $matches)) {
            $result['brand'] = trim($matches[1]);
            $result['model'] = trim($matches[2]);
            
            Log::info('Parsed simple vehicle info from parentheses', $result);
            return $result;
        }
        
        return null;
    }
    
    /**
     * Extract equipment/vehicle type information
     */
    private function extractEquipmentInfo(string $content): ?array
    {
        $equipment = [];
        
        // Equipment type patterns (prioritize heavy equipment)
        $equipmentPatterns = [
            '/motorgrader/i' => ['type' => 'Motorgrader', 'category' => 'heavy_equipment'],
            '/grader/i' => ['type' => 'Grader', 'category' => 'heavy_equipment'],
            '/excavator/i' => ['type' => 'Excavator', 'category' => 'heavy_equipment'],
            '/bulldozer/i' => ['type' => 'Bulldozer', 'category' => 'heavy_equipment'],
            '/wheel\s*loader/i' => ['type' => 'Wheel Loader', 'category' => 'heavy_equipment'],
            '/loader/i' => ['type' => 'Loader', 'category' => 'heavy_equipment'],
            '/crane/i' => ['type' => 'Crane', 'category' => 'heavy_equipment'],
            '/dump\s*truck/i' => ['type' => 'Dump Truck', 'category' => 'commercial_vehicle'],
            '/truck/i' => ['type' => 'Truck', 'category' => 'commercial_vehicle'],
            '/forklift/i' => ['type' => 'Forklift', 'category' => 'warehouse_equipment'],
            '/tractor/i' => ['type' => 'Tractor', 'category' => 'agricultural'],
        ];
        
        foreach ($equipmentPatterns as $pattern => $info) {
            if (preg_match($pattern, $content)) {
                $equipment['type'] = $info['type'];
                $equipment['category'] = $info['category'];
                $equipment['make'] = $info['type']; // Use as make if no brand found
                $equipment['model'] = $info['type']; // Use as model if no specific model found
                
                Log::info('Equipment type identified', [
                    'type' => $info['type'],
                    'category' => $info['category']
                ]);
                break;
            }
        }
        
        return !empty($equipment) ? $equipment : null;
    }
    
    /**
     * Extract shipping/route data
     */
    private function extractShippingData(string $content): array
    {
        $shipping = [];
        
        // Check for destination options patterns first (more specific)
        if (preg_match($this->shippingPatterns['destination_options_german'], $content, $matches)) {
            $option1 = trim($matches[1]);
            $option2 = trim($matches[2]);
            
            // For German pattern, we also need to extract the origin from "ab X"
            if (preg_match('/ab\s+([A-Za-zÄÖÜäöüß\s,\-\.]+?)\s+nach/i', $content, $originMatches)) {
                $shipping['origin'] = trim($originMatches[1]);
            }
            
            $shipping['destination'] = $option1;
            $shipping['destination_options'] = [$option1, $option2];
            
            Log::info('German destination options found', [
                'primary' => $option1,
                'alternative' => $option2,
                'origin' => $shipping['origin'] ?? null
            ]);
        } elseif (preg_match($this->shippingPatterns['destination_options'], $content, $matches)) {
            $option1 = trim($matches[1]);
            $option2 = trim($matches[2]);
            
            $shipping['destination'] = $option1;
            $shipping['destination_options'] = [$option1, $option2];
            
            Log::info('Destination options found', [
                'primary' => $option1,
                'alternative' => $option2
            ]);
        }
        
        // Only check route patterns if we haven't found destination options
        elseif (preg_match($this->shippingPatterns['route_from_to'], $content, $matches)) {
            $shipping['origin'] = trim($matches[1]);
            $shipping['destination'] = trim($matches[2]);
        } elseif (preg_match($this->shippingPatterns['route_german'], $content, $matches)) {
            $shipping['origin'] = trim($matches[1]);
            $shipping['destination'] = trim($matches[2]);
        } elseif (preg_match($this->shippingPatterns['route_french'], $content, $matches)) {
            $shipping['origin'] = trim($matches[1]);
            $shipping['destination'] = trim($matches[2]);
        } elseif (preg_match($this->shippingPatterns['route_dutch'], $content, $matches)) {
            $shipping['origin'] = trim($matches[1]);
            $shipping['destination'] = trim($matches[2]);
        } elseif (preg_match($this->shippingPatterns['route_arrow'], $content, $matches)) {
            $shipping['origin'] = trim($matches[1]);
            $shipping['destination'] = trim($matches[2]);
        }
        
        // Extract individual origin/destination
        if (empty($shipping['origin']) && preg_match($this->shippingPatterns['origin'], $content, $matches)) {
            $shipping['origin'] = trim($matches[1]);
        }
        if (empty($shipping['destination']) && preg_match($this->shippingPatterns['destination'], $content, $matches)) {
            $shipping['destination'] = trim($matches[1]);
        }
        
        // Extract ports
        if (preg_match($this->shippingPatterns['pol'], $content, $matches)) {
            $shipping['origin_port'] = trim($matches[1]);
        }
        if (preg_match($this->shippingPatterns['pod'], $content, $matches)) {
            $shipping['destination_port'] = trim($matches[1]);
        }
        
        // Extract shipping type
        if (preg_match($this->shippingPatterns['roro'], $content)) {
            $shipping['shipping_type'] = 'roro';
        } elseif (preg_match($this->shippingPatterns['container_40hc'], $content)) {
            $shipping['shipping_type'] = 'container';
            $shipping['container_size'] = '40hc';
        } elseif (preg_match($this->shippingPatterns['container_40'], $content)) {
            $shipping['shipping_type'] = 'container';
            $shipping['container_size'] = '40ft';
        } elseif (preg_match($this->shippingPatterns['container_20'], $content)) {
            $shipping['shipping_type'] = 'container';
            $shipping['container_size'] = '20ft';
        }
        
        return array_filter($shipping);
    }
    
    /**
     * Extract contact information
     */
    private function extractContactData(string $content): array
    {
        $contact = [];
        
        // Extract email
        if (preg_match($this->contactPatterns['email'], $content, $matches)) {
            $contact['email'] = $matches[0];
            
            // Extract name from email format "Name <email@domain.com>"
            $emailContext = substr($content, max(0, strpos($content, $matches[0]) - 50), 100);
            if (preg_match($this->contactPatterns['name_from_email'], $emailContext, $nameMatch)) {
                $contact['name'] = trim($nameMatch[1], '"\'');
            }
            
            // Extract company from domain
            if (preg_match($this->contactPatterns['company_domain'], $contact['email'], $domainMatch)) {
                $domain = $domainMatch[1];
                $domainParts = explode('.', $domain);
                if (!in_array($domainParts[0], ['gmail', 'yahoo', 'hotmail', 'outlook', 'live'])) {
                    $contact['company'] = ucfirst($domainParts[0]);
                }
            }
        }
        
        // Extract phone number
        if (preg_match($this->contactPatterns['phone_international'], $content, $matches)) {
            $contact['phone'] = preg_replace('/[\s\-\(\)]/', '', $matches[0]);
        } elseif (preg_match($this->contactPatterns['phone_us'], $content, $matches)) {
            $contact['phone'] = preg_replace('/[\s\-\.]/', '', $matches[0]);
        }
        
        // Extract name from signature (if not already found from email)
        if (empty($contact['name']) && preg_match($this->contactPatterns['signature_name'], $content, $matches)) {
            $name = trim($matches[1]);
            // Validate that it looks like a real name (not common words)
            $commonWords = ['merci', 'cordialement', 'regards', 'best', 'sincerely', 'thanks'];
            if (!in_array(strtolower($name), $commonWords)) {
                $contact['name'] = $name;
            }
        }
        
        // Extract name from French "je suis" pattern
        if (empty($contact['name']) && preg_match($this->contactPatterns['french_je_suis'], $content, $matches)) {
            $name = trim($matches[1]);
            // Validate that it looks like a real name (not common words)
            $commonWords = ['merci', 'cordialement', 'regards', 'best', 'sincerely', 'thanks', 'content', 'heureux'];
            if (!in_array(strtolower($name), $commonWords) && strlen($name) >= 3) {
                $contact['name'] = $name;
            }
        }
        
        // Post-processing normalization for names
        if (!empty($contact['name'])) {
            // Collapse multiple spaces
            $contact['name'] = preg_replace('/\s+/', ' ', trim($contact['name']));
            // Uppercase first letters, lowercase rest
            $contact['name'] = mb_convert_case($contact['name'], MB_CASE_TITLE, "UTF-8");
        }
        
        return array_filter($contact);
    }
    
    /**
     * Extract date information
     */
    private function extractDateData(string $content): array
    {
        $dates = [];
        
        // Define date context keywords
        $dateContexts = [
            'pickup' => ['pickup', 'collection', 'collect', 'loading'],
            'delivery' => ['delivery', 'arrival', 'deliver', 'discharge'],
            'etd' => ['etd', 'departure', 'sailing'],
            'eta' => ['eta', 'arrival', 'estimated arrival']
        ];
        
        foreach ($dateContexts as $type => $keywords) {
            foreach ($keywords as $keyword) {
                $pattern = '/\b' . preg_quote($keyword, '/') . '\b[:\s]*([^\n]{1,50})/i';
                if (preg_match($pattern, $content, $contextMatch)) {
                    $dateText = $contextMatch[1];
                    
                    // Try different date patterns
                    if (preg_match($this->datePatterns['date_dmy'], $dateText, $dateMatch)) {
                        $dates[$type . '_date'] = $dateMatch[0];
                        break;
                    } elseif (preg_match($this->datePatterns['date_ymd'], $dateText, $dateMatch)) {
                        $dates[$type . '_date'] = $dateMatch[0];
                        break;
                    } elseif (preg_match($this->datePatterns['date_text'], $dateText, $dateMatch)) {
                        $dates[$type . '_date'] = $dateMatch[0];
                        break;
                    }
                }
            }
        }
        
        return $dates;
    }
    
    /**
     * Extract pricing information
     */
    private function extractPricingData(string $content): array
    {
        $pricing = [];
        
        // Try currency before amount pattern
        if (preg_match($this->pricePatterns['currency_before'], $content, $matches)) {
            $pricing['currency'] = $this->standardizeCurrency($matches[1]);
            $pricing['amount'] = (float)str_replace(',', '', $matches[2]);
        }
        // Try currency after amount pattern
        elseif (preg_match($this->pricePatterns['currency_after'], $content, $matches)) {
            $pricing['amount'] = (float)str_replace(',', '', $matches[1]);
            $pricing['currency'] = $this->standardizeCurrency($matches[2]);
        }
        
        // Extract incoterms
        $incoterms = ['FOB', 'CIF', 'CFR', 'EXW', 'DDP', 'DAP'];
        foreach ($incoterms as $term) {
            if (preg_match('/\b' . $term . '\b/i', $content)) {
                $pricing['incoterm'] = $term;
                break;
            }
        }
        
        return array_filter($pricing);
    }
    
    /**
     * Helper methods
     */
    private function extractDimensions(string $content): ?array
    {
        Log::info('Attempting dimension extraction', ['content_length' => strlen($content)]);
        
        // Enhanced patterns for dimension extraction (including European comma format)
        $patterns = [
            // NEW Pattern: German "800cm lang, 204cm breit, 232cm hoch" format  
            'separated_dimensions_german' => $this->vehiclePatterns['dimensions_separated_german'],
            // NEW Pattern: English "800cm long, 204cm wide, 232cm high" format
            'separated_dimensions' => $this->vehiclePatterns['dimensions_separated'],
            
            // Pattern 1: 10.06m x 2.52m x 3.12m or 10,06m x 2,52m x 3,12m
            'european_comma_format' => '/(\d+[,.]?\d*)\s*m?\s*[x×]\s*(\d+[,.]?\d*)\s*m?\s*[x×]\s*(\d+[,.]?\d*)\s*m?/i',
            
            // Pattern 2: L: 10.06 W: 2.52 H: 3.12
            'lwh_labeled' => '/[lL](?:ength)?[:\s]+(\d+[,.]?\d*)\s*m?\s*[wW](?:idth)?[:\s]+(\d+[,.]?\d*)\s*m?\s*[hH](?:eight)?[:\s]+(\d+[,.]?\d*)\s*m?/i',
            
            // Pattern 3: 10.06 x 2.52 x 3.12 (with spaces)
            'spaced_format' => '/(\d+[,.]?\d*)\s+x\s+(\d+[,.]?\d*)\s+x\s+(\d+[,.]?\d*)/i',
            
            // Pattern 4: dimensions with comma as decimal separator specifically
            'comma_decimal' => '/(\d+,\d+)\s*m?\s*[x×]\s*(\d+,\d+)\s*m?\s*[x×]\s*(\d+,\d+)\s*m?/i',
            
            // Original patterns (keeping for backward compatibility)
            'dimensions_labeled' => $this->vehiclePatterns['dimensions_labeled'],
            'dimensions_lwh' => $this->vehiclePatterns['dimensions_lwh'], 
            'dimensions_parentheses' => $this->vehiclePatterns['dimensions_parentheses'],
            'dimensions_metric' => $this->vehiclePatterns['dimensions_metric'],
            'dimensions_imperial' => $this->vehiclePatterns['dimensions_imperial']
        ];
        
        foreach ($patterns as $patternName => $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                Log::info("Dimension pattern matched", [
                    'pattern' => $patternName,
                    'matches' => $matches,
                    'raw_text' => substr($content, 0, 200)
                ]);
                
                // Handle the new separated dimensions format
                if ($patternName === 'separated_dimensions_german' || $patternName === 'separated_dimensions') {
                    $length = (float) str_replace(',', '.', $matches[1]);
                    $width = (float) str_replace(',', '.', $matches[2]);
                    $height = (float) str_replace(',', '.', $matches[3]);
                    $unit = 'cm'; // These patterns are specifically for cm
                } else {
                    // Convert comma to dot for decimal numbers
                    $length = (float) str_replace(',', '.', $matches[1]);
                    $width = (float) str_replace(',', '.', $matches[2]);
                    $height = (float) str_replace(',', '.', $matches[3]);
                    $unit = isset($matches[4]) ? strtolower(trim($matches[4])) : 'm';
                }
                
                // Validate dimensions (reasonable ranges for equipment/vehicles)
                // For cm values, allow larger ranges; for m values, use smaller ranges
                $maxLength = ($unit === 'cm') ? 5000 : 100;  // 50m max for cm, 100m for other units
                $maxWidth = ($unit === 'cm') ? 1000 : 50;    // 10m max for cm, 50m for other units  
                $maxHeight = ($unit === 'cm') ? 1000 : 50;   // 10m max for cm, 50m for other units
                
                if ($length > 0 && $length < $maxLength && 
                    $width > 0 && $width < $maxWidth && 
                    $height > 0 && $height < $maxHeight) {
                    
                    // Convert to meters
                    $dimensions = $this->convertDimensionsToMeters($length, $width, $height, $unit);
                    
                    if ($this->validateDimensions($dimensions)) {
                        // Try to extract weight from the same context
                        $weight = $this->extractWeightFromDimensionContext($content);
                        if ($weight) {
                            $dimensions['weight_kg'] = $weight;
                        }
                        
                        Log::info('Valid dimensions extracted', [
                            'dimensions' => $dimensions,
                            'pattern_used' => $patternName,
                            'source_text' => substr($content, 0, 200)
                        ]);
                        return $dimensions;
                    }
                }
            }
        }
        
        // Also try to extract weight if present in the same context
        $weight = $this->extractWeightFromDimensionContext($content);
        
        // Try individual labeled dimensions (L:, W:, H:)
        $individualDimensions = $this->extractIndividualDimensions($content);
        if ($individualDimensions && $this->validateDimensions($individualDimensions)) {
            if ($weight) {
                $individualDimensions['weight_kg'] = $weight;
            }
            Log::info('Individual dimensions extracted', $individualDimensions);
            return $individualDimensions;
        }
        
        Log::info('No dimensions found in content');
        return null;
    }
    
    /**
     * Extract weight specifically from dimension context (like "10,06m x 2,52m x 3,12m / 18.750 kg")
     */
    private function extractWeightFromDimensionContext(string $content): ?float
    {
        // Look for weight patterns near dimension patterns
        $weightPatterns = [
            '/\/\s*(\d{1,3}\.?\d{3})\s*kg/i', // After slash like "/ 18.750 kg" - prioritize this pattern
            '/(\d+[,.]?\d*)\s*kg/i',
            '/(\d+[\s,.]?\d+)\s*kg/i', // Handle "18.750" or "18 750" format
            '/weight[:\s]+(\d+[,.]?\d*)\s*kg/i',
        ];
        
        foreach ($weightPatterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $weightStr = $matches[1];
                
                Log::info('Weight extraction attempt', [
                    'pattern' => $pattern,
                    'raw_match' => $weightStr,
                    'content_context' => substr($content, max(0, strpos($content, $matches[0]) - 20), 40)
                ]);
                
                // Handle European heavy equipment weight format (18.750 = 18750kg)
                if (preg_match('/^\d{1,3}\.\d{3}$/', $weightStr)) {
                    // Format like 18.750 - this is thousands separator for heavy equipment
                    $weight = (float) str_replace('.', '', $weightStr);
                    Log::info('Interpreted as thousands-separated weight', [
                        'original' => $weightStr,
                        'interpreted' => $weight
                    ]);
                } elseif (preg_match('/^\d+,\d{1,2}$/', $weightStr)) {
                    // Format like 18,75 - this is decimal separator, convert to dot
                    $weight = (float) str_replace(',', '.', $weightStr);
                    Log::info('Interpreted as decimal weight', [
                        'original' => $weightStr,
                        'interpreted' => $weight
                    ]);
                } else {
                    // Standard format - remove spaces and convert comma to dot for decimals
                    $weight = (float) str_replace([',', ' '], ['.', ''], $weightStr);
                    Log::info('Interpreted as standard weight', [
                        'original' => $weightStr,
                        'interpreted' => $weight
                    ]);
                }
                
                if ($weight > 0 && $weight < 1000000) { // Reasonable weight range
                    Log::info('Weight extracted from dimension context', [
                        'weight' => $weight,
                        'raw_match' => $matches[1],
                        'final_weight' => $weight . 'kg'
                    ]);
                    return $weight;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Extract individual dimension fields (Length:, Width:, Height:)
     */
    private function extractIndividualDimensions(string $content): ?array
    {
        $dimensions = [];
        $unit = 'm'; // Default unit
        
        // Extract length - try both full word and single letter patterns
        if (preg_match($this->vehiclePatterns['length_labeled'], $content, $matches)) {
            $dimensions['length'] = (float)$matches[1];
            if (isset($matches[2]) && !empty($matches[2])) {
                $unit = strtolower(trim($matches[2]));
            }
        } elseif (preg_match($this->vehiclePatterns['length_single_letter'], $content, $matches)) {
            $dimensions['length'] = (float)$matches[1];
            if (isset($matches[2]) && !empty($matches[2])) {
                $unit = strtolower(trim($matches[2]));
            }
        }
        
        // Extract width - try both full word and single letter patterns
        if (preg_match($this->vehiclePatterns['width_labeled'], $content, $matches)) {
            $dimensions['width'] = (float)$matches[1];
            if (isset($matches[2]) && !empty($matches[2])) {
                $unit = strtolower(trim($matches[2]));
            }
        } elseif (preg_match($this->vehiclePatterns['width_single_letter'], $content, $matches)) {
            $dimensions['width'] = (float)$matches[1];
            if (isset($matches[2]) && !empty($matches[2])) {
                $unit = strtolower(trim($matches[2]));
            }
        }
        
        // Extract height - try both full word and single letter patterns
        if (preg_match($this->vehiclePatterns['height_labeled'], $content, $matches)) {
            $dimensions['height'] = (float)$matches[1];
            if (isset($matches[2]) && !empty($matches[2])) {
                $unit = strtolower(trim($matches[2]));
            }
        } elseif (preg_match($this->vehiclePatterns['height_single_letter'], $content, $matches)) {
            $dimensions['height'] = (float)$matches[1];
            if (isset($matches[2]) && !empty($matches[2])) {
                $unit = strtolower(trim($matches[2]));
            }
        }
        
        // Need at least 2 dimensions to be useful
        if (count($dimensions) >= 2) {
            $length = $dimensions['length'] ?? 0;
            $width = $dimensions['width'] ?? 0;
            $height = $dimensions['height'] ?? 0;
            
            return $this->convertDimensionsToMeters($length, $width, $height, $unit);
        }
        
        return null;
    }
    
    /**
     * Convert dimensions to meters from various units
     */
    private function convertDimensionsToMeters(float $length, float $width, float $height, string $unit): array
    {
        $multiplier = match($unit) {
            'cm', 'centimeter', 'centimeters' => 0.01,
            'mm', 'millimeter', 'millimeters' => 0.001,
            'ft', 'feet', 'foot' => 0.3048,
            'in', 'inch', 'inches' => 0.0254,
            default => 1 // meters
        };
        
        return [
            'length_m' => round($length * $multiplier, 3),
            'width_m' => round($width * $multiplier, 3),
            'height_m' => round($height * $multiplier, 3),
            'unit_source' => $unit,
            'source' => 'pattern_extraction'
        ];
    }
    
    /**
     * Validate that dimensions are reasonable for a vehicle
     */
    private function validateDimensions(array $dimensions): bool
    {
        $length = $dimensions['length_m'] ?? 0;
        $width = $dimensions['width_m'] ?? 0;
        $height = $dimensions['height_m'] ?? 0;
        
        // Basic range validation for vehicles
        $validLength = $length >= 2.0 && $length <= 15.0; // 2m to 15m
        $validWidth = $width >= 1.0 && $width <= 4.0;    // 1m to 4m  
        $validHeight = $height >= 0.8 && $height <= 4.0; // 0.8m to 4m
        
        $isValid = $validLength && $validWidth && $validHeight;
        
        if (!$isValid) {
            Log::warning('Dimensions failed validation', [
                'dimensions' => $dimensions,
                'validLength' => $validLength,
                'validWidth' => $validWidth, 
                'validHeight' => $validHeight
            ]);
        }
        
        return $isValid;
    }
    
    private function extractWeight(string $content): ?int
    {
        if (preg_match($this->vehiclePatterns['weight_kg'], $content, $matches)) {
            return (int)round((float)str_replace(',', '', $matches[1]));
        }
        
        if (preg_match($this->vehiclePatterns['weight_lbs'], $content, $matches)) {
            $lbs = (float)str_replace(',', '', $matches[1]);
            return (int)round($lbs * 0.453592); // Convert to kg
        }
        
        // NEW: Handle weight in tons (e.g., "1,8t" or "1.8 tons")
        if (preg_match('/(\d+(?:[,\.]\d+)?)\s*(?:tons?|t)\b/i', $content, $matches)) {
            $weight = (float) str_replace(',', '.', $matches[1]);
            return (int)round($weight * 1000); // Convert to kg
        }
        
        // NEW: Handle weight near vehicle context
        if (preg_match('/(?:weight[:\s]*|wiegt[:\s]*|masse[:\s]*)?(\d+(?:,\d+)?)\s*kg\b/i', $content, $matches)) {
            return (int)round((float)str_replace(',', '', $matches[1]));
        }
        
        return null;
    }
    
    private function extractMileage(string $content): ?int
    {
        if (preg_match($this->vehiclePatterns['mileage_km'], $content, $matches)) {
            return (int)str_replace(',', '', $matches[1]);
        }
        
        if (preg_match($this->vehiclePatterns['mileage_miles'], $content, $matches)) {
            $miles = (int)str_replace(',', '', $matches[1]);
            return (int)round($miles * 1.60934); // Convert to km
        }
        
        return null;
    }
    
    private function isVehicleYear(int $year, string $content, int $position): bool
    {
        $currentYear = date('Y');
        
        // Year range validation
        if ($year < 1900 || $year > $currentYear + 2) {
            return false;
        }
        
        // Check context around the year
        $context = substr($content, max(0, $position - 50), 100);
        
        // Prioritize vehicle-specific contexts over document dates
        $vehicleSpecificKeywords = ['model', 'year', 'vehicle', 'car', 'auto', 'manufactured'];
        $documentKeywords = ['date', 'invoice', 'due', 'created', 'issued'];
        
        // Check if it's in a document date context (lower priority)
        $isDocumentDate = false;
        foreach ($documentKeywords as $keyword) {
            if (stripos($context, $keyword) !== false) {
                $isDocumentDate = true;
                break;
            }
        }
        
        // Check if it's in a vehicle context (higher priority)
        $isVehicleYear = false;
        foreach ($vehicleSpecificKeywords as $keyword) {
            if (stripos($context, $keyword) !== false) {
                $isVehicleYear = true;
                break;
            }
        }
        
        // Prefer vehicle years over document dates
        if ($isVehicleYear) {
            return true;
        }
        
        // Only accept document dates if no better vehicle year is found
        // and the year is reasonable for a vehicle (not too recent for model year)
        if ($isDocumentDate && $year <= $currentYear - 1) {
            return true;
        }
        
        return false;
    }
    
    private function standardizeFuelType(string $fuel): string
    {
        $fuel = strtolower(trim($fuel));
        
        return match($fuel) {
            'benzine', 'gasoline', 'gas' => 'petrol',
            'electric' => 'electric',
            'hybrid' => 'hybrid',
            'lpg' => 'lpg',
            'cng' => 'cng',
            default => 'diesel'
        };
    }
    
    private function standardizeTransmission(string $trans): string
    {
        $trans = strtolower(trim($trans));
        
        return match($trans) {
            'auto', 'automatic' => 'automatic',
            'cvt' => 'cvt',
            default => 'manual'
        };
    }
    
    private function standardizeCondition(string $condition): string
    {
        $condition = strtolower(trim($condition));
        
        if (strpos($condition, 'new') !== false) return 'new';
        if (strpos($condition, 'damage') !== false || strpos($condition, 'accident') !== false) return 'damaged';
        
        return 'used';
    }
    
    private function standardizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        
        return match($currency) {
            '$', 'USD' => 'USD',
            '€', 'EUR' => 'EUR',
            '£', 'GBP' => 'GBP',
            default => 'USD'
        };
    }
    
    /**
     * Get common vehicle patterns as fallback when database is empty
     */
    private function getCommonVehiclePatterns(): array
    {
        return [
            // Luxury brands
            [
                'pattern' => 'BENTLEY CONTINENTAL',
                'brand' => 'BENTLEY',
                'model' => 'CONTINENTAL',
                'regex' => '/\bBENTLEY\s+CONTINENTAL\b/i'
            ],
            [
                'pattern' => 'MERCEDES-BENZ',
                'brand' => 'MERCEDES-BENZ',
                'model' => 'UNKNOWN',
                'regex' => '/\bMERCEDES[\s-]*BENZ\s+([A-Z0-9]+)/i'
            ],
            [
                'pattern' => 'BMW',
                'brand' => 'BMW',
                'model' => 'UNKNOWN',
                'regex' => '/\bBMW\s+([\wÀ-ÿ]+(?:\s+[\wÀ-ÿ]*)?)\s*(?:\b(?:de|from|to|vers|for|in|on|at|with|and|et|&)\b|$)/i'
            ],
            [
                'pattern' => 'AUDI',
                'brand' => 'AUDI',
                'model' => 'UNKNOWN',
                'regex' => '/\bAUDI\s+([A-Z0-9]+)/i'
            ],
            [
                'pattern' => 'LEXUS',
                'brand' => 'LEXUS',
                'model' => 'UNKNOWN',
                'regex' => '/\bLEXUS\s+([A-Z0-9]+)/i'
            ],
            // Mass market brands
            [
                'pattern' => 'TOYOTA',
                'brand' => 'TOYOTA',
                'model' => 'UNKNOWN',
                'regex' => '/\bTOYOTA\s+([A-Z0-9]+)/i'
            ],
            [
                'pattern' => 'HONDA',
                'brand' => 'HONDA',
                'model' => 'UNKNOWN',
                'regex' => '/\bHONDA\s+([A-Z0-9]+)/i'
            ],
            [
                'pattern' => 'FORD',
                'brand' => 'FORD',
                'model' => 'UNKNOWN',
                'regex' => '/\bFORD\s+([A-Z0-9]+)/i'
            ],
            [
                'pattern' => 'VOLKSWAGEN',
                'brand' => 'VOLKSWAGEN',
                'model' => 'UNKNOWN',
                'regex' => '/\bVOLKSWAGEN\s+([A-Z0-9]+)/i'
            ],
            [
                'pattern' => 'NISSAN',
                'brand' => 'NISSAN',
                'model' => 'UNKNOWN',
                'regex' => '/\bNISSAN\s+([A-Z0-9]+)/i'
            ],
            // Add more patterns as needed...
        ];
    }
}
