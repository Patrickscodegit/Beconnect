<?php

namespace App\Filament\Resources\ExtractionResource\Pages;

use App\Filament\Resources\ExtractionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListExtractions extends ListRecords
{
    protected static string $resource = ExtractionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
