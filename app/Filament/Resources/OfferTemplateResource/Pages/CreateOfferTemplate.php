<?php

namespace App\Filament\Resources\OfferTemplateResource\Pages;

use App\Filament\Resources\OfferTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOfferTemplate extends CreateRecord
{
    protected static string $resource = OfferTemplateResource::class;
    
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

