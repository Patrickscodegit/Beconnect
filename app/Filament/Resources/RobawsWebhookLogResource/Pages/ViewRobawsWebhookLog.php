<?php

namespace App\Filament\Resources\RobawsWebhookLogResource\Pages;

use App\Filament\Resources\RobawsWebhookLogResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewRobawsWebhookLog extends ViewRecord
{
    protected static string $resource = RobawsWebhookLogResource::class;
    
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label('Back to List')
                ->url(RobawsWebhookLogResource::getUrl('index'))
                ->icon('heroicon-o-arrow-left'),
        ];
    }
}

