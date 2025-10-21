<?php

namespace App\Services;

use App\Models\RobawsCustomerCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerDuplicateService
{
    /**
     * Find all duplicate groups (customers with same name)
     */
    public function findDuplicateGroups(): Collection
    {
        return RobawsCustomerCache::select('name', DB::raw('count(*) as duplicate_count'))
            ->groupBy('name')
            ->havingRaw('count(*) > 1')
            ->orderBy('duplicate_count', 'desc')
            ->get();
    }
    
    /**
     * Get all duplicates for a specific customer
     */
    public function getDuplicatesFor(RobawsCustomerCache $customer): Collection
    {
        return RobawsCustomerCache::where('name', $customer->name)
            ->where('id', '!=', $customer->id)
            ->get();
    }
    
    /**
     * Get all customers in a duplicate group by name
     */
    public function getGroupByName(string $name): Collection
    {
        return RobawsCustomerCache::where('name', $name)
            ->orderBy('created_at', 'asc')
            ->get();
    }
    
    /**
     * Suggest which record to keep as primary based on data completeness
     * Priority: most complete data > most intakes > oldest record
     */
    public function suggestPrimaryRecord(Collection $duplicates): RobawsCustomerCache
    {
        // Score each record based on completeness
        $scored = $duplicates->map(function ($customer) {
            $score = 0;
            
            // Fields worth points (more important fields = more points)
            if ($customer->email) $score += 10;
            if ($customer->phone) $score += 8;
            if ($customer->mobile) $score += 6;
            if ($customer->vat_number) $score += 7;
            if ($customer->address) $score += 5;
            if ($customer->city) $score += 3;
            if ($customer->country) $score += 3;
            if ($customer->website) $score += 2;
            
            // Has intakes (very important!)
            $intakeCount = $customer->intakes()->count();
            $score += ($intakeCount * 20); // 20 points per intake
            
            // Prefer records that were synced from Robaws (have proper ID)
            if (!str_starts_with($customer->robaws_client_id, 'NEW_')) {
                $score += 15;
            }
            
            return [
                'customer' => $customer,
                'score' => $score,
                'intake_count' => $intakeCount,
            ];
        });
        
        // Sort by score (highest first), then by oldest
        $best = $scored->sortByDesc('score')->first();
        
        return $best['customer'];
    }
    
    /**
     * Check if a customer has duplicates
     */
    public function hasDuplicates(RobawsCustomerCache $customer): bool
    {
        return RobawsCustomerCache::where('name', $customer->name)
            ->where('id', '!=', $customer->id)
            ->exists();
    }
    
    /**
     * Get duplicate count for a customer
     */
    public function getDuplicateCount(RobawsCustomerCache $customer): int
    {
        return RobawsCustomerCache::where('name', $customer->name)
            ->where('id', '!=', $customer->id)
            ->count();
    }
    
    /**
     * Get total number of duplicate customers (not groups)
     */
    public function getTotalDuplicateCustomersCount(): int
    {
        $duplicateNames = RobawsCustomerCache::select('name')
            ->groupBy('name')
            ->havingRaw('count(*) > 1')
            ->pluck('name');
        
        return RobawsCustomerCache::whereIn('name', $duplicateNames)->count();
    }
}

