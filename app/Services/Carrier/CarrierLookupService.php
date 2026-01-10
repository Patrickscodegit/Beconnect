<?php

namespace App\Services\Carrier;

use App\Models\ShippingCarrier;
use Illuminate\Support\Facades\DB;

class CarrierLookupService
{
    /**
     * Find carrier by code
     */
    public function findByCode(string $code): ?ShippingCarrier
    {
        return ShippingCarrier::where('code', $code)->first();
    }
    
    /**
     * Find carrier by name (case-insensitive, partial match)
     */
    public function findByName(string $name): ?ShippingCarrier
    {
        $useIlike = DB::getDriverName() === 'pgsql';
        
        $query = ShippingCarrier::query();
        if ($useIlike) {
            $query->where('name', 'ILIKE', "%{$name}%");
        } else {
            $query->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($name) . '%']);
        }
        
        return $query->first();
    }
    
    /**
     * Find carrier by Robaws supplier ID
     */
    public function findByRobawsSupplierId(string $robawsSupplierId): ?ShippingCarrier
    {
        return ShippingCarrier::whereHas('robawsSupplier', function ($q) use ($robawsSupplierId) {
            $q->where('robaws_supplier_id', $robawsSupplierId);
        })->first();
    }
    
    /**
     * Find Grimaldi carrier (common lookup helper)
     */
    public function findGrimaldi(): ?ShippingCarrier
    {
        // Try by code first
        $carrier = $this->findByCode('GRIMALDI');
        if ($carrier) {
            return $carrier;
        }
        
        // Fallback to name search
        return $this->findByName('Grimaldi');
    }
    
    /**
     * Find carrier by code or name (tries both)
     */
    public function findByCodeOrName(string $identifier): ?ShippingCarrier
    {
        // Try code first
        $carrier = $this->findByCode($identifier);
        if ($carrier) {
            return $carrier;
        }
        
        // Fallback to name
        return $this->findByName($identifier);
    }
}
