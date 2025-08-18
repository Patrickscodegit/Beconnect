<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExtractionResource\Pages;
use App\Models\Extraction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Filament\Infolists\Infolist;

class ExtractionResource extends Resource
{
    protected static ?string $model = Extraction::class;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';
    
    protected static ?string $navigationLabel = 'AI Extractions';
    
    protected static ?string $modelLabel = 'Extraction';
    
    protected static ?string $pluralModelLabel = 'AI Extractions';
    
    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Extraction Details')
                    ->schema([
                        Forms\Components\Select::make('intake_id')
                            ->label('Intake')
                            ->relationship('intake', 'id')
                            ->searchable()
                            ->preload()
                            ->required(),
                            
                        Forms\Components\TextInput::make('confidence')
                            ->label('Confidence Score')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1)
                            ->step(0.01)
                            ->suffix('%')
                            ->formatStateUsing(fn (?float $state): ?string => $state ? number_format($state * 100, 1) : null)
                            ->dehydrateStateUsing(fn (?string $state): ?float => $state ? ((float) $state) / 100 : null),
                            
                        Forms\Components\DateTimePicker::make('verified_at')
                            ->label('Verified At'),
                            
                        Forms\Components\TextInput::make('verified_by')
                            ->label('Verified By')
                            ->maxLength(255),
                    ])
                    ->columns(2),
                    
                Forms\Components\Section::make('Extracted Data')
                    ->schema([
                        Forms\Components\KeyValue::make('raw_json')
                            ->label('Extracted Information')
                            ->keyLabel('Field')
                            ->valueLabel('Value')
                            ->addActionLabel('Add Field')
                            ->reorderable()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('intake.id')
                    ->label('Intake ID')
                    ->sortable()
                    ->searchable(),
                    
                Tables\Columns\TextColumn::make('intake.status')
                    ->label('Intake Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'processing' => 'info',
                        'completed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                    
                Tables\Columns\TextColumn::make('confidence')
                    ->label('Confidence')
                    ->formatStateUsing(fn (?float $state): string => $state ? number_format($state * 100, 1) . '%' : 'N/A')
                    ->badge()
                    ->color(fn (?float $state): string => match (true) {
                        $state === null => 'gray',
                        $state >= 0.8 => 'success',
                        $state >= 0.6 => 'warning',
                        default => 'danger',
                    })
                    ->sortable(),
                    
                Tables\Columns\IconColumn::make('verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->getStateUsing(fn (Extraction $record): bool => !is_null($record->verified_at))
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor('success')
                    ->falseColor('warning'),
                    
                Tables\Columns\TextColumn::make('verified_by')
                    ->label('Verified By')
                    ->placeholder('Not verified')
                    ->limit(20),
                    
                Tables\Columns\TextColumn::make('extracted_fields_count')
                    ->label('Fields')
                    ->getStateUsing(fn (Extraction $record): int => is_array($record->raw_json) ? count($record->raw_json) : 0)
                    ->badge()
                    ->color('info'),
                    
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Extracted')
                    ->dateTime()
                    ->sortable()
                    ->since(),
            ])
            ->filters([
                Tables\Filters\Filter::make('verified')
                    ->toggle()
                    ->query(fn ($query) => $query->whereNotNull('verified_at')),
                    
                Tables\Filters\Filter::make('high_confidence')
                    ->label('High Confidence (≥80%)')
                    ->toggle()
                    ->query(fn ($query) => $query->where('confidence', '>=', 0.8)),
                    
                Tables\Filters\Filter::make('low_confidence')
                    ->label('Low Confidence (<60%)')
                    ->toggle()
                    ->query(fn ($query) => $query->where('confidence', '<', 0.6)),
                    
                Tables\Filters\SelectFilter::make('intake.status')
                    ->label('Intake Status')
                    ->relationship('intake', 'status')
                    ->options([
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('verify')
                    ->label('Verify')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Extraction $record) {
                        $record->update([
                            'verified_at' => now(),
                            'verified_by' => auth()->user()->name ?? 'System',
                        ]);
                    })
                    ->visible(fn (Extraction $record): bool => is_null($record->verified_at)),
                    
                Tables\Actions\Action::make('unverify')
                    ->label('Unverify')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (Extraction $record) {
                        $record->update([
                            'verified_at' => null,
                            'verified_by' => null,
                        ]);
                    })
                    ->visible(fn (Extraction $record): bool => !is_null($record->verified_at)),
                    
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    
                    Tables\Actions\BulkAction::make('verify_selected')
                        ->label('Verify Selected')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                $record->update([
                                    'verified_at' => now(),
                                    'verified_by' => auth()->user()->name ?? 'System',
                                ]);
                            }
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
    
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Extraction Overview')
                    ->schema([
                        Infolists\Components\TextEntry::make('intake.id')
                            ->label('Intake ID'),
                        Infolists\Components\TextEntry::make('confidence')
                            ->label('Confidence Score')
                            ->formatStateUsing(fn (?float $state): string => $state ? number_format($state * 100, 1) . '%' : 'N/A')
                            ->badge()
                            ->color(fn (?float $state): string => match (true) {
                                $state === null => 'gray',
                                $state >= 0.8 => 'success',
                                $state >= 0.6 => 'warning',
                                default => 'danger',
                            }),
                        Infolists\Components\TextEntry::make('verified_at')
                            ->label('Verified At')
                            ->dateTime()
                            ->placeholder('Not verified'),
                        Infolists\Components\TextEntry::make('verified_by')
                            ->label('Verified By')
                            ->placeholder('Not verified'),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Extracted At')
                            ->dateTime(),
                    ])
                    ->columns(3),
                    
                Infolists\Components\Section::make('Extracted Data')
                    ->schema([
                        Infolists\Components\KeyValueEntry::make('raw_json')
                            ->label('Extracted Information')
                            ->keyLabel('Field')
                            ->valueLabel('Value'),
                    ]),
                    
                Infolists\Components\Section::make('Related Intake')
                    ->schema([
                        Infolists\Components\TextEntry::make('intake.status')
                            ->badge(),
                        Infolists\Components\TextEntry::make('intake.source')
                            ->badge(),
                        Infolists\Components\TextEntry::make('intake.priority')
                            ->badge(),
                        Infolists\Components\TextEntry::make('intake.created_at')
                            ->label('Intake Created')
                            ->dateTime(),
                    ])
                    ->columns(4),
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
            'index' => Pages\ListExtractions::route('/'),
            'create' => Pages\CreateExtraction::route('/create'),
            'view' => Pages\ViewExtraction::route('/{record}'),
            'edit' => Pages\EditExtraction::route('/{record}/edit'),
        ];
    }
}
