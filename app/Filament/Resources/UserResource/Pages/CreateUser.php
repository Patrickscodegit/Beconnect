<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\Robaws\PricingTierSyncService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    public function afterCreate(): void
    {
        $user = $this->record;
        if ($user->role !== 'customer' || !$user->relationLoaded('portalLink')) {
            $user->load('portalLink');
        }
        if ($user->role === 'customer' && $user->portalLink) {
            try {
                app(PricingTierSyncService::class)->pushUserPricingToRobaws($user);
            } catch (\Throwable $e) {
                Notification::make()
                    ->title('Saved, but pricing sync to Robaws failed')
                    ->body($e->getMessage())
                    ->warning()
                    ->send();
            }
        }
    }
}
