<?php

namespace App\Filament\Resources\QuotationRequestResource\Pages;

use App\Filament\Resources\QuotationRequestResource;
use App\Services\Pricing\QuotationVatService;
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

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        if (!$record) {
            return $data;
        }

        $record->loadMissing('articles');

        $data['articles'] = $record->articles->map(function ($article) {
            $pivot = $article->pivot;
            $unitPrice = $pivot->unit_price ?? $pivot->selling_price ?? $article->unit_price ?? 0;
            $unitType = $pivot->unit_type ?? $article->unit_type ?? 'unit';
            $quantity = $pivot->quantity ?? 1;
            $isChild = ($pivot->item_type ?? null) === 'child' || !empty($pivot->parent_article_id);
            $isParent = ($pivot->item_type ?? null) === 'parent' || (bool) ($article->is_parent_item ?? false);

            return [
                'id' => $article->id,
                'robaws_id' => $article->robaws_article_id,
                'description' => $article->sales_name ?? $article->description ?? $article->article_name,
                'article_code' => $article->article_code,
                'unit_price' => $unitPrice,
                'unit_type' => $unitType,
                'quantity' => $quantity,
                'is_parent' => $isParent,
                'is_child' => $isChild,
                'parent_id' => $pivot->parent_article_id,
            ];
        })->values()->toArray();

        return $data;
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();
        if (!$record) {
            return;
        }

        app(QuotationVatService::class)->recalculateVatForQuotation($record);
    }
}

