<?php

namespace App\Filament\Resources\RobawsCustomerCacheResource\Pages;

use App\Filament\Resources\RobawsCustomerCacheResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRobawsCustomerCache extends EditRecord
{
    protected static string $resource = RobawsCustomerCacheResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
