<?php

namespace App\Filament\Resources\PricingTierResource\Pages;

use App\Filament\Resources\PricingTierResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePricingTier extends CreateRecord
{
    protected static string $resource = PricingTierResource::class;
    
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
    
    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Pricing tier created successfully';
    }
}

