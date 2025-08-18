<?php

namespace App\Filament\Resources\ExtractionResource\Pages;

use App\Filament\Resources\ExtractionResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewExtraction extends ViewRecord
{
    protected static string $resource = ExtractionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
