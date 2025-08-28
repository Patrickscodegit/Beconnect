<?php

namespace App\Filament\Resources\RobawsIntegrationResource\Pages;

use App\Filament\Resources\RobawsIntegrationResource;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Notifications\Notification;

class ListRobawsIntegrations extends ListRecords
{
    protected static string $resource = RobawsIntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('integration_summary')
                ->label('View Summary')
                ->icon('heroicon-o-chart-bar')
                ->action(function () {
                    $service = app(EnhancedRobawsIntegrationService::class);
                    $summary = $service->getIntegrationSummary();
                    
                    $message = "Total: {$summary['total_documents']} | Ready: {$summary['ready_for_sync']} | Review: {$summary['needs_review']} | Synced: {$summary['synced']} | Success Rate: {$summary['success_rate']}%";
                    
                    Notification::make()
                        ->title('Robaws Integration Summary')
                        ->body($message)
                        ->info()
                        ->persistent()
                        ->send();
                }),
            Actions\Action::make('process_all_pending')
                ->label('Process All Pending')
                ->icon('heroicon-o-cog-6-tooth')
                ->requiresConfirmation()
                ->action(function () {
                    $service = app(EnhancedRobawsIntegrationService::class);
                    $documents = \App\Models\Document::whereNull('robaws_sync_status')
                        ->whereHas('extractions', function ($query) {
                            $query->where('status', 'completed');
                        })
                        ->get();
                    
                    $processed = 0;
                    foreach ($documents as $document) {
                        if ($service->processDocumentFromExtraction($document)) {
                            $processed++;
                        }
                    }
                    
                    Notification::make()
                        ->title("Processed {$processed} pending documents")
                        ->success()
                        ->send();
                }),
        ];
    }
}
