<?php

namespace App\Filament\Resources\RobawsWebhookLogResource\Pages;

use App\Filament\Resources\RobawsWebhookLogResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Notifications\Notification;

class ListRobawsWebhookLogs extends ListRecords
{
    protected static string $resource = RobawsWebhookLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('cleanupOld')
                ->label('Cleanup Old Logs')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Delete Old Webhook Logs?')
                ->modalDescription('This will delete webhook logs older than 30 days. Logs from the last 30 days will be kept.')
                ->action(function () {
                    $deleted = \App\Models\RobawsWebhookLog::where('created_at', '<', now()->subDays(30))->delete();
                    
                    Notification::make()
                        ->title("Deleted {$deleted} old webhook logs")
                        ->success()
                        ->send();
                }),
            
            Actions\Action::make('stats')
                ->label('View Stats')
                ->icon('heroicon-o-chart-bar')
                ->modalHeading('Webhook Statistics')
                ->modalContent(fn () => view('filament.modals.webhook-stats', [
                    'total' => \App\Models\RobawsWebhookLog::count(),
                    'last24h' => \App\Models\RobawsWebhookLog::where('created_at', '>=', now()->subDay())->count(),
                    'last7d' => \App\Models\RobawsWebhookLog::where('created_at', '>=', now()->subDays(7))->count(),
                    'processed' => \App\Models\RobawsWebhookLog::where('status', 'processed')->count(),
                    'failed' => \App\Models\RobawsWebhookLog::where('status', 'failed')->count(),
                    'failedLast24h' => \App\Models\RobawsWebhookLog::where('status', 'failed')
                        ->where('created_at', '>=', now()->subDay())->count(),
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),
        ];
    }
}

