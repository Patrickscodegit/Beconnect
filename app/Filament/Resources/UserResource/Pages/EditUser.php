<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\Robaws\PricingTierSyncService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('pushPricingToRobaws')
                ->label('Push pricing to Robaws')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn () => $this->record?->role === 'customer' && $this->record?->portalLink)
                ->requiresConfirmation()
                ->modalHeading('Push Pricing Tier to Robaws')
                ->modalDescription('This will update the PRICING extra field on the linked Robaws client with the current pricing tier.')
                ->action(function () {
                    $user = $this->record;
                    if ($user->role !== 'customer' || !$user->portalLink) {
                        Notification::make()->title('No Robaws link')->body('This user is not linked to a Robaws client.')->warning()->send();
                        return;
                    }
                    try {
                        app(PricingTierSyncService::class)->pushUserPricingToRobaws($user);
                        Notification::make()->title('Pricing pushed to Robaws')->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Push failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    public function afterSave(): void
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
