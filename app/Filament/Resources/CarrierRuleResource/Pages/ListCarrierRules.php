<?php

namespace App\Filament\Resources\CarrierRuleResource\Pages;

use App\Filament\Resources\CarrierRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCarrierRules extends ListRecords
{
    protected static string $resource = CarrierRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

