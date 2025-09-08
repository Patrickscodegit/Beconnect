<?php

namespace App\Filament\Resources\IntakeResource\Pages;

use App\Filament\Resources\IntakeResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewIntake extends ViewRecord
{
    protected static string $resource = IntakeResource::class;

    // Enable automatic polling every 5 seconds for real-time status updates
    protected ?string $pollingInterval = '5s';

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            
            Actions\Action::make('toggle_polling')
                ->label(fn () => $this->pollingInterval ? 'Disable Auto-refresh' : 'Enable Auto-refresh')
                ->icon(fn () => $this->pollingInterval ? 'heroicon-o-pause' : 'heroicon-o-play')
                ->color(fn () => $this->pollingInterval ? 'warning' : 'success')
                ->action(function () {
                    $this->pollingInterval = $this->pollingInterval ? null : '5s';
                })
                ->tooltip('Toggle automatic status refresh'),
        ];
    }
}
