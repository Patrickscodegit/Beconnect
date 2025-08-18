<?php

namespace App\Filament\Resources\ExtractionResource\Pages;

use App\Filament\Resources\ExtractionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditExtraction extends EditRecord
{
    protected static string $resource = ExtractionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
