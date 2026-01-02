<?php

namespace App\Filament\Resources\PricingProfileResource\Pages;

use App\Filament\Resources\PricingProfileResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPricingProfiles extends ListRecords
{
    protected static string $resource = PricingProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('New Margin Profile')
                ->icon('heroicon-o-plus'),
        ];
    }
    
    public function getTitle(): string
    {
        return 'Margin Profiles';
    }
    
    public function getHeading(): string
    {
        return 'Manage Margin Profiles';
    }
    
    public function getSubheading(): ?string
    {
        return 'Configure structured margin rules for pricing calculations. Profiles can be global, carrier-specific, or customer-specific.';
    }
}
