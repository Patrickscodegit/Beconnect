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
                
                Tables\Columns\BadgeColumn::make('shipping_line')
                    ->label('Shipping Line')
                    ->formatStateUsing(fn ($state) => $state ?: 'Not specified')
                    ->color(fn ($state) => $state ? 'primary' : 'gray')
                    ->toggleable(),
                    
                Tables\Columns\BadgeColumn::make('service_type')
                    ->label('Service Type')
                    ->formatStateUsing(fn ($state) => $state ?: 'Not specified')
                    ->color(fn ($state) => $state ? 'success' : 'gray')
                    ->toggleable(),
                    
                Tables\Columns\TextColumn::make('pol_terminal')
                    ->label('POL Terminal')
                    ->formatStateUsing(fn ($state) => $state ?: 'N/A')
                    ->color(fn ($state) => $state ? 'primary' : 'gray')
                    ->tooltip(fn ($state) => $state ? null : 'Not available in Robaws')
                    ->toggleable(),
                    
                Tables\Columns\IconColumn::make('is_parent_item')
                    ->boolean()
                    ->label('Parent')
                    ->tooltip('Parent item status from Robaws API')
                    ->toggleable(),
                    
                Tables\Columns\TextColumn::make('validity_date')
                    ->date('M d, Y')
                    ->label('Valid Until')
                    ->placeholder('Not set')
                    ->color(fn ($state) => $state && $state >= now() ? 'success' : 'gray')
                    ->toggleable(),
                    
            Tables\Columns\TextColumn::make('applicable_services')
                ->badge()
                ->formatStateUsing(function ($state, $record) {
                    // Parse array from JSON if needed
                    $services = is_string($state) ? json_decode($state, true) : $state;
                    if (!is_array($services)) {
                        return 'None';
                    }
                    
                    // If service_type is known, show only relevant services
                    if ($record->service_type) {
                        $relevant = [$record->service_type];
                        
                        // Add CONSOL variant if it exists
                        $consol = $record->service_type . ' CONSOL';
                        if (in_array($consol, $services)) {
                            $relevant[] = $consol;
                        }
                        
                        return implode(', ', array_map(fn($s) => str_replace('_', ' ', $s), $relevant));
                    }
                    
                    // Otherwise show max 3 services
                    $limited = array_slice($services, 0, 3);
                    $more = count($services) - 3;
                    $result = implode(', ', array_map(fn($s) => str_replace('_', ' ', $s), $limited));
                    if ($more > 0) {
                        $result .= " +{$more} more";
                    }
                    return $result ?: 'None';
                })
                ->color(fn ($state) => $state ? 'success' : 'gray')
                ->tooltip(function ($state) {
                    $services = is_string($state) ? json_decode($state, true) : $state;
                    if (is_array($services) && count($services) > 0) {
                        return implode(', ', array_map(fn($s) => str_replace('_', ' ', $s), $services));
                    }
                    return null;
                })
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
                Tables\Filters\SelectFilter::make('shipping_line')
                    ->label('Shipping Line')
                    ->options(fn () => RobawsArticleCache::distinct()
                        ->whereNotNull('shipping_line')
                        ->pluck('shipping_line', 'shipping_line')
                        ->toArray()),
                        
                Tables\Filters\SelectFilter::make('service_type')
                    ->label('Service Type')
                    ->options(fn () => RobawsArticleCache::distinct()
                        ->whereNotNull('service_type')
                        ->pluck('service_type', 'service_type')
                        ->toArray()),
                        
                Tables\Filters\SelectFilter::make('pol_terminal')
                    ->label('POL Terminal')
                    ->options(fn () => RobawsArticleCache::distinct()
                        ->whereNotNull('pol_terminal')
                        ->pluck('pol_terminal', 'pol_terminal')
                        ->toArray()),
                    
                Tables\Filters\TernaryFilter::make('is_parent_item')
                    ->label('Parent Items Only')
                    ->placeholder('All items')
                    ->trueLabel('Only parent items')
                    ->falseLabel('Only non-parent items'),
                    
                Tables\Filters\TernaryFilter::make('has_metadata')
                    ->label('Has Metadata')
                    ->placeholder('All articles')
                    ->trueLabel('With metadata')
                    ->falseLabel('Missing metadata')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('shipping_line'),
                        false: fn (Builder $query) => $query->whereNull('shipping_line'),
                    ),
                    
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
                Tables\Actions\Action::make('sync_metadata')
                    ->label('Sync Metadata')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->action(function (RobawsArticleCache $record) {
                        \App\Jobs\SyncSingleArticleMetadataJob::dispatch($record->id);
                        
                        Notification::make()
                            ->title('Metadata sync started')
                            ->body("Syncing metadata for: {$record->article_name}")
                            ->success()
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Sync article metadata')
                    ->modalDescription('This will fetch shipping line, service type, POL terminal, and composite items from Robaws.')
                    ->modalSubmitActionLabel('Sync'),
                    
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('sync_metadata')
                        ->label('Sync Metadata')
                        ->icon('heroicon-o-arrow-path')
                        ->color('primary')
                        ->action(function (\Illuminate\Support\Collection $records) {
                            $articleIds = $records->pluck('id')->toArray();
                            \App\Jobs\SyncArticlesMetadataBulkJob::dispatch($articleIds);
                            
                            Notification::make()
                                ->title('Bulk metadata sync started')
                                ->body('Syncing metadata for ' . count($articleIds) . ' articles in background...')
                                ->success()
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->modalHeading('Sync metadata for selected articles')
                        ->modalDescription('This will fetch metadata for all selected articles from Robaws. This may take a few minutes.')
                        ->modalSubmitActionLabel('Start Sync')
                        ->deselectRecordsAfterCompletion(),
                        
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->headerActions([
                // Old article sync removed - metadata sync now handled via bulk/row actions
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

