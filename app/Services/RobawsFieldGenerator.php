<?php

namespace App\Services;

use App\Models\QuotationRequest;
use App\Models\QuotationCommodityItem;

class RobawsFieldGenerator
{
    /**
     * Generate the CARGO field for Robaws from commodity items
     * 
     * Example output:
     * 1x Vehicles - Car (Mercedes C-Class, Diesel)
     * 1x Machinery - On Tracks (Caterpillar 320D) + Parts
     * 1x Boat - With Iron Cradle
     * 2x General Cargo - Palletized (Forkliftable)
     */
    public function generateCargoField(QuotationRequest $quotation): string
    {
        // Always use commodity items if available
        if ($quotation->commodityItems && $quotation->commodityItems->count() > 0) {
            $cargoLines = [];
        
        foreach ($quotation->commodityItems as $item) {
            $line = "{$item->quantity}x " . ucfirst(str_replace('_', ' ', $item->commodity_type));
            
            if ($item->commodity_type === 'vehicles') {
                $vehicleDesc = $item->category ? ucwords(str_replace('_', ' ', $item->category)) : '';
                $details = [];
                if ($item->make) $details[] = $item->make;
                if ($item->type_model) $details[] = $item->type_model;
                if ($item->fuel_type) $details[] = ucfirst($item->fuel_type);
                
                if ($vehicleDesc) {
                    $line .= " - {$vehicleDesc}";
                }
                if ($details) {
                    $line .= " (" . implode(' ', $details) . ")";
                }
                
                if ($item->condition) {
                    $line .= " [" . ucfirst($item->condition) . "]";
                }
                
            } elseif ($item->commodity_type === 'machinery') {
                $machineryDesc = $item->category ? ucwords(str_replace('_', ' ', $item->category)) : '';
                $details = [];
                if ($item->make) $details[] = $item->make;
                if ($item->type_model) $details[] = $item->type_model;
                
                if ($machineryDesc) {
                    $line .= " - {$machineryDesc}";
                }
                if ($details) {
                    $line .= " (" . implode(' ', $details) . ")";
                }
                
                if ($item->condition) {
                    $line .= " [" . ucfirst($item->condition) . "]";
                }
                if ($item->has_parts) {
                    $line .= " + Parts";
                }
                
            } elseif ($item->commodity_type === 'boat') {
                $accessories = [];
                if ($item->has_trailer) $accessories[] = 'Trailer';
                if ($item->has_wooden_cradle) $accessories[] = 'Wooden Cradle';
                if ($item->has_iron_cradle) $accessories[] = 'Iron Cradle';
                
                if ($item->condition) {
                    $line .= " [" . ucfirst($item->condition) . "]";
                }
                if ($accessories) {
                    $line .= " - With " . implode(' & ', $accessories);
                }
                
            } else { // general_cargo
                $cargoDesc = $item->category ? ucwords(str_replace('_', ' ', $item->category)) : '';
                $attrs = [];
                if ($item->is_forkliftable) $attrs[] = 'Forkliftable';
                if ($item->is_hazardous) $attrs[] = 'Hazardous';
                if ($item->is_unpacked) $attrs[] = 'Unpacked';
                if ($item->is_ispm15) $attrs[] = 'ISPM15';
                
                if ($cargoDesc) {
                    $line .= " - {$cargoDesc}";
                }
                if ($attrs) {
                    $line .= " (" . implode(', ', $attrs) . ")";
                }
            }
            
            $cargoLines[] = $line;
        }
        
        return implode("\n", $cargoLines);
        }
        
        // Fallback to legacy cargo_description only if no commodity items exist (edge case)
        return $quotation->cargo_description ?? 'Not specified';
    }

    /**
     * Generate the DIM_BEF_DELIVERY field for Robaws from commodity items
     * 
     * Example output:
     * Item 1: 450x180x150cm, CBM: 1.22m³, Weight: 1500kg, Wheelbase: 270cm
     * Item 2: 600x250x280cm, CBM: 4.20m³, Weight: 3500kg, Parts: Yes
     * Item 3: 700x250x180cm, CBM: 3.15m³, Weight: 2500kg, Iron Cradle
     * Item 4: 120x80x100cm, CBM: 0.96m³, Bruto: 850kg, Netto: 800kg
     */
    public function generateDimField(QuotationRequest $quotation): string
    {
        // Always use commodity items if available
        if ($quotation->commodityItems && $quotation->commodityItems->count() > 0) {
            $dimLines = [];
        
        foreach ($quotation->commodityItems as $index => $item) {
            $line = "Item " . ($index + 1) . ": ";
            $details = [];
            
            // Dimensions
            if ($item->length_cm && $item->width_cm && $item->height_cm) {
                $details[] = "{$item->length_cm}x{$item->width_cm}x{$item->height_cm}cm";
            }
            
            // Volume
            if ($item->cbm) {
                $details[] = "CBM: {$item->cbm}m³";
            }
            
            // Weight (single or dual)
            if ($item->bruto_weight_kg && $item->netto_weight_kg) {
                $details[] = "Bruto: {$item->bruto_weight_kg}kg";
                $details[] = "Netto: {$item->netto_weight_kg}kg";
            } elseif ($item->weight_kg) {
                $details[] = "Weight: {$item->weight_kg}kg";
            }
            
            // Wheelbase (vehicles only)
            if ($item->wheelbase_cm) {
                $details[] = "Wheelbase: {$item->wheelbase_cm}cm";
            }
            
            // Special attributes
            if ($item->has_parts) {
                $details[] = "Parts: Yes";
            }
            if ($item->has_trailer) {
                $details[] = "Trailer: Yes";
            }
            if ($item->has_iron_cradle) {
                $details[] = "Iron Cradle";
            }
            if ($item->has_wooden_cradle) {
                $details[] = "Wooden Cradle";
            }
            if ($item->is_hazardous) {
                $details[] = "⚠️ Hazardous";
            }
            
            // Extra info (if provided)
            if ($item->extra_info) {
                $details[] = "Note: " . substr($item->extra_info, 0, 100); // Truncate long notes
            }
            
            $dimLines[] = $line . implode(", ", $details);
        }
        
        return implode("\n", $dimLines);
        }
        
        // Fallback to legacy cargo_dimensions only if no commodity items exist (edge case)
        $dims = [];
        if ($quotation->cargo_dimensions) $dims[] = $quotation->cargo_dimensions;
        return $dims ? implode(', ', $dims) : 'Not specified';
    }

    /**
     * Generate both CARGO and DIM fields and update the quotation
     */
    public function generateAndUpdateFields(QuotationRequest $quotation): void
    {
        $cargoField = $this->generateCargoField($quotation);
        $dimField = $this->generateDimField($quotation);
        
        $quotation->update([
            'cargo_description' => $cargoField,
            'robaws_cargo_field' => $cargoField,
            'robaws_dim_field' => $dimField,
        ]);
    }

    /**
     * Update cargo_description from commodity items
     */
    public function updateCargoDescription(QuotationRequest $quotation): void
    {
        $cargoField = $this->generateCargoField($quotation);

        $quotation->update([
            'cargo_description' => $cargoField,
        ]);
    }

    /**
     * Get summary statistics from commodity items
     */
    public function getCommoditySummary(QuotationRequest $quotation): array
    {
        // Always use commodity items if available
        if ($quotation->commodityItems && $quotation->commodityItems->count() > 0) {
            $summary = [
            'total_items' => $quotation->commodityItems->count(),
            'total_quantity' => $quotation->commodityItems->sum('quantity'),
            'total_cbm' => $quotation->commodityItems->sum('cbm'),
            'total_weight' => 0,
            'has_hazardous' => $quotation->commodityItems->contains('is_hazardous', true),
            'commodity_breakdown' => [],
        ];

        // Calculate total weight (handle both single and dual weight)
        foreach ($quotation->commodityItems as $item) {
            if ($item->bruto_weight_kg) {
                $summary['total_weight'] += $item->bruto_weight_kg * $item->quantity;
            } elseif ($item->weight_kg) {
                $summary['total_weight'] += $item->weight_kg * $item->quantity;
            }
        }

        // Breakdown by commodity type
        $breakdown = $quotation->commodityItems->groupBy('commodity_type');
        foreach ($breakdown as $type => $items) {
            $summary['commodity_breakdown'][$type] = $items->sum('quantity');
        }

        return $summary;
        }
        
        // Fallback only if no commodity items exist (edge case)
        return [
            'total_items' => 0,
            'total_quantity' => 0,
            'total_cbm' => 0,
            'total_weight' => 0,
            'has_hazardous' => false,
        ];
    }
}

