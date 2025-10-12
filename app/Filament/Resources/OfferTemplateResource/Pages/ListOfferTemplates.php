<?php

namespace App\Filament\Resources\OfferTemplateResource\Pages;

use App\Filament\Resources\OfferTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOfferTemplates extends ListRecords
{
    protected static string $resource = OfferTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

