<?php

namespace App\Filament\Resources\OfferTemplateResource\Pages;

use App\Filament\Resources\OfferTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOfferTemplate extends EditRecord
{
    protected static string $resource = OfferTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
    
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

