<?php

namespace App\Filament\Resources\PricingProfileResource\Pages;

use App\Filament\Resources\PricingProfileResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPricingProfile extends EditRecord
{
    protected static string $resource = PricingProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->requiresConfirmation()
                ->modalDescription('Warning: This will affect pricing calculations using this profile. Consider marking as inactive instead.'),
        ];
    }
    
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
    
    protected function getSavedNotificationTitle(): ?string
    {
        return 'Margin profile updated - changes will apply to new calculations immediately';
    }
}
