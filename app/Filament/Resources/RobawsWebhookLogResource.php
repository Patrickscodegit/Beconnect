<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RobawsWebhookLogResource\Pages;
use App\Models\RobawsWebhookLog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;

class RobawsWebhookLogResource extends Resource
{
    protected static ?string $model = RobawsWebhookLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-signal';
    
    protected static ?string $navigationLabel = 'Webhook Logs';
    
    protected static ?string $navigationGroup = 'Robaws Integration';
    
    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Webhook Details')
                    ->schema([
                        Forms\Components\TextInput::make('event_type')
                            ->label('Event Type')
                            ->disabled(),
                        
                        Forms\Components\TextInput::make('robaws_id')
                            ->label('Robaws ID')
                            ->disabled(),
                        
                        Forms\Components\Select::make('status')
                            ->options([
                                'received' => 'Received',
                                'processing' => 'Processing',
                                'processed' => 'Processed',
                                'failed' => 'Failed',
                            ])
                            ->disabled(),
                        
                        Forms\Components\Textarea::make('error_message')
                            ->label('Error Message')
                            ->disabled()
                            ->rows(3)
                            ->visible(fn ($record) => $record && $record->status === 'failed'),
                    ])->columns(2),
                
                Forms\Components\Section::make('Payload')
                    ->schema([
                        Forms\Components\Textarea::make('payload')
                            ->label('Full Payload')
                            ->disabled()
                            ->rows(15)
                            ->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT)),
                    ]),
                
                Forms\Components\Section::make('Timestamps')
                    ->schema([
                        Forms\Components\DateTimePicker::make('created_at')
                            ->label('Received At')
                            ->disabled(),
                        
                        Forms\Components\DateTimePicker::make('processed_at')
                            ->label('Processed At')
                            ->disabled(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->searchable(),
                
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'secondary' => 'received',
                        'warning' => 'processing',
                        'success' => 'processed',
                        'danger' => 'failed',
                    ])
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('event_type')
                    ->label('Event')
                    ->searchable()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('robaws_id')
                    ->label('Robaws ID')
                    ->searchable()
                    ->limit(20),
                
                Tables\Columns\TextColumn::make('error_message')
                    ->label('Error')
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->error_message)
                    ->toggleable(isToggledHiddenByDefault: true),
                
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Received')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->tooltip(fn ($record) => $record->created_at->format('Y-m-d H:i:s')),
                
                Tables\Columns\TextColumn::make('processed_at')
                    ->label('Processed')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->tooltip(fn ($record) => $record->processed_at?->format('Y-m-d H:i:s'))
                    ->placeholder('Not processed'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'received' => 'Received',
                        'processing' => 'Processing',
                        'processed' => 'Processed',
                        'failed' => 'Failed',
                    ])
                    ->default('all'),
                
                Tables\Filters\SelectFilter::make('event_type')
                    ->options([
                        'article.created' => 'Article Created',
                        'article.updated' => 'Article Updated',
                        'article.stock-changed' => 'Stock Changed',
                    ]),
                
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')
                            ->label('From'),
                        Forms\Components\DatePicker::make('created_until')
                            ->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                
                Tables\Actions\Action::make('replay')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn ($record) => $record->status === 'failed')
                    ->requiresConfirmation()
                    ->modalHeading('Retry Failed Webhook?')
                    ->modalDescription('This will reprocess the webhook payload.')
                    ->action(function ($record) {
                        try {
                            // Get the sync service
                            $syncService = app(\App\Services\Quotation\RobawsArticlesSyncService::class);
                            
                            // Extract data from payload
                            $payload = $record->payload;
                            $event = $payload['event'] ?? $record->event_type;
                            $data = $payload['data'] ?? [];
                            
                            // Reset status to processing
                            $record->update(['status' => 'processing', 'error_message' => null]);
                            
                            // Reprocess
                            $syncService->processArticleFromWebhook($data, $event);
                            
                            // Mark as processed
                            $record->markAsProcessed();
                            
                            Notification::make()
                                ->title('Webhook reprocessed successfully')
                                ->success()
                                ->send();
                                
                        } catch (\Exception $e) {
                            $record->markAsFailed($e->getMessage());
                            
                            Notification::make()
                                ->title('Retry failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                
                Tables\Actions\Action::make('viewPayload')
                    ->label('View Payload')
                    ->icon('heroicon-o-code-bracket')
                    ->modalHeading('Webhook Payload')
                    ->modalContent(fn ($record) => view('filament.modals.json-viewer', [
                        'json' => json_encode($record->payload, JSON_PRETTY_PRINT)
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    
                    Tables\Actions\BulkAction::make('retryFailed')
                        ->label('Retry Failed')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $success = 0;
                            $failed = 0;
                            
                            foreach ($records as $record) {
                                if ($record->status !== 'failed') {
                                    continue;
                                }
                                
                                try {
                                    $syncService = app(\App\Services\Quotation\RobawsArticlesSyncService::class);
                                    $payload = $record->payload;
                                    $event = $payload['event'] ?? $record->event_type;
                                    $data = $payload['data'] ?? [];
                                    
                                    $record->update(['status' => 'processing', 'error_message' => null]);
                                    $syncService->processArticleFromWebhook($data, $event);
                                    $record->markAsProcessed();
                                    
                                    $success++;
                                } catch (\Exception $e) {
                                    $record->markAsFailed($e->getMessage());
                                    $failed++;
                                }
                            }
                            
                            Notification::make()
                                ->title("Retry complete: {$success} succeeded, {$failed} failed")
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('30s'); // Auto-refresh every 30 seconds
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRobawsWebhookLogs::route('/'),
            'view' => Pages\ViewRobawsWebhookLog::route('/{record}'),
        ];
    }
    
    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', 'failed')
            ->where('created_at', '>=', now()->subDay())
            ->count() ?: null;
    }
    
    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }
}

