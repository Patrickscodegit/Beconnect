<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RobawsIntegrationResource\Pages;
use App\Models\Document;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;

class RobawsIntegrationResource extends Resource
{
    protected static ?string $model = Document::class;
    
    protected static ?string $navigationIcon = 'heroicon-o-truck';
    
    protected static ?string $navigationLabel = 'Robaws Integration';
    
    protected static ?string $modelLabel = 'Document';
    
    protected static ?string $pluralModelLabel = 'Robaws Integration';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('extractions', function ($query) {
                $query->where('status', 'completed');
            })
            ->with(['extractions' => function ($query) {
                $query->latest()->limit(1);
            }]);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('filename')
                    ->disabled(),
                Forms\Components\Select::make('robaws_sync_status')
                    ->options([
                        'ready' => 'Ready for Sync',
                        'needs_review' => 'Needs Review',
                        'synced' => 'Synced',
                    ])
                    ->nullable(),
                Forms\Components\Textarea::make('robaws_quotation_data')
                    ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT) : $state)
                    ->disabled()
                    ->rows(10),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('filename')
                    ->searchable()
                    ->limit(30),
                BadgeColumn::make('robaws_sync_status')
                    ->colors([
                        'success' => 'ready',
                        'warning' => 'needs_review',
                        'primary' => 'synced',
                        'secondary' => fn ($state) => $state === null,
                    ])
                    ->formatStateUsing(fn ($state) => match($state) {
                        'ready' => 'Ready',
                        'needs_review' => 'Needs Review',
                        'synced' => 'Synced',
                        default => 'Pending',
                    }),
                TextColumn::make('robaws_formatted_at')
                    ->dateTime()
                    ->sortable()
                    ->label('Formatted At'),
                TextColumn::make('extractions_count')
                    ->counts('extractions')
                    ->label('Extractions'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('robaws_sync_status')
                    ->options([
                        'ready' => 'Ready for Sync',
                        'needs_review' => 'Needs Review',
                        'synced' => 'Synced',
                    ])
                    ->label('Sync Status'),
            ])
            ->actions([
                Action::make('process_robaws')
                    ->label('Process for Robaws')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->visible(fn (Document $record) => $record->robaws_sync_status !== 'synced')
                    ->action(function (Document $record) {
                        $service = app(EnhancedRobawsIntegrationService::class);
                        $result = $service->processDocumentFromExtraction($record);
                        
                        if ($result) {
                            Notification::make()
                                ->title('Document processed for Robaws successfully')
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Failed to process document for Robaws')
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('mark_synced')
                    ->label('Mark as Synced')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (Document $record) => $record->robaws_sync_status === 'ready')
                    ->requiresConfirmation()
                    ->action(function (Document $record) {
                        $service = app(EnhancedRobawsIntegrationService::class);
                        $result = $service->markAsManuallySynced($record);
                        
                        if ($result) {
                            Notification::make()
                                ->title('Document marked as synced')
                                ->success()
                                ->send();
                        }
                    }),
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('bulk_process')
                        ->label('Process Selected for Robaws')
                        ->icon('heroicon-o-cog-6-tooth')
                        ->action(function ($records) {
                            $service = app(\App\Services\RobawsIntegration\EnhancedRobawsIntegrationService::class);
                            $processed = 0;
                            
                            foreach ($records as $record) {
                                if ($service->processDocumentFromExtraction($record)) {
                                    $processed++;
                                }
                            }
                            
                            Notification::make()
                                ->title("Processed {$processed} of " . count($records) . " documents")
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('10s'); // Auto-refresh every 10 seconds for integration status
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRobawsIntegrations::route('/'),
            'view' => Pages\ViewRobawsIntegration::route('/{record}'),
        ];
    }
}
