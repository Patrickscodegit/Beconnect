<?php

namespace App\Services\Pricing;

use App\Models\PricingProfile;
use Carbon\Carbon;

class PricingProfileResolver
{
    /**
     * Resolve pricing profile with priority:
     * 1. Client-specific (robaws_client_id set, carrier_id optional)
     * 2. Carrier default (carrier_id set, robaws_client_id null)
     * 3. Global (both null)
     *
     * @param int|null $carrierId
     * @param string|null $robawsClientId
     * @param Carbon|null $date
     * @return PricingProfile|null
     */
    public function resolve(?int $carrierId = null, ?string $robawsClientId = null, ?Carbon $date = null): ?PricingProfile
    {
        $date = $date ?? now();

        // Priority 1: Client-specific profile (robaws_client_id matches, carrier_id optional)
        if ($robawsClientId) {
            $profile = PricingProfile::active($date)
                ->where('robaws_client_id', $robawsClientId)
                ->first();

            if ($profile) {
                return $profile;
            }
        }

        // Priority 2: Carrier default profile
        if ($carrierId) {
            $profile = PricingProfile::active($date)
                ->where('carrier_id', $carrierId)
                ->whereNull('robaws_client_id')
                ->first();

            if ($profile) {
                return $profile;
            }
        }

        // Priority 3: Global profile
        $profile = PricingProfile::active($date)
            ->whereNull('carrier_id')
            ->whereNull('robaws_client_id')
            ->first();

        return $profile;
    }
}
