<?php

namespace App\Filament\Resources\QuotationRequestResource\Pages;

use App\Filament\Resources\QuotationRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditQuotationRequest extends EditRecord
{
    protected static string $resource = QuotationRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
    
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
    
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Build routing string from individual route fields for display
        $routeParts = [];
        if (!empty($data['por'])) $routeParts[] = $data['por'];
        if (!empty($data['pol'])) $routeParts[] = $data['pol'];
        if (!empty($data['pod'])) $routeParts[] = $data['pod'];
        if (!empty($data['fdest'])) $routeParts[] = $data['fdest'];
        
        $data['routing'] = implode(' → ', $routeParts);
        
        return $data;
    }
}

