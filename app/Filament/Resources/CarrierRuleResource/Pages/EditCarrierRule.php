<?php

namespace App\Filament\Resources\CarrierRuleResource\Pages;

use App\Filament\Resources\CarrierRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCarrierRule extends EditRecord
{
    protected static string $resource = CarrierRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}

