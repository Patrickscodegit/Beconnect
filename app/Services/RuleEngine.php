<?php

namespace App\Services;

use App\Models\Intake;
use App\Models\VinWmi;
use App\Models\VehicleSpec;

class RuleEngine
{
    public function apply(Intake $intake, array $extractedData): array
    {
        logger("Applying business rules for intake {$intake->id}");
        
        $issues = [];
        $verified_count = 0;
        $total_vehicles = count($extractedData['vehicles'] ?? []);
        
        // Validate and enrich vehicle data using our existing database
        foreach ($extractedData['vehicles'] ?? [] as &$vehicle) {
            if (!empty($vehicle['vin'])) {
                // Extract WMI (first 3 characters) from VIN
                $wmi = substr($vehicle['vin'], 0, 3);
                
                // Look up in our VIN WMI database
                $vinWmi = VinWmi::where('wmi', $wmi)->first();
                if ($vinWmi) {
                    // Verify manufacturer matches
                    if (empty($vehicle['make']) || 
                        stripos($vinWmi->manufacturer, $vehicle['make']) !== false ||
                        stripos($vehicle['make'], $vinWmi->manufacturer) !== false) {
                        
                        $vehicle['make'] = $vinWmi->manufacturer; // Use verified manufacturer
                        $vehicle['country_of_manufacture']['value'] = $vinWmi->country;
                        $vehicle['country_of_manufacture']['verified'] = true;
                        $verified_count++;
                    } else {
                        $issues[] = "Manufacturer mismatch for VIN {$vehicle['vin']}: extracted '{$vehicle['make']}' vs database '{$vinWmi->manufacturer}'";
                    }
                } else {
                    $issues[] = "Unknown WMI code '{$wmi}' for VIN {$vehicle['vin']}";
                }
            }
            
            // Cross-reference with vehicle specifications if we have make/model/year
            if (!empty($vehicle['make']) && !empty($vehicle['model']) && !empty($vehicle['year'])) {
                $spec = VehicleSpec::where('make', 'ILIKE', '%' . $vehicle['make'] . '%')
                    ->where('model', 'ILIKE', '%' . $vehicle['model'] . '%')
                    ->where('year', $vehicle['year'])
                    ->first();
                
                if ($spec) {
                    // Fill in missing dimensions from our database
                    if (empty($vehicle['dims_m']['L'])) $vehicle['dims_m']['L'] = (float)$spec->length_m;
                    if (empty($vehicle['dims_m']['W'])) $vehicle['dims_m']['W'] = (float)$spec->width_m;
                    if (empty($vehicle['dims_m']['H'])) $vehicle['dims_m']['H'] = (float)$spec->height_m;
                    if (empty($vehicle['dims_m']['wheelbase'])) $vehicle['dims_m']['wheelbase'] = (float)$spec->wheelbase_m;
                    if (empty($vehicle['weight_kg'])) $vehicle['weight_kg'] = $spec->weight_kg;
                    if (empty($vehicle['engine_cc'])) $vehicle['engine_cc'] = $spec->engine_cc;
                    if (empty($vehicle['fuel_type'])) $vehicle['fuel_type'] = $spec->fuel_type;
                    
                    // Calculate CBM if dimensions are available
                    if ($vehicle['dims_m']['L'] && $vehicle['dims_m']['W'] && $vehicle['dims_m']['H']) {
                        $vehicle['cbm'] = round($vehicle['dims_m']['L'] * $vehicle['dims_m']['W'] * $vehicle['dims_m']['H'], 2);
                    }
                    
                    $verified_count++;
                } else {
                    $issues[] = "No specification data found for {$vehicle['make']} {$vehicle['model']} {$vehicle['year']}";
                }
            }
            
            // Validate required fields
            if (empty($vehicle['make']) || empty($vehicle['model'])) {
                $issues[] = "Missing essential vehicle information: make or model";
            }
        }
        
        // Validate shipping information
        if (empty($extractedData['pol']) || empty($extractedData['pod'])) {
            $issues[] = "Missing port information (POL or POD)";
        }
        
        if (empty($extractedData['shipment_type'])) {
            $issues[] = "Shipment type not determined";
        }
        
        // Validate parties
        if (empty($extractedData['parties']['shipper']['name']) || 
            empty($extractedData['parties']['consignee']['name'])) {
            $issues[] = "Missing shipper or consignee information";
        }
        
        // Update extraction with enriched data
        $intake->extraction->update([
            'raw_json' => $extractedData
        ]);
        
        $all_verified = (count($issues) === 0 && $verified_count === $total_vehicles && $total_vehicles > 0);
        
        // Update intake notes
        $intake->update([
            'notes' => array_merge($intake->notes ?? [], [
                "rules_applied_at_" . now()->format('Y-m-d_H:i:s'),
                "verified_vehicles: {$verified_count}/{$total_vehicles}",
                "issues_found: " . count($issues)
            ])
        ]);
        
        logger("Rules applied for intake {$intake->id}: {$verified_count}/{$total_vehicles} vehicles verified, " . count($issues) . " issues found");
        
        return [
            'all_verified' => $all_verified,
            'verified_count' => $verified_count,
            'total_vehicles' => $total_vehicles,
            'issues' => $issues
        ];
    }
}
