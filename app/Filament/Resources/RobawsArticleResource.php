<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RobawsArticleResource\Pages;
use App\Models\RobawsArticleCache;
use App\Services\Robaws\RobawsArticleProvider;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;

class RobawsArticleResource extends Resource
{
    protected static ?string $model = RobawsArticleCache::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';
    
    protected static ?string $navigationLabel = 'Article Cache';
    
    protected static ?string $modelLabel = 'Article';
    
    protected static ?string $pluralModelLabel = 'Articles';
    
    protected static ?string $navigationGroup = 'Quotation System';
    
    protected static ?int $navigationSort = 12;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Basic Information')
                    ->schema([
                        Forms\Components\TextInput::make('article_name')
                            ->required()
                            ->maxLength(500)
                            ->columnSpan(2),
                            
                        Forms\Components\TextInput::make('article_code')
                            ->maxLength(100)
                            ->columnSpan(1),
                            
                        Forms\Components\TextInput::make('robaws_article_id')
                            ->label('Robaws Article ID')
                            ->disabled()
                            ->columnSpan(1),
                            
                        Forms\Components\TextInput::make('unit_price')
                            ->numeric()
                            ->prefix('€')
                            ->required()
                            ->columnSpan(1),
                            
                        Forms\Components\Select::make('currency')
                            ->options([
                                'EUR' => 'EUR',
                                'USD' => 'USD',
                                'GBP' => 'GBP',
                            ])
                            ->default('EUR')
                            ->columnSpan(1),
                            
                        Forms\Components\Select::make('unit_type')
                            ->options([
                                'unit' => 'Unit',
                                'shipment' => 'Per Shipment',
                                'container' => 'Per Container',
                                'vehicle' => 'Per Vehicle',
                                'cbm' => 'Per CBM',
                                'kg' => 'Per KG',
                                'ton' => 'Per Ton',
                            ])
                            ->default('unit')
                            ->columnSpan(1),
                            
                        Forms\Components\Select::make('category')
                            ->options([
                                'seafreight' => 'Seafreight',
                                'precarriage' => 'Precarriage',
                                'oncarriage' => 'Oncarriage',
                                'customs' => 'Customs',
                                'warehouse' => 'Warehouse',
                                'insurance' => 'Insurance',
                                'administration' => 'Administration',
                                'miscellaneous' => 'Miscellaneous',
                                'general' => 'General',
                            ])
                            ->default('general')
                            ->columnSpan(1),
                    ])
                    ->columns(2),
                    
                Forms\Components\Section::make('Classification')
                    ->schema([
                        Forms\Components\CheckboxList::make('applicable_services')
                            ->options(function () {
                                $serviceTypes = config('quotation.service_types', []);
                                $options = [];
                                foreach ($serviceTypes as $key => $value) {
                                    if (is_array($value)) {
                                        $options[$key] = $value['name'] ?? $key;
                                    } else {
                                        $options[$key] = $value;
                                    }
                                }
                                return $options;
                            })
                            ->columns(3)
                            ->columnSpanFull(),
                            
                        Forms\Components\Select::make('customer_type')
                            ->options(config('quotation.customer_types', []))
                            ->columnSpan(1),
                            
                        Forms\Components\TagsInput::make('carriers')
                            ->placeholder('Add carrier names')
                            ->columnSpan(1),
                            
                        Forms\Components\Toggle::make('is_parent_article')
                            ->label('Is Parent Article')
                            ->columnSpan(1),
                            
                        Forms\Components\Toggle::make('is_surcharge')
                            ->label('Is Surcharge/Add-on')
                            ->columnSpan(1),
                    ])
                    ->columns(2),
                    
                Forms\Components\Section::make('Quantity & Pricing')
                    ->schema([
                        Forms\Components\TextInput::make('min_quantity')
                            ->numeric()
                            ->default(1)
                            ->columnSpan(1),
                            
                        Forms\Components\TextInput::make('max_quantity')
                            ->numeric()
                            ->default(1)
                            ->columnSpan(1),
                            
                        Forms\Components\TextInput::make('tier_label')
                            ->maxLength(50)
                            ->placeholder('e.g., 2-pack, 3-pack')
                            ->columnSpan(1),
                            
                        Forms\Components\KeyValue::make('pricing_formula')
                            ->label('Pricing Formula (JSON)')
                            ->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->collapsible(),
                    
                Forms\Components\Section::make('Metadata')
                    ->schema([
                        Forms\Components\Toggle::make('requires_manual_review')
                            ->label('Requires Manual Review')
                            ->columnSpan(1),
                            
                        Forms\Components\DateTimePicker::make('last_synced_at')
                            ->label('Last Synced')
                            ->disabled()
                            ->columnSpan(1),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('article_code')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->placeholder('N/A'),
                    
                Tables\Columns\TextColumn::make('article_name')
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->tooltip(function (Tables\Columns\TextColumn $column): ?string {
                        $state = $column->getState();
                        if (strlen($state) > 50) {
                            return $state;
                        }
                        return null;
                    }),
                    
                Tables\Columns\TextColumn::make('unit_price')
                    ->money('EUR')
                    ->sortable(),
                    
            Tables\Columns\TextColumn::make('applicable_services')
                ->badge()
                ->formatStateUsing(function ($state): string {
                    // Handle both array and JSON string cases
                    if (is_string($state)) {
                        $decoded = json_decode($state, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            if (count($decoded) > 0) {
                                return implode(', ', array_map(fn($s) => str_replace('_', ' ', $s), $decoded));
                            }
                            return 'None';
                        }
                        // If it's a simple string (like "FCL_IMPORT" directly), format it
                        return str_replace('_', ' ', $state);
                    }
                    if (is_array($state) && count($state) > 0) {
                        return implode(', ', array_map(fn($s) => str_replace('_', ' ', $s), $state));
                    }
                    return 'None';
                })
                ->color(function ($state): string {
                    if (is_string($state)) {
                        $decoded = json_decode($state, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            return count($decoded) > 0 ? 'success' : 'gray';
                        }
                        // Simple strings are valid too
                        return 'success';
                    }
                    if (is_array($state) && count($state) > 0) {
                        return 'success';
                    }
                    return 'gray';
                })
                ->tooltip(function ($state): ?string {
                    if (is_string($state)) {
                        $decoded = json_decode($state, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            if (count($decoded) > 0) {
                                return implode(', ', array_map(fn($s) => str_replace('_', ' ', $s), $decoded));
                            }
                            return null;
                        }
                        // Simple string tooltip
                        return str_replace('_', ' ', $state);
                    }
                    if (is_array($state) && count($state) > 0) {
                        return implode(', ', array_map(fn($s) => str_replace('_', ' ', $s), $state));
                    }
                    return null;
                }),
                    
                Tables\Columns\IconColumn::make('is_parent_article')
                    ->boolean()
                    ->label('Parent')
                    ->toggleable(),
                    
                Tables\Columns\TextColumn::make('children_count')
                    ->counts('children')
                    ->label('Children')
                    ->badge()
                    ->color('info')
                    ->toggleable(),
                    
                Tables\Columns\TextColumn::make('customer_type')
                    ->badge()
                    ->placeholder('All')
                    ->toggleable(),
                    
                Tables\Columns\TextColumn::make('category')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'seafreight' => 'info',
                        'customs' => 'warning',
                        'warehouse' => 'success',
                        'administration' => 'gray',
                        default => 'gray',
                    })
                    ->toggleable(),
                    
                Tables\Columns\TextColumn::make('last_synced_at')
                    ->dateTime()
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->options([
                        'seafreight' => 'Seafreight',
                        'precarriage' => 'Precarriage',
                        'oncarriage' => 'Oncarriage',
                        'customs' => 'Customs',
                        'warehouse' => 'Warehouse',
                        'insurance' => 'Insurance',
                        'administration' => 'Administration',
                        'miscellaneous' => 'Miscellaneous',
                        'general' => 'General',
                    ]),
                    
                Tables\Filters\TernaryFilter::make('is_parent_article')
                    ->label('Is Parent Article')
                    ->placeholder('All articles')
                    ->trueLabel('Only parent articles')
                    ->falseLabel('Only child articles'),
                    
                Tables\Filters\TernaryFilter::make('is_surcharge')
                    ->label('Is Surcharge')
                    ->placeholder('All articles')
                    ->trueLabel('Only surcharges')
                    ->falseLabel('Exclude surcharges'),
                    
                Tables\Filters\SelectFilter::make('customer_type')
                    ->options(config('quotation.customer_types', []))
                    ->label('Customer Type'),
                    
                Tables\Filters\TernaryFilter::make('requires_manual_review')
                    ->label('Requires Review'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->headerActions([
                Tables\Actions\Action::make('sync')
                    ->label('Sync from Robaws')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->action(function () {
                        try {
                            $articleProvider = app(RobawsArticleProvider::class);
                            $count = $articleProvider->syncArticles();
                            
                            Notification::make()
                                ->title('Articles synced successfully')
                                ->body("Synced {$count} articles from Robaws.")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Sync failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Sync articles from Robaws')
                    ->modalDescription('This will fetch the latest articles from Robaws offers. This may take a few minutes.')
                    ->modalSubmitActionLabel('Start Sync'),
            ])
            ->defaultSort('last_synced_at', 'desc');
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
            'index' => Pages\ListRobawsArticles::route('/'),
            'view' => Pages\ViewRobawsArticle::route('/{record}'),
            'edit' => Pages\EditRobawsArticle::route('/{record}/edit'),
        ];
    }
    
    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::where('requires_manual_review', true)->count();
        return $count > 0 ? (string) $count : null;
    }
    
    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}

