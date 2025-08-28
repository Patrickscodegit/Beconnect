<?php

namespace App\Filament\Resources\RobawsIntegrationResource\Pages;

use App\Filament\Resources\RobawsIntegrationResource;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Notifications\Notification;

class ViewRobawsIntegration extends ViewRecord
{
    protected static string $resource = RobawsIntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('reprocess')
                ->label('Reprocess for Robaws')
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    $service = app(EnhancedRobawsIntegrationService::class);
                    $result = $service->processDocumentFromExtraction($this->record);
                    
                    if ($result) {
                        Notification::make()
                            ->title('Document reprocessed successfully')
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Failed to reprocess document')
                            ->danger()
                            ->send();
                    }
                }),
            Actions\Action::make('export_data')
                ->label('Export Robaws Data')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn () => $this->record->robaws_quotation_data !== null)
                ->action(function () {
                    $service = app(EnhancedRobawsIntegrationService::class);
                    $exportData = $service->exportDocumentForRobaws($this->record);
                    
                    if ($exportData) {
                        $filename = 'robaws_export_' . $this->record->id . '_' . date('Y-m-d_H-i-s') . '.json';
                        
                        return response()->streamDownload(
                            function () use ($exportData) {
                                echo json_encode($exportData, JSON_PRETTY_PRINT);
                            },
                            $filename,
                            ['Content-Type' => 'application/json']
                        );
                    }
                }),
        ];
    }
}
