<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RobawsSyncLogResource\Pages;
use App\Models\RobawsSyncLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Illuminate\Database\Eloquent\Builder;

class RobawsSyncLogResource extends Resource
{
    protected static ?string $model = RobawsSyncLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';
    
    protected static ?string $navigationLabel = 'Sync Logs';
    
    protected static ?string $modelLabel = 'Sync Log';
    
    protected static ?string $pluralModelLabel = 'Sync Logs';
    
    protected static ?string $navigationGroup = 'Quotation System';
    
    protected static ?int $navigationSort = 14;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sync_type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'articles' => 'info',
                        'offers' => 'warning',
                        'webhooks' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'success' => 'success',
                        'failed' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('synced_count')
                    ->label('Items Synced')
                    ->numeric()
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable()
                    ->since(),
                    
                Tables\Columns\TextColumn::make('duration')
                    ->getStateUsing(function (RobawsSyncLog $record): string {
                        if ($record->started_at && $record->completed_at) {
                            $seconds = $record->completed_at->diffInSeconds($record->started_at);
                            if ($seconds < 60) {
                                return $seconds . 's';
                            } else {
                                $minutes = floor($seconds / 60);
                                $remainingSeconds = $seconds % 60;
                                return $minutes . 'm ' . $remainingSeconds . 's';
                            }
                        }
                        return 'N/A';
                    })
                    ->label('Duration'),
                    
                Tables\Columns\TextColumn::make('error_message')
                    ->limit(50)
                    ->tooltip(function (Tables\Columns\TextColumn $column): ?string {
                        $state = $column->getState();
                        if ($state && strlen($state) > 50) {
                            return $state;
                        }
                        return null;
                    })
                    ->placeholder('No errors')
                    ->color('danger'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('sync_type')
                    ->options([
                        'articles' => 'Articles',
                        'offers' => 'Offers',
                        'webhooks' => 'Webhooks',
                    ]),
                    
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'success' => 'Success',
                        'failed' => 'Failed',
                        'pending' => 'Pending',
                    ]),
                    
                Tables\Filters\Filter::make('started_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('started_from')
                            ->label('From'),
                        \Filament\Forms\Components\DatePicker::make('started_until')
                            ->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['started_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('started_at', '>=', $date),
                            )
                            ->when(
                                $data['started_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('started_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('started_at', 'desc')
            ->poll('30s'); // Auto-refresh every 30 seconds
    }
    
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Sync Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('sync_type')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'articles' => 'info',
                                'offers' => 'warning',
                                'webhooks' => 'success',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'success' => 'success',
                                'failed' => 'danger',
                                'pending' => 'warning',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('synced_count')
                            ->label('Items Synced'),
                    ])
                    ->columns(3),
                    
                Infolists\Components\Section::make('Timeline')
                    ->schema([
                        Infolists\Components\TextEntry::make('started_at')
                            ->dateTime(),
                        Infolists\Components\TextEntry::make('completed_at')
                            ->dateTime()
                            ->placeholder('Not completed'),
                        Infolists\Components\TextEntry::make('duration')
                            ->getStateUsing(function (RobawsSyncLog $record): string {
                                if ($record->started_at && $record->completed_at) {
                                    $seconds = $record->completed_at->diffInSeconds($record->started_at);
                                    if ($seconds < 60) {
                                        return $seconds . ' seconds';
                                    } else {
                                        $minutes = floor($seconds / 60);
                                        $remainingSeconds = $seconds % 60;
                                        return $minutes . ' minutes ' . $remainingSeconds . ' seconds';
                                    }
                                }
                                return 'N/A';
                            }),
                    ])
                    ->columns(3),
                    
                Infolists\Components\Section::make('Error Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('error_message')
                            ->label('')
                            ->placeholder('No errors')
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (RobawsSyncLog $record): bool => $record->error_message !== null)
                    ->collapsible(),
            ]);
    }
    
    public static function getRelations(): array
    {
        return [
            //
        ];
    }
    
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRobawsSyncLogs::route('/'),
            'view' => Pages\ViewRobawsSyncLog::route('/{record}'),
        ];
    }
    
    public static function canCreate(): bool
    {
        return false; // Sync logs are created automatically
    }
    
    public static function canEdit($record): bool
    {
        return false; // Sync logs are read-only
    }
    
    public static function canDelete($record): bool
    {
        return false; // Sync logs should be preserved for audit trail
    }
}

