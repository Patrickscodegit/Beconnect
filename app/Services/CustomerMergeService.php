<?php

namespace App\Services;

use App\Models\RobawsCustomerCache;
use App\Models\Intake;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomerMergeService
{
    /**
     * Merge duplicate customers into a primary record
     * 
     * @param RobawsCustomerCache $primary The record to keep
     * @param array $duplicateIds IDs of records to merge and delete
     * @return array ['success' => bool, 'message' => string, 'merged_count' => int]
     */
    public function merge(RobawsCustomerCache $primary, array $duplicateIds): array
    {
        try {
            DB::beginTransaction();
            
            $duplicates = RobawsCustomerCache::whereIn('id', $duplicateIds)->get();
            
            if ($duplicates->isEmpty()) {
                return [
                    'success' => false,
                    'message' => 'No duplicate records found to merge',
                    'merged_count' => 0,
                ];
            }
            
            $mergedCount = 0;
            $totalIntakes = 0;
            
            foreach ($duplicates as $duplicate) {
                // Don't merge into itself
                if ($duplicate->id === $primary->id) {
                    continue;
                }
                
                // Merge non-null fields from duplicate into primary
                $this->mergeFields($primary, $duplicate);
                
                // Update all intakes to point to primary record
                $intakeCount = Intake::where('robaws_client_id', $duplicate->robaws_client_id)
                    ->update(['robaws_client_id' => $primary->robaws_client_id]);
                
                $totalIntakes += $intakeCount;
                
                // Log the merge
                Log::info('Customer merge', [
                    'primary_id' => $primary->id,
                    'primary_name' => $primary->name,
                    'duplicate_id' => $duplicate->id,
                    'duplicate_robaws_id' => $duplicate->robaws_client_id,
                    'intakes_moved' => $intakeCount,
                ]);
                
                // Delete the duplicate
                $duplicate->delete();
                $mergedCount++;
            }
            
            // Save merged primary record
            $primary->save();
            
            DB::commit();
            
            $message = "Successfully merged {$mergedCount} duplicate(s) into {$primary->name}.";
            if ($totalIntakes > 0) {
                $message .= " {$totalIntakes} intake(s) preserved.";
            }
            
            return [
                'success' => true,
                'message' => $message,
                'merged_count' => $mergedCount,
                'intakes_moved' => $totalIntakes,
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Customer merge failed', [
                'primary_id' => $primary->id,
                'duplicate_ids' => $duplicateIds,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'message' => 'Merge failed: ' . $e->getMessage(),
                'merged_count' => 0,
            ];
        }
    }
    
    /**
     * Merge non-null fields from duplicate into primary
     */
    protected function mergeFields(RobawsCustomerCache $primary, RobawsCustomerCache $duplicate): void
    {
        // Fields to potentially merge (prefer non-null values)
        $fieldsToMerge = [
            'email',
            'phone',
            'mobile',
            'address',
            'street',
            'street_number',
            'city',
            'postal_code',
            'country',
            'country_code',
            'vat_number',
            'website',
            'language',
        ];
        
        foreach ($fieldsToMerge as $field) {
            // If primary doesn't have a value but duplicate does, use duplicate's value
            if (empty($primary->$field) && !empty($duplicate->$field)) {
                $primary->$field = $duplicate->$field;
            }
        }
        
        // Merge metadata (keep both, with primary taking precedence)
        if (!empty($duplicate->metadata)) {
            $primaryMetadata = $primary->metadata ?? [];
            $duplicateMetadata = $duplicate->metadata ?? [];
            
            // Merge arrays, primary metadata takes precedence
            $primary->metadata = array_merge($duplicateMetadata, $primaryMetadata);
        }
    }
    
    /**
     * Preview what will happen in a merge (without executing)
     */
    public function previewMerge(RobawsCustomerCache $primary, array $duplicateIds): array
    {
        $duplicates = RobawsCustomerCache::whereIn('id', $duplicateIds)->get();
        
        $preview = [
            'primary' => [
                'id' => $primary->id,
                'name' => $primary->name,
                'email' => $primary->email,
                'phone' => $primary->phone,
                'city' => $primary->city,
            ],
            'duplicates' => [],
            'fields_to_merge' => [],
            'total_intakes' => 0,
        ];
        
        foreach ($duplicates as $duplicate) {
            if ($duplicate->id === $primary->id) continue;
            
            $intakeCount = Intake::where('robaws_client_id', $duplicate->robaws_client_id)->count();
            
            $preview['duplicates'][] = [
                'id' => $duplicate->id,
                'robaws_client_id' => $duplicate->robaws_client_id,
                'email' => $duplicate->email,
                'phone' => $duplicate->phone,
                'city' => $duplicate->city,
                'intake_count' => $intakeCount,
            ];
            
            $preview['total_intakes'] += $intakeCount;
            
            // Check which fields will be merged
            $fieldsToMerge = ['email', 'phone', 'mobile', 'address', 'city', 'vat_number'];
            foreach ($fieldsToMerge as $field) {
                if (empty($primary->$field) && !empty($duplicate->$field)) {
                    $preview['fields_to_merge'][$field] = $duplicate->$field;
                }
            }
        }
        
        return $preview;
    }
    
    /**
     * Check if it's safe to delete a customer
     */
    public function canSafelyDelete(RobawsCustomerCache $customer): array
    {
        $intakeCount = $customer->intakes()->count();
        
        return [
            'can_delete' => $intakeCount === 0,
            'intake_count' => $intakeCount,
            'warning' => $intakeCount > 0 
                ? "This customer has {$intakeCount} related intake(s). Please merge or reassign them first." 
                : null,
        ];
    }
}

