<?php

namespace App\Filament\Resources\RobawsCustomerCacheResource\Pages;

use App\Filament\Resources\RobawsCustomerCacheResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRobawsCustomerCaches extends ListRecords
{
    protected static string $resource = RobawsCustomerCacheResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
