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
        ];
    }
}

