<?php

namespace App\Filament\Resources\CarrierRuleResource\Pages;

use App\Filament\Resources\CarrierRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewCarrierRule extends ViewRecord
{
    protected static string $resource = CarrierRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            Actions\Action::make('manageMappings')
                ->label('Manage Freight Mappings')
                ->icon('heroicon-o-squares-2x2')
                ->url(fn () => static::getResource()::getUrl('edit-mappings', ['record' => $this->record]))
                ->color('primary'),
        ];
    }
}

