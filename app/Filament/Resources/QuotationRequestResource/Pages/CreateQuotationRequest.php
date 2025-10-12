<?php

namespace App\Filament\Resources\QuotationRequestResource\Pages;

use App\Filament\Resources\QuotationRequestResource;
use Filament\Resources\Pages\CreateRecord;

class CreateQuotationRequest extends CreateRecord
{
    protected static string $resource = QuotationRequestResource::class;
    
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
    
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Comprehensive logging of all form data
        \Log::info('CreateQuotationRequest::mutateFormDataBeforeCreate - START', [
            'all_data' => $data,
            'data_keys' => array_keys($data),
            'status_value' => $data['status'] ?? 'NOT_SET',
            'source_value' => $data['source'] ?? 'NOT_SET',
            'cargo_details_value' => $data['cargo_details'] ?? 'NOT_SET',
            'intro_template_id' => $data['intro_template_id'] ?? 'NOT_SET',
            'end_template_id' => $data['end_template_id'] ?? 'NOT_SET',
            'request_number_in_data' => $data['request_number'] ?? 'NOT_SET',
            'route_fields' => [
                'por' => $data['por'] ?? 'NOT_SET',
                'pol' => $data['pol'] ?? 'NOT_SET',
                'pod' => $data['pod'] ?? 'NOT_SET',
                'fdest' => $data['fdest'] ?? 'NOT_SET',
            ],
        ]);
        
        // Fix status field - change 'draft' to 'pending' (valid enum value)
        if (isset($data['status']) && $data['status'] === 'draft') {
            $data['status'] = 'pending';
            \Log::info('Fixed status: changed from draft to pending');
        }
        
        // Ensure cargo_details has a value (it's a JSON field)
        if (!isset($data['cargo_details']) || empty($data['cargo_details'])) {
            $data['cargo_details'] = [];
            \Log::info('Set cargo_details to empty array');
        }
        
        // Build routing string from individual route fields for display
        $routeParts = [];
        if (!empty($data['por'])) $routeParts[] = $data['por'];
        if (!empty($data['pol'])) $routeParts[] = $data['pol'];
        if (!empty($data['pod'])) $routeParts[] = $data['pod'];
        if (!empty($data['fdest'])) $routeParts[] = $data['fdest'];
        
        $data['routing'] = implode(' → ', $routeParts);
        \Log::info('Built routing string', ['routing' => $data['routing'], 'route_parts' => $routeParts]);
        
        // Log final data before return
        \Log::info('CreateQuotationRequest::mutateFormDataBeforeCreate - FINAL', [
            'final_data' => $data,
            'final_status' => $data['status'] ?? 'NOT_SET',
            'final_source' => $data['source'] ?? 'NOT_SET',
            'final_routing' => $data['routing'] ?? 'NOT_SET',
        ]);
        
        return $data;
    }
    
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        // Ensure request_number is not set in the data
        unset($data['request_number']);
        
        \Log::info('CreateQuotationRequest::handleRecordCreation - Data before creation', [
            'data_keys' => array_keys($data),
            'request_number_in_data' => $data['request_number'] ?? 'NOT_SET',
        ]);
        
        // Create the record
        $record = $this->getModel()::create($data);
        
        \Log::info('CreateQuotationRequest::handleRecordCreation - Record created', [
            'id' => $record->id,
            'request_number' => $record->request_number,
        ]);
        
        return $record;
    }
}

