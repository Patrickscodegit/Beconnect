<?php

namespace App\Filament\Resources\PricingProfileResource\Pages;

use App\Filament\Resources\PricingProfileResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePricingProfile extends CreateRecord
{
    protected static string $resource = PricingProfileResource::class;
    
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
    
    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Margin profile created successfully';
    }
}
