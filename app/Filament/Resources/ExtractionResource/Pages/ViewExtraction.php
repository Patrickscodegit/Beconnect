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

    // Override to ensure we only use the custom infolist, not any default display
    public function getInfolist(string $name = 'default'): ?\Filament\Infolists\Infolist
    {
        return static::getResource()::infolist($this->makeInfolist());
    }

    // Hide any default form display
    protected function hasInfolist(): bool
    {
        return true;
    }
}
