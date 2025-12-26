<?php

namespace App\Filament\Resources\CarrierRuleResource\Pages;

use App\Filament\Resources\CarrierRuleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCarrierRule extends CreateRecord
{
    protected static string $resource = CarrierRuleResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}

