<?php

namespace App\Services\Robaws;

use App\Models\User;
use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Support\Facades\Log;

class PricingTierSyncService
{
    public function __construct(
        protected RobawsApiClient $apiClient
    ) {}

    /**
     * Push User's pricing tier to the linked Robaws client.
     * When pricing_tier_id is null, pushes "TIER A" (default).
     */
    public function pushUserPricingToRobaws(User $user): bool
    {
        $link = $user->portalLink;
        if (!$link) {
            return true; // No link, nothing to push
        }

        $robawsValue = 'TIER A'; // Default when null
        if ($user->pricing_tier_id && $user->pricingTier) {
            $robawsValue = 'TIER ' . strtoupper($user->pricingTier->code);
        }

        try {
            $this->apiClient->pushClientExtraField(
                (int) $link->robaws_client_id,
                'PRICING',
                $robawsValue
            );
            Log::info('Pushed pricing tier to Robaws', [
                'user_id' => $user->id,
                'robaws_client_id' => $link->robaws_client_id,
                'pricing_value' => $robawsValue,
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to push pricing tier to Robaws', [
                'user_id' => $user->id,
                'robaws_client_id' => $link->robaws_client_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
