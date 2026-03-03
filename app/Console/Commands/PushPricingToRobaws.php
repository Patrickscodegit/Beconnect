<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Robaws\PricingTierSyncService;
use Illuminate\Console\Command;

class PushPricingToRobaws extends Command
{
    protected $signature = 'robaws:push-pricing
        {user_id : The user ID to push pricing for}';

    protected $description = 'Push a user\'s pricing tier to their linked Robaws client.';

    public function handle(): int
    {
        $userId = (int) $this->argument('user_id');
        $user = User::with(['portalLink', 'pricingTier'])->find($userId);

        if (!$user) {
            $this->error("User #{$userId} not found.");
            return self::FAILURE;
        }

        if ($user->role !== 'customer') {
            $this->error("User #{$userId} is not a customer (role: {$user->role}).");
            return self::FAILURE;
        }

        if (!$user->portalLink) {
            $this->error("User #{$userId} has no Robaws portal link.");
            return self::FAILURE;
        }

        try {
            app(PricingTierSyncService::class)->pushUserPricingToRobaws($user);
            $tier = $user->pricingTier ? ('TIER ' . strtoupper($user->pricingTier->code)) : 'TIER C';
            $this->info("Pushed {$tier} to Robaws client #{$user->portalLink->robaws_client_id}.");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
