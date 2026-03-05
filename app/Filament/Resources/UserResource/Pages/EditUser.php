<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\Robaws\PricingTierSyncService;
use App\Services\Robaws\RobawsCustomerSyncService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('syncFromRobaws')
                ->label('Sync from Belgaco')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn () => $this->record?->role === 'customer' && $this->record?->portalLink)
                ->requiresConfirmation()
                ->modalHeading('Sync from Belgaco')
                ->modalDescription('Fetch the latest customer data (including PRICING tier) from Belgaco and update this user.')
                ->action(function () {
                    $user = $this->record;
                    if ($user->role !== 'customer' || !$user->portalLink) {
                        Notification::make()->title('No Belgaco link')->body('This user is not linked to a Belgaco client.')->warning()->send();
                        return;
                    }
                    try {
                        app(RobawsCustomerSyncService::class)->syncSingleCustomer($user->portalLink->robaws_client_id);
                        $this->record->refresh();
                        $this->record->load(['portalLink.cachedCustomer', 'pricingTier']);
                        $this->form->fill($this->getRecord()->getAttributes());
                        Notification::make()->title('Synced from Belgaco')->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Sync failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Actions\Action::make('pushPricingToRobaws')
                ->label('Push pricing to Belgaco')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn () => $this->record?->role === 'customer' && $this->record?->portalLink)
                ->requiresConfirmation()
                ->modalHeading('Push Pricing Tier to Belgaco')
                ->modalDescription('This will update the PRICING extra field on the linked Belgaco client with the current pricing tier.')
                ->action(function () {
                    $user = $this->record;
                    if ($user->role !== 'customer' || !$user->portalLink) {
                        Notification::make()->title('No Belgaco link')->body('This user is not linked to a Belgaco client.')->warning()->send();
                        return;
                    }
                    try {
                        app(PricingTierSyncService::class)->pushUserPricingToRobaws($user);
                        Notification::make()->title('Pricing pushed to Belgaco')->success()->send();
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
                    ->title('Saved, but pricing sync to Belgaco failed')
                    ->body($e->getMessage())
                    ->warning()
                    ->send();
            }
        }
    }
}
