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
use Carbon\Carbon;

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
                                '%' => '%',
                                '20FT DV' => '20FT DV',
                                '20FT OT' => '20FT OT',
                                '40FT DV' => '40FT DV',
                                '40FT FR' => '40FT FR',
                                '40FT HC' => '40FT HC',
                                '40FT OT' => '40FT OT',
                                'CBM' => 'CBM',
                                'Chassis nr' => 'Chassis nr',
                                'Cont.' => 'Cont.',
                                'Day' => 'Day',
                                'Doc' => 'Doc',
                                'FRT' => 'FRT',
                                'Hour' => 'Hour',
                                'LM' => 'LM',
                                'Lumps.' => 'Lumps.',
                                'M3' => 'M3',
                                'Meter' => 'Meter',
                                'Rit' => 'Rit',
                                'RT' => 'RT',
                                'Shipm.' => 'Shipm.',
                                'SQM' => 'SQM',
                                'stacked unit' => 'stacked unit',
                                'Teu' => 'Teu',
                                'Ton' => 'Ton',
                                'Truck' => 'Truck',
                                'Unit' => 'Unit',
                                'Vehicle' => 'Vehicle',
                                'w/m' => 'w/m',
                            ])
                            ->searchable()
                            ->default('Unit')
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
                            
                        // customer_type removed - it's a quotation property, not article property
                        // carriers/applicable_carriers removed - use shipping_line instead
                        
                        Forms\Components\Toggle::make('is_parent_article')
                            ->label('Is Parent Article')
                            ->columnSpan(1),
                            
                        Forms\Components\Toggle::make('is_surcharge')
                            ->label('Is Surcharge/Add-on')
                            ->columnSpan(1),
                    ])
                    ->columns(2),
                    
                Forms\Components\Section::make('Smart Article Selection Fields')
                    ->description('Fields used for intelligent article filtering and suggestions')
                    ->schema([
                        Forms\Components\TextInput::make('shipping_line')
                            ->label('Shipping Line')
                            ->maxLength(100)
                            ->columnSpan(1),

                        Forms\Components\Select::make('transport_mode')
                            ->label('Transport Mode')
                            ->options([
                                'RORO' => 'RORO',
                                'FCL' => 'FCL',
                                'FCL CONSOL' => 'FCL CONSOL',
                                'LCL' => 'LCL',
                                'BB' => 'BB',
                                'AIRFREIGHT' => 'AIRFREIGHT',
                                'ROAD TRANSPORT' => 'ROAD TRANSPORT',
                                'CUSTOMS' => 'CUSTOMS',
                                'PORT FORWARDING' => 'PORT FORWARDING',
                                'VEHICLE PURCHASE' => 'VEHICLE PURCHASE',
                                'HOMOLOGATION' => 'HOMOLOGATION',
                                'WAREHOUSE' => 'WAREHOUSE',
                                'SEAFREIGHT' => 'SEAFREIGHT',
                                'OTHER' => 'OTHER',
                            ])
                            ->searchable()
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('service_type')
                            ->label('Service Type')
                            ->maxLength(100)
                            ->columnSpan(1),
                            
                        Forms\Components\TextInput::make('pol_terminal')
                            ->label('POL Terminal')
                            ->maxLength(100)
                            ->columnSpan(1),
                            
                        Forms\Components\Select::make('commodity_type')
                            ->label('Commodity Type')
                            ->options([
                                'Car' => 'Car',
                                'Small Van' => 'Small Van',
                                'Big Van' => 'Big Van',
                                'Very Big Van' => 'Very Big Van',
                                'HH' => 'HH',
                                'All cargo' => 'All cargo',
                                'FCL' => 'FCL',
                                'LCL' => 'LCL',
                                'BB' => 'BB',
                            ])
                            ->searchable()
                            ->placeholder('Select Commodity Type')
                            ->columnSpan(1),
                            
                        Forms\Components\Select::make('pol')
                            ->label('POL')
                            ->options([
                                'Antwerp (ANR), Belgium' => 'Antwerp (ANR), Belgium',
                                'Zeebrugge (ZEE), Belgium' => 'Zeebrugge (ZEE), Belgium',
                                'Flushing (FLU), Belgium' => 'Flushing (FLU), Belgium',
                                'Flushing (FLU), Netherlands' => 'Flushing (FLU), Netherlands',
                                'Jebel Ali (JEA), United Arab Emirates' => 'Jebel Ali (JEA), United Arab Emirates',
                                'Al Maktoum International (DWC), United Arab Emirates' => 'Al Maktoum International (DWC), United Arab Emirates',
                                'Dubai International (DXB), United Arab Emirates' => 'Dubai International (DXB), United Arab Emirates',
                            ])
                            ->searchable()
                            ->placeholder('Select POL')
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('pol_code')
                            ->label('POL Code')
                            ->maxLength(10)
                            ->columnSpan(1),
                            
                        Forms\Components\Select::make('pod')
                            ->label('POD')
                            ->options([
                                'Pointe-Noire (PNR), Congo' => 'Pointe-Noire (PNR), Congo',
                                'Dakar (DKR), Senegal' => 'Dakar (DKR), Senegal',
                                'Cotonou (COO), Benin' => 'Cotonou (COO), Benin',
                                'Conakry (CKY), Guinea' => 'Conakry (CKY), Guinea',
                                'Dar es Salaam (DAR), Tanzania' => 'Dar es Salaam (DAR), Tanzania',
                                'Douala (DLA), Cameroon' => 'Douala (DLA), Cameroon',
                                'Durban (DUR), South Africa' => 'Durban (DUR), South Africa',
                                'East London (ELS), South Africa' => 'East London (ELS), South Africa',
                                'Lagos (LOS), Nigeria' => 'Lagos (LOS), Nigeria',
                                'Lomé (LFW), Togo' => 'Lomé (LFW), Togo',
                                'Nouakchott (NKC), Mauritania' => 'Nouakchott (NKC), Mauritania',
                                'Libreville (LBV), Gabon' => 'Libreville (LBV), Gabon',
                                'Freetown (FNA), Sierra Leone' => 'Freetown (FNA), Sierra Leone',
                                'Abidjan (ABJ), Ivory Coast' => 'Abidjan (ABJ), Ivory Coast',
                                'Antwerp (ANR), Belgium' => 'Antwerp (ANR), Belgium',
                                'Zeebrugge (ZEE), Belgium' => 'Zeebrugge (ZEE), Belgium',
                                'Flushing (FLU), Belgium' => 'Flushing (FLU), Belgium',
                                'Jebel Ali (JEA), United Arab Emirates' => 'Jebel Ali (JEA), United Arab Emirates',
                                'Al Maktoum International (DWC), United Arab Emirates' => 'Al Maktoum International (DWC), United Arab Emirates',
                                'Dubai International (DXB), United Arab Emirates' => 'Dubai International (DXB), United Arab Emirates',
                            ])
                            ->searchable()
                            ->placeholder('Select POD')
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('pod_code')
                            ->label('POD Code')
                            ->maxLength(10)
                            ->columnSpan(1),
                    ])
                    ->columns(3)
                    ->collapsible()
                    ->collapsed(false),

                Forms\Components\Section::make('Robaws Extra Fields')
                    ->description('Direct mappings from Robaws extra fields; kept to track metadata quality.')
                    ->schema([
                        Forms\Components\Select::make('article_type')
                            ->label('Article Type')
                            ->options([
                                'SEAFREIGHT' => 'SEAFREIGHT',
                                'SEAFREIGHT SURCHARGES' => 'SEAFREIGHT SURCHARGES',
                                'LOCAL CHARGES POL' => 'LOCAL CHARGES POL',
                                'LOCAL CHARGES POD' => 'LOCAL CHARGES POD',
                                'ROAD TRANSPORT SURCHARGES' => 'ROAD TRANSPORT SURCHARGES',
                                'INSPECTION SURCHARGES' => 'INSPECTION SURCHARGES',
                                'ADMINISTRATIVE / MISC. SURCHARGES' => 'ADMINISTRATIVE / MISC. SURCHARGES',
                            ])
                            ->searchable()
                            ->placeholder('Select Article Type')
                            ->columnSpan(1),

                        Forms\Components\Select::make('cost_side')
                            ->label('Cost Side')
                            ->options([
                                'POL' => 'POL',
                                'POD' => 'POD',
                                'SEA' => 'SEA',
                                'AIR' => 'AIR',
                                'INLAND' => 'INLAND',
                                'ADMIN' => 'ADMIN',
                                'WAREHOUSE' => 'WAREHOUSE',
                            ])
                            ->searchable()
                            ->placeholder('Select Cost Side')
                            ->columnSpan(1),

                        Forms\Components\Toggle::make('is_mandatory')
                            ->label('Is Mandatory')
                            ->inline(false)
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('mandatory_condition')
                            ->label('Mandatory Condition')
                            ->maxLength(255)
                            ->columnSpan(2),

                        Forms\Components\Textarea::make('notes')
                            ->rows(2)
                            ->columnSpan(2),

                        Forms\Components\Textarea::make('article_info')
                            ->label('Article Info (raw)')
                            ->rows(2)
                            ->columnSpanFull(),

                        Forms\Components\DatePicker::make('update_date')
                            ->label('Update Date')
                            ->native(false)
                            ->displayFormat('d-m-Y')
                            ->placeholder('Select update date')
                            ->columnSpan(1),

                        Forms\Components\DatePicker::make('validity_date')
                            ->label('Validity Date')
                            ->native(false)
                            ->displayFormat('d-m-Y')
                            ->placeholder('Select validity date')
                            ->columnSpan(1),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(false),
                    
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
            ->defaultPaginationPageOption(25)
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
                    
                Tables\Columns\TextColumn::make('sales_name')
                    ->label('Sales Name')
                    ->searchable()
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                    
                Tables\Columns\TextColumn::make('brand')
                    ->searchable()
                    ->badge()
                    ->color('info')
                    ->toggleable(isToggledHiddenByDefault: true),
                    
                Tables\Columns\TextColumn::make('article_number')
                    ->label('Article #')
                    ->searchable()
                    ->toggleable(),
                    
                Tables\Columns\TextColumn::make('barcode')
                    ->toggleable(isToggledHiddenByDefault: true),
                    
                Tables\Columns\TextColumn::make('sale_price')
                    ->label('Sale Price')
                    ->money('EUR')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                    
                Tables\Columns\TextColumn::make('cost_price')
                    ->label('Cost Price')
                    ->money('EUR')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                    
                Tables\Columns\TextColumn::make('weight_kg')
                    ->label('Weight (kg)')
                    ->numeric(2)
                    ->toggleable(isToggledHiddenByDefault: true),
                    
                Tables\Columns\IconColumn::make('stock_article')
                    ->label('Stock')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                    
                Tables\Columns\IconColumn::make('wappy')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                    
                Tables\Columns\TextColumn::make('composite_items')
                    ->label('Composite Items')
                    ->formatStateUsing(fn ($state) => $state ? count($state) . ' items' : '-')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                
                Tables\Columns\BadgeColumn::make('shipping_line')
                    ->label('Shipping Line')
                    ->formatStateUsing(fn ($state) => $state ?: 'Not specified')
                    ->color(fn ($state) => $state ? 'primary' : 'gray')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('transport_mode')
                    ->label('Transport Mode')
                    ->formatStateUsing(fn ($state) => $state ?: 'N/A')
                    ->color(fn ($state) => $state ? 'warning' : 'gray')
                    ->toggleable(),
                    
                Tables\Columns\BadgeColumn::make('service_type')
                    ->label('Service Type')
                    ->formatStateUsing(fn ($state) => $state ?: 'Not specified')
                    ->color(fn ($state) => $state ? 'success' : 'gray')
                    ->toggleable(),
                
                // POL in full Robaws format
                Tables\Columns\TextColumn::make('pol')
                    ->label('POL')
                    ->formatStateUsing(fn ($state) => $state ?: 'N/A')
                    ->color(fn ($state) => $state ? 'success' : 'gray')
                    ->tooltip('Port of Loading')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('pol_code')
                    ->label('POL Code')
                    ->formatStateUsing(fn ($state) => $state ?: '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                    
                Tables\Columns\TextColumn::make('pol_terminal')
                    ->label('POL Terminal')
                    ->formatStateUsing(fn ($state) => $state ?: 'N/A')
                    ->color(fn ($state) => $state ? 'primary' : 'gray')
                    ->tooltip(fn ($state) => $state ? null : 'Not available in Robaws')
                    ->toggleable(),
                
                // POD in full Robaws format
                Tables\Columns\TextColumn::make('pod')
                    ->label('POD')
                    ->formatStateUsing(fn ($state) => $state ?: 'N/A')
                    ->color(fn ($state) => $state ? 'info' : 'gray')
                    ->tooltip('Port of Discharge')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('pod_code')
                    ->label('POD Code')
                    ->formatStateUsing(fn ($state) => $state ?: '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                    
                Tables\Columns\BadgeColumn::make('commodity_type')
                    ->label('Commodity Type')
                    ->formatStateUsing(fn ($state) => $state ?: 'N/A')
                    ->color(fn ($state) => $state ? 'warning' : 'gray')
                    ->tooltip('For Smart Article Selection')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\BadgeColumn::make('article_type')
                    ->label('Article Type')
                    ->formatStateUsing(fn ($state) => $state ?: 'N/A')
                    ->color(fn ($state) => $state ? 'secondary' : 'gray')
                    ->toggleable(),
                    
                Tables\Columns\IconColumn::make('is_parent_article')
                    ->boolean()
                    ->label('Parent')
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->tooltip('Parent article status from Robaws API')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_mandatory')
                    ->boolean()
                    ->label('Mandatory')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('cost_side')
                    ->label('Cost Side')
                    ->formatStateUsing(fn ($state) => $state ?: 'N/A')
                    ->color(fn ($state) => match ($state) {
                        'POL' => 'primary',
                        'POD' => 'info',
                        'SEA' => 'success',
                        'AIR' => 'warning',
                        'INLAND' => 'secondary',
                        'ADMIN' => 'gray',
                        default => 'gray',
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('mandatory_condition')
                    ->label('Mandatory Condition')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('notes')
                    ->label('Notes')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),
                    
                Tables\Columns\TextColumn::make('effective_validity_date')
                    ->date('M d, Y')
                    ->label('Valid Until')
                    ->placeholder('Not set')
                    ->color(fn ($state) => $state && $state >= now() ? 'success' : 'gray')
                    ->toggleable(),
                    
            Tables\Columns\TextColumn::make('applicable_services')
                ->badge()
                ->separator(',')
                ->formatStateUsing(function ($state, $record) {
                    $services = is_string($state) ? json_decode($state, true) : $state;
                    
                    if (is_array($services) && count($services) > 0) {
                        // Return as string for Filament to handle properly
                        return implode(', ', $services);
                    }
                    
                    // Fallback: show service_type if available
                    if ($record->service_type) {
                        return $record->service_type;
                    }
                    
                    return 'Not specified';
                })
                ->color('success')
                ->limit(50) // Limit characters, not array items
                ->tooltip(function ($state, $record) {
                    $services = is_string($state) ? json_decode($state, true) : $state;
                    
                    if (is_array($services) && count($services) > 2) {
                        return implode(', ', $services);
                    }
                    
                    // Show direction hint
                    if ($record->pol && $record->pod) {
                        return 'Direction-aware services based on POL/POD routing';
                    }
                    
                    return 'Service types this article applies to';
                })
                ->toggleable(),
                    
                Tables\Columns\TextColumn::make('children_count')
                    ->counts('children')
                    ->label('Children')
                    ->badge()
                    ->color('info')
                    ->toggleable(),
                    
                // customer_type column removed - it's a quotation property
                    
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

                Tables\Columns\BadgeColumn::make('metadata_source')
                    ->label('Sync Source')
                    ->state(fn (RobawsArticleCache $record) => str_contains((string) $record->article_info, 'Extracted from description') ? 'Fallback' : 'API')
                    ->color(fn (RobawsArticleCache $record) => str_contains((string) $record->article_info, 'Extracted from description') ? 'warning' : 'success')
                    ->tooltip(fn (RobawsArticleCache $record) => str_contains((string) $record->article_info, 'Extracted from description') ? 'Populated via name parsing fallback' : 'Fetched directly from Robaws API')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('brand')
                    ->label('Brand')
                    ->options(fn () => RobawsArticleCache::distinct()
                        ->whereNotNull('brand')
                        ->pluck('brand', 'brand')
                        ->toArray()),
                        
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

                Tables\Filters\SelectFilter::make('transport_mode')
                    ->label('Transport Mode')
                    ->options(fn () => RobawsArticleCache::distinct()
                        ->whereNotNull('transport_mode')
                        ->pluck('transport_mode', 'transport_mode')
                        ->toArray()),
                        
                Tables\Filters\SelectFilter::make('pol')
                    ->label('POL')
                    ->options(fn () => RobawsArticleCache::distinct()
                        ->whereNotNull('pol')
                        ->pluck('pol', 'pol')
                        ->toArray()),

                Tables\Filters\SelectFilter::make('pol_code')
                    ->label('POL Code')
                    ->options(fn () => RobawsArticleCache::distinct()
                        ->whereNotNull('pol_code')
                        ->pluck('pol_code', 'pol_code')
                        ->toArray()),
                        
                Tables\Filters\SelectFilter::make('pol_terminal')
                    ->label('POL Terminal')
                    ->options(fn () => RobawsArticleCache::distinct()
                        ->whereNotNull('pol_terminal')
                        ->pluck('pol_terminal', 'pol_terminal')
                        ->toArray()),
                        
                Tables\Filters\SelectFilter::make('pod')
                    ->label('POD')
                    ->options(fn () => RobawsArticleCache::distinct()
                        ->whereNotNull('pod')
                        ->pluck('pod', 'pod')
                        ->toArray()),

                Tables\Filters\SelectFilter::make('pod_code')
                    ->label('POD Code')
                    ->options(fn () => RobawsArticleCache::distinct()
                        ->whereNotNull('pod_code')
                        ->pluck('pod_code', 'pod_code')
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

                Tables\Filters\SelectFilter::make('article_type')
                    ->label('Article Type')
                    ->options(fn () => RobawsArticleCache::distinct()
                        ->whereNotNull('article_type')
                        ->pluck('article_type', 'article_type')
                        ->toArray()),

                Tables\Filters\SelectFilter::make('cost_side')
                    ->label('Cost Side')
                    ->options(fn () => RobawsArticleCache::distinct()
                        ->whereNotNull('cost_side')
                        ->pluck('cost_side', 'cost_side')
                        ->toArray()),
                    
                Tables\Filters\TernaryFilter::make('is_parent_article')
                    ->label('Is Parent Article')
                    ->placeholder('All articles')
                    ->trueLabel('Only parent articles')
                    ->falseLabel('Only child articles'),

                Tables\Filters\TernaryFilter::make('is_mandatory')
                    ->label('Is Mandatory')
                    ->placeholder('All articles')
                    ->trueLabel('Mandatory only')
                    ->falseLabel('Optional only'),
                    
                Tables\Filters\TernaryFilter::make('is_surcharge')
                    ->label('Is Surcharge')
                    ->placeholder('All articles')
                    ->trueLabel('Only surcharges')
                    ->falseLabel('Exclude surcharges'),
                    
                // customer_type filter removed - it's a quotation property
                    
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
                        ->label('Sync Metadata (Fast)')
                        ->icon('heroicon-o-arrow-path')
                        ->color('success')
                        ->action(function (\Illuminate\Support\Collection $records) {
                            $provider = app(\App\Services\Robaws\RobawsArticleProvider::class);
                            
                            $successCount = 0;
                            $failCount = 0;
                            $totalCount = $records->count();
                            
                            foreach ($records as $record) {
                                try {
                                    // Use fast metadata extraction (no API calls)
                                    $provider->syncArticleMetadata($record->id, useApi: false);
                                    $successCount++;
                                } catch (\Exception $e) {
                                    $failCount++;
                                    \Illuminate\Support\Facades\Log::warning('Bulk metadata sync failed for article', [
                                        'article_id' => $record->id,
                                        'article_name' => $record->article_name,
                                        'error' => $e->getMessage()
                                    ]);
                                }
                            }
                            
                            Notification::make()
                                ->title('Bulk metadata sync completed!')
                                ->body("Processed {$totalCount} articles. Success: {$successCount}, Failed: {$failCount}")
                                ->success()
                                ->duration(8000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->modalHeading('Sync metadata for selected articles')
                        ->modalDescription('This will fast-sync metadata (POL/POD, service type, shipping line) for the selected articles using name extraction. No API calls needed - instant processing.')
                        ->modalSubmitActionLabel('Sync Now')
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('bulk_update_fields')
                        ->label('Update Fields')
                        ->icon('heroicon-o-pencil-square')
                        ->color('warning')
                        ->form([
                            Forms\Components\TextInput::make('pol')
                                ->label('POL')
                                ->placeholder('Leave unchanged'),
                            Forms\Components\TextInput::make('pod')
                                ->label('POD')
                                ->placeholder('Leave unchanged'),
                            Forms\Components\TextInput::make('pol_terminal')
                                ->label('POL Terminal')
                                ->maxLength(100)
                                ->placeholder('Leave unchanged'),
                            Forms\Components\Textarea::make('article_info')
                                ->label('Article Info (raw)')
                                ->rows(3)
                                ->placeholder('Leave unchanged'),
                            Forms\Components\Select::make('is_parent_article')
                                ->label('Parent Article?')
                                ->options([
                                    '1' => 'Yes',
                                    '0' => 'No',
                                ])
                                ->placeholder('Leave unchanged'),
                            Forms\Components\Select::make('is_surcharge')
                                ->label('Surcharge Add-on?')
                                ->options([
                                    '1' => 'Yes',
                                    '0' => 'No',
                                ])
                                ->placeholder('Leave unchanged'),
                            Forms\Components\DatePicker::make('update_date')
                                ->label('Update Date')
                                ->native(false)
                                ->displayFormat('d-m-Y'),
                            Forms\Components\DatePicker::make('validity_date')
                                ->label('Validity Date')
                                ->native(false)
                                ->displayFormat('d-m-Y'),
                            Forms\Components\TextInput::make('shipping_line')
                                ->label('Shipping Line')
                                ->maxLength(100)
                                ->placeholder('Leave unchanged'),
                            Forms\Components\Select::make('transport_mode')
                                ->label('Transport Mode')
                                ->options([
                                    'RORO' => 'RORO',
                                    'FCL' => 'FCL',
                                    'FCL CONSOL' => 'FCL CONSOL',
                                    'LCL' => 'LCL',
                                    'BB' => 'BB',
                                    'AIRFREIGHT' => 'AIRFREIGHT',
                                    'ROAD TRANSPORT' => 'ROAD TRANSPORT',
                                    'CUSTOMS' => 'CUSTOMS',
                                    'PORT FORWARDING' => 'PORT FORWARDING',
                                    'VEHICLE PURCHASE' => 'VEHICLE PURCHASE',
                                    'HOMOLOGATION' => 'HOMOLOGATION',
                                    'WAREHOUSE' => 'WAREHOUSE',
                                    'SEAFREIGHT' => 'SEAFREIGHT',
                                    'OTHER' => 'OTHER',
                                ])
                                ->placeholder('Leave unchanged')
                                ->searchable(),
                            Forms\Components\TextInput::make('service_type')
                                ->label('Service Type')
                                ->maxLength(100)
                                ->placeholder('Leave unchanged'),
                            Forms\Components\Select::make('commodity_type')
                                ->label('Commodity Type')
                                ->options([
                                    'Car' => 'Car',
                                    'Small Van' => 'Small Van',
                                    'Big Van' => 'Big Van',
                                    'Very Big Van' => 'Very Big Van',
                                    'HH' => 'HH',
                                    'All cargo' => 'All cargo',
                                    'FCL' => 'FCL',
                                    'LCL' => 'LCL',
                                    'BB' => 'BB',
                                ])
                                ->placeholder('Leave unchanged')
                                ->searchable(),
                            Forms\Components\TextInput::make('unit_price')
                                ->label('Unit Price')
                                ->numeric()
                                ->placeholder('Leave unchanged'),
                            Forms\Components\TextInput::make('sale_price')
                                ->label('Sale Price')
                                ->numeric()
                                ->placeholder('Leave unchanged'),
                            Forms\Components\TextInput::make('cost_price')
                                ->label('Cost Price')
                                ->numeric()
                                ->placeholder('Leave unchanged'),
                            Forms\Components\TextInput::make('tier_label')
                                ->label('Tier Label')
                                ->maxLength(50)
                                ->placeholder('Leave unchanged'),
                            Forms\Components\Select::make('article_type')
                                ->label('Article Type')
                                ->options([
                                    'SEAFREIGHT' => 'SEAFREIGHT',
                                    'SEAFREIGHT SURCHARGES' => 'SEAFREIGHT SURCHARGES',
                                    'LOCAL CHARGES POL' => 'LOCAL CHARGES POL',
                                    'LOCAL CHARGES POD' => 'LOCAL CHARGES POD',
                                    'ROAD TRANSPORT SURCHARGES' => 'ROAD TRANSPORT SURCHARGES',
                                    'INSPECTION SURCHARGES' => 'INSPECTION SURCHARGES',
                                    'ADMINISTRATIVE / MISC. SURCHARGES' => 'ADMINISTRATIVE / MISC. SURCHARGES',
                                ])
                                ->placeholder('Leave unchanged')
                                ->searchable(),
                            Forms\Components\Select::make('cost_side')
                                ->label('Cost Side')
                                ->options([
                                    'POL' => 'POL',
                                    'POD' => 'POD',
                                    'SEA' => 'SEA',
                                    'AIR' => 'AIR',
                                    'INLAND' => 'INLAND',
                                    'ADMIN' => 'ADMIN',
                                    'WAREHOUSE' => 'WAREHOUSE',
                                ])
                                ->placeholder('Leave unchanged')
                                ->searchable(),
                            Forms\Components\Select::make('is_mandatory')
                                ->label('Mandatory?')
                                ->options([
                                    '1' => 'Yes',
                                    '0' => 'No',
                                ])
                                ->placeholder('Leave unchanged'),
                            Forms\Components\Select::make('requires_manual_review')
                                ->label('Requires Manual Review?')
                                ->options([
                                    '1' => 'Yes',
                                    '0' => 'No',
                                ])
                                ->placeholder('Leave unchanged'),
                            Forms\Components\Select::make('is_parent_item')
                                ->label('Parent Item Flag?')
                                ->options([
                                    '1' => 'Yes',
                                    '0' => 'No',
                                ])
                                ->placeholder('Leave unchanged'),
                        ])
                        ->action(function (\Illuminate\Support\Collection $records, array $data) {
                            $fields = collect($data)
                                ->map(fn ($value) => is_string($value) ? trim($value) : $value)
                                ->reject(fn ($value) => $value === null || $value === '');

                            if ($fields->isEmpty()) {
                                Notification::make()
                                    ->title('No changes applied')
                                    ->body('Please provide at least one field value to update.')
                                    ->warning()
                                    ->send();
                                return;
                            }

                            $updates = $fields->toArray();

                            foreach (['is_parent_article', 'is_surcharge', 'is_mandatory', 'requires_manual_review', 'is_parent_item'] as $booleanField) {
                                if (array_key_exists($booleanField, $updates)) {
                                    $updates[$booleanField] = $updates[$booleanField] === '1';
                                }
                            }

                            foreach (['update_date', 'validity_date'] as $dateField) {
                                if (array_key_exists($dateField, $updates) && $updates[$dateField]) {
                                    $updates[$dateField] = Carbon::parse($updates[$dateField])->format('Y-m-d');
                                }
                            }

                            foreach (['unit_price', 'sale_price', 'cost_price'] as $numericField) {
                                if (array_key_exists($numericField, $updates)) {
                                    $updates[$numericField] = $updates[$numericField] === '' ? null : (float) $updates[$numericField];
                                }
                            }

                            $updatedCount = 0;

                            foreach ($records as $record) {
                                $record->update($updates);
                                $updatedCount++;
                            }

                            Notification::make()
                                ->title('Bulk update complete')
                                ->body("Updated {$updatedCount} article" . ($updatedCount === 1 ? '' : 's') . '.')
                                ->success()
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->modalHeading('Update selected articles')
                        ->modalDescription('Only the fields you fill in will be applied to the selected articles.')
                        ->modalSubmitActionLabel('Apply Changes')
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
            \App\Filament\Resources\RobawsArticleResource\RelationManagers\CompositeItemsRelationManager::class,
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

