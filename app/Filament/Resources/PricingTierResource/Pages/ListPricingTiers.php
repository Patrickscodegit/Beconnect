<?php

namespace App\Filament\Resources\PricingTierResource\Pages;

use App\Filament\Resources\PricingTierResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPricingTiers extends ListRecords
{
    protected static string $resource = PricingTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('New Pricing Tier')
                ->icon('heroicon-o-plus'),
        ];
    }
    
    public function getTitle(): string
    {
        return 'Pricing Tiers';
    }
    
    public function getHeading(): string
    {
        return 'Manage Pricing Tiers';
    }
    
    public function getSubheading(): ?string
    {
        return 'Configure profit margins for quotation pricing. Changes take effect immediately for new quotations.';
    }
}

