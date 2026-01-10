<?php

namespace App\Filament\Resources\RobawsSupplierCacheResource\Pages;

use App\Filament\Resources\RobawsSupplierCacheResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRobawsSupplierCache extends EditRecord
{
    protected static string $resource = RobawsSupplierCacheResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
