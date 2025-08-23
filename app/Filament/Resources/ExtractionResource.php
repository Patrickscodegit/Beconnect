<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExtractionResource\Pages;
use App\Models\Extraction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Database\Eloquent\Builder;

class ExtractionResource extends Resource
{
    protected static ?string $model = Extraction::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';
    
    protected static ?string $navigationGroup = 'AI Processing';
    
    protected static ?string $modelLabel = 'AI Extraction';
    
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
                Section::make('Extraction Details')
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'completed' => 'success',
                                'processing' => 'info',
                                'failed' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('confidence')
                            ->formatStateUsing(fn ($state) => $state ? number_format($state * 100, 1) . '%' : '0.0%'),
                        TextEntry::make('service_used')
                            ->label('Service Used')
                            ->default('N/A'),
                        TextEntry::make('created_at')
                            ->label('Extracted At')
                            ->dateTime(),
                    ])
                    ->columns(2),

                Section::make('Extracted Information')
                    ->schema([
                        TextEntry::make('dummy')
                            ->label('')
                            ->formatStateUsing(function ($record) {
                                // Debug logging
                                \Log::info('Extraction data debug', [
                                    'id' => $record->id,
                                    'has_extracted_data' => !empty($record->extracted_data),
                                    'extracted_data_type' => gettype($record->extracted_data),
                                    'extracted_data_count' => is_array($record->extracted_data) ? count($record->extracted_data) : 0,
                                    'extracted_data_keys' => is_array($record->extracted_data) ? array_keys($record->extracted_data) : [],
                                ]);
                                
                                if (!$record->extracted_data || !is_array($record->extracted_data)) {
                                    return new \Illuminate\Support\HtmlString(
                                        '<div class="text-gray-500 italic p-4 border border-gray-200 rounded">No data extracted</div>'
                                    );
                                }
                                
                                $html = '<dl class="space-y-3">';
                                foreach ($record->extracted_data as $key => $value) {
                                    $label = ucwords(str_replace('_', ' ', $key));
                                    
                                    if (is_array($value)) {
                                        if (array_is_list($value)) {
                                            // It's a list
                                            $displayValue = '<ul class="list-disc list-inside ml-4">';
                                            foreach ($value as $item) {
                                                $itemValue = is_scalar($item) ? $item : json_encode($item);
                                                $displayValue .= '<li>' . htmlspecialchars($itemValue) . '</li>';
                                            }
                                            $displayValue .= '</ul>';
                                        } else {
                                            // It's an associative array
                                            $displayValue = '<div class="ml-4 space-y-1">';
                                            foreach ($value as $subKey => $subValue) {
                                                $subLabel = ucwords(str_replace('_', ' ', $subKey));
                                                $subDisplayValue = is_scalar($subValue) ? $subValue : json_encode($subValue);
                                                $displayValue .= '<div><span class="font-medium">' . htmlspecialchars($subLabel) . ':</span> ' . htmlspecialchars($subDisplayValue) . '</div>';
                                            }
                                            $displayValue .= '</div>';
                                        }
                                    } else {
                                        $displayValue = htmlspecialchars((string)$value);
                                    }
                                    
                                    $html .= '<div class="border-b border-gray-200 pb-2">';
                                    $html .= '<dt class="text-sm font-medium text-gray-600">' . htmlspecialchars($label) . '</dt>';
                                    $html .= '<dd class="mt-1 text-sm text-gray-900">' . $displayValue . '</dd>';
                                    $html .= '</div>';
                                }
                                $html .= '</dl>';
                                
                                return new \Illuminate\Support\HtmlString($html);
                            })
                            ->extraAttributes(['class' => 'prose max-w-none']),
                    ]),

                Section::make('Raw JSON Data')
                    ->schema([
                        TextEntry::make('dummy_raw')
                            ->label('')
                            ->formatStateUsing(function ($record) {
                                if (empty($record->raw_json)) {
                                    return new \Illuminate\Support\HtmlString(
                                        '<div class="text-gray-500 italic p-4 border border-gray-200 rounded">No raw JSON data available</div>'
                                    );
                                }
                                
                                // Since we removed array casting, raw_json should be a string
                                $json = $record->raw_json;
                                
                                // If it's still an array somehow, convert it
                                if (is_array($json)) {
                                    $json = json_encode($json, JSON_PRETTY_PRINT);
                                } elseif (!is_string($json)) {
                                    $json = json_encode($json, JSON_PRETTY_PRINT);
                                }
                                
                                // Pretty format the JSON if it's not already formatted
                                $decodedJson = json_decode($json, true);
                                if ($decodedJson !== null) {
                                    $json = json_encode($decodedJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                                }
                                
                                return new \Illuminate\Support\HtmlString(
                                    '<pre class="bg-gray-900 text-green-400 p-4 rounded-lg overflow-x-auto text-xs font-mono whitespace-pre-wrap max-h-96 overflow-y-auto">' . 
                                    htmlspecialchars($json) . 
                                    '</pre>'
                                );
                            }),
                    ])
                    ->collapsible()
                    ->collapsed(),
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
