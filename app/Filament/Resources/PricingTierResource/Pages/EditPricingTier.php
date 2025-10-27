<?php

namespace App\Filament\Resources\PricingTierResource\Pages;

use App\Filament\Resources\PricingTierResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPricingTier extends EditRecord
{
    protected static string $resource = PricingTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->requiresConfirmation()
                ->modalDescription('Warning: This will affect quotations using this tier. Consider marking as inactive instead.'),
        ];
    }
    
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
    
    protected function getSavedNotificationTitle(): ?string
    {
        return 'Pricing tier updated - changes will apply to new quotations immediately';
    }
}

