<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuotationRequestResource\Pages;
use App\Models\QuotationRequest;
use App\Models\RobawsArticleCache;
use App\Filament\Forms\Components\ArticleSelector;
use App\Filament\Forms\Components\PriceCalculator;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Illuminate\Database\Eloquent\Builder;

class QuotationRequestResource extends Resource
{
    protected static ?string $model = QuotationRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    
    protected static ?string $navigationLabel = 'Quotations';
    
    protected static ?string $modelLabel = 'Quotation';
    
    protected static ?string $pluralModelLabel = 'Quotations';
    
    protected static ?string $navigationGroup = 'Quotation System';
    
    protected static ?int $navigationSort = 11;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Client Information')
                    ->description('Company or organization details (maps to Robaws Client)')
                    ->schema([
                        Forms\Components\TextInput::make('client_name')
                            ->label('Company Name')
                            ->maxLength(255)
                            ->columnSpan(1),
                            
                        Forms\Components\TextInput::make('client_email')
                            ->label('Company Email')
                            ->email()
                            ->maxLength(255)
                            ->columnSpan(1),
                            
                        Forms\Components\TextInput::make('client_tel')
                            ->label('Company Phone')
                            ->tel()
                            ->maxLength(50)
                            ->columnSpan(1),
                            
                        Forms\Components\TextInput::make('robaws_client_id')
                            ->label('Robaws Client ID')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn ($record) => $record?->robaws_client_id)
                            ->columnSpan(1),
                    ])
                    ->columns(2)
                    ->collapsible(),
                    
                Forms\Components\Section::make('Contact Person')
                    ->description('Person making the quotation request (maps to Robaws Contact)')
                    ->schema([
                        Forms\Components\TextInput::make('contact_name')
                            ->label('Contact Name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(1),
                            
                        Forms\Components\TextInput::make('contact_email')
                            ->label('Contact Email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(1),
                            
                        Forms\Components\TextInput::make('contact_phone')
                            ->label('Contact Phone')
                            ->tel()
                            ->maxLength(50)
                            ->columnSpan(1),
                            
                        Forms\Components\TextInput::make('contact_function')
                            ->label('Job Title / Function')
                            ->maxLength(100)
                            ->columnSpan(1),
                            
                        Forms\Components\TextInput::make('customer_reference')
                            ->label('Customer Reference')
                            ->maxLength(100)
                            ->columnSpan(2),
                            
                        Forms\Components\Select::make('customer_role')
                            ->label('Customer Role / Type')
                            ->options(config('quotation.customer_roles', []))
                            ->default('CONSIGNEE')
                            ->required()
                            ->searchable()
                            ->helperText('WHO is the customer? (for categorization, CRM, and reporting)')
                            ->columnSpan(1),
                            
                        Forms\Components\Select::make('pricing_tier_id')
                            ->label('Pricing Tier')
                            ->relationship('pricingTier', 'name')
                            ->getOptionLabelFromRecordUsing(fn ($record) => 
                                sprintf(
                                    '%s Tier %s - %s (%s%s%%)',
                                    $record->icon ?? '',
                                    $record->code,
                                    $record->name,
                                    $record->margin_percentage > 0 ? '+' : '',
                                    number_format($record->margin_percentage, 1)
                                )
                            )
                            ->searchable()
                            ->required(false) // Optional until migrations run
                            ->preload()
                            ->default(function () {
                                try {
                                    // Default to Tier B (Medium Price)
                                    return \App\Models\PricingTier::where('code', 'B')->where('is_active', true)->first()?->id;
                                } catch (\Exception $e) {
                                    // Table might not exist yet if migrations haven't run
                                    return null;
                                }
                            })
                            ->helperText('WHAT pricing do they get? Margins are editable in Pricing Tiers menu')
                            ->visible(fn () => \Schema::hasTable('pricing_tiers')) // Only show if table exists
                            ->columnSpan(1),
                            
                        // Hidden fields for required database columns
                        Forms\Components\Hidden::make('source')
                            ->default('intake')
                            ->dehydrated(),
                        Forms\Components\Hidden::make('requester_type')
                            ->default('admin')
                            ->dehydrated(),
                        Forms\Components\Hidden::make('trade_direction')
                            ->afterStateHydrated(function ($component, $state, $get) {
                                // Auto-calculate from service_type if not set
                                if (!$state && $get('service_type')) {
                                    $component->state(self::getDirectionFromServiceType($get('service_type')));
                                }
                            })
                            ->dehydrateStateUsing(function ($state, $get) {
                                // Always derive from service_type
                                return self::getDirectionFromServiceType($get('service_type') ?? '');
                            })
                            ->dehydrated(),
                        Forms\Components\Hidden::make('robaws_sync_status')
                            ->default('pending')
                            ->dehydrated(),
                        Forms\Components\Hidden::make('pricing_currency')
                            ->default('EUR')
                            ->dehydrated(),
                    ])
                    ->columns(2)
                    ->collapsible(),
                    
                Forms\Components\Section::make('Route & Service Information')
                    ->schema([
                        Forms\Components\Placeholder::make('simple_service_type_display')
                            ->label('Customer Selected')
                            ->content(function ($record) {
                                if (!$record || !$record->simple_service_type) {
                                    return '-';
                                }
                                $type = config("quotation.simple_service_types.{$record->simple_service_type}");
                                if (!$type) {
                                    return $record->simple_service_type;
                                }
                                return $type['icon'] . ' ' . $type['name'];
                            })
                            ->visible(fn ($record) => $record?->simple_service_type)
                            ->columnSpan(1),
                        
                        Forms\Components\Select::make('service_type')
                            ->label('Actual Service Type (Team Selection)')
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
                            ->required()
                            ->live()
                            ->columnSpan(fn ($record) => $record?->simple_service_type ? 1 : 2),
                            
                        Forms\Components\TextInput::make('por')
                            ->label('Place of Receipt (POR)')
                            ->placeholder('Optional - e.g., Brussels, Paris')
                            ->maxLength(100)
                            ->columnSpan(1),
                            
                        Forms\Components\Select::make('pol')
                            ->label('Port of Loading (POL)')
                            ->options(function (Forms\Get $get) {
                                $serviceType = $get('service_type');
                                
                                // Show airports for airfreight services
                                if (in_array($serviceType, ['AIRFREIGHT_EXPORT', 'AIRFREIGHT_IMPORT'])) {
                                    $airports = config('airports', []);
                                    return collect($airports)->mapWithKeys(function ($airport, $code) {
                                        return [$airport['name'] => $airport['full_name']];
                                    });
                                }
                                
                                // Show seaports for all other services
                                return \App\Models\Port::europeanOrigins()
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(function ($port) {
                                        return [$port->name => $port->name . ' (' . $port->code . '), ' . $port->country];
                                    });
                            })
                            ->searchable()
                            ->allowHtml()
                            ->createOptionUsing(fn (string $value): string => $value)
                            ->getSearchResultsUsing(function (string $search, Forms\Get $get) {
                                $serviceType = $get('service_type');
                                
                                if (in_array($serviceType, ['AIRFREIGHT_EXPORT', 'AIRFREIGHT_IMPORT'])) {
                                    $airports = config('airports', []);
                                    $results = collect($airports)
                                        ->filter(fn($airport) => 
                                            str_contains(strtolower($airport['name']), strtolower($search)) ||
                                            str_contains(strtolower($airport['code']), strtolower($search))
                                        )
                                        ->mapWithKeys(fn($airport) => [$airport['name'] => $airport['full_name']]);
                                    
                                    // Add custom option at the end for airports
                                    if (!empty($search)) {
                                        $results->put($search, "Custom airport: {$search}");
                                    }
                                } else {
                                    $results = \App\Models\Port::europeanOrigins()
                                        ->where(function($q) use ($search) {
                                            $q->where('name', 'like', "%{$search}%")
                                              ->orWhere('code', 'like', "%{$search}%");
                                        })
                                        ->get()
                                        ->mapWithKeys(fn($port) => [$port->name => $port->name . ' (' . $port->code . '), ' . $port->country]);
                                    
                                    // Add custom option at the end for seaports
                                    if (!empty($search)) {
                                        $results->put($search, "Custom seaport: {$search}");
                                    }
                                }
                                
                                return $results->all();
                            })
                            ->createOptionUsing(fn (string $value): string => $value)
                            ->required()
                            ->live()
                            ->reactive()
                            ->afterStateUpdated(fn (Forms\Set $set) => $set('selected_schedule_id', null))
                            ->helperText(function (Forms\Get $get) {
                                $serviceType = $get('service_type');
                                if (in_array($serviceType, ['AIRFREIGHT_EXPORT', 'AIRFREIGHT_IMPORT'])) {
                                    return 'Select airport or type custom name (press Enter)';
                                }
                                return 'Select seaport or type custom name (press Enter)';
                            })
                            ->columnSpan(1),
                            
                        Forms\Components\Select::make('pod')
                            ->label('Port of Discharge (POD)')
                            ->options(function (Forms\Get $get) {
                                $serviceType = $get('service_type');
                                
                                // Show airports for airfreight services
                                if (in_array($serviceType, ['AIRFREIGHT_EXPORT', 'AIRFREIGHT_IMPORT'])) {
                                    $airports = config('airports', []);
                                    return collect($airports)->mapWithKeys(function ($airport, $code) {
                                        return [$airport['name'] => $airport['full_name']];
                                    });
                                }
                                
                                // Show seaports for all other services
                                return \App\Models\Port::withActivePodSchedules()
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(function ($port) {
                                        return [$port->name => $port->name . ' (' . $port->code . '), ' . $port->country];
                                    });
                            })
                            ->searchable()
                            ->allowHtml()
                            ->createOptionUsing(fn (string $value): string => $value)
                            ->getSearchResultsUsing(function (string $search, Forms\Get $get) {
                                $serviceType = $get('service_type');
                                
                                if (in_array($serviceType, ['AIRFREIGHT_EXPORT', 'AIRFREIGHT_IMPORT'])) {
                                    $airports = config('airports', []);
                                    $results = collect($airports)
                                        ->filter(fn($airport) => 
                                            str_contains(strtolower($airport['name']), strtolower($search)) ||
                                            str_contains(strtolower($airport['code']), strtolower($search))
                                        )
                                        ->mapWithKeys(fn($airport) => [$airport['name'] => $airport['full_name']]);
                                    
                                    // Add custom option at the end for airports
                                    if (!empty($search)) {
                                        $results->put($search, "Custom airport: {$search}");
                                    }
                                } else {
                                    $results = \App\Models\Port::withActivePodSchedules()
                                        ->where(function($q) use ($search) {
                                            $q->where('name', 'like', "%{$search}%")
                                              ->orWhere('code', 'like', "%{$search}%");
                                        })
                                        ->get()
                                        ->mapWithKeys(fn($port) => [$port->name => $port->name . ' (' . $port->code . '), ' . $port->country]);
                                    
                                    // Add custom option at the end for seaports
                                    if (!empty($search)) {
                                        $results->put($search, "Custom seaport: {$search}");
                                    }
                                }
                                
                                return $results->all();
                            })
                            ->createOptionUsing(fn (string $value): string => $value)
                            ->required()
                            ->live()
                            ->reactive()
                            ->afterStateUpdated(fn (Forms\Set $set) => $set('selected_schedule_id', null))
                            ->helperText(function (Forms\Get $get) {
                                $serviceType = $get('service_type');
                                if (in_array($serviceType, ['AIRFREIGHT_EXPORT', 'AIRFREIGHT_IMPORT'])) {
                                    return 'Select airport or type custom name (press Enter)';
                                }
                                return 'Select seaport or type custom name (press Enter)';
                            })
                            ->columnSpan(1),
                            
                        Forms\Components\TextInput::make('fdest')
                            ->label('Final Destination (FDEST)')
                            ->placeholder('Optional - e.g., Bamako, Lagos')
                            ->maxLength(100)
                            ->columnSpan(1),
                            
                        Forms\Components\Select::make('commodity_type')
                            ->options([
                                'cars' => 'Cars',
                                'general_goods' => 'General Goods',
                                'personal_goods' => 'Personal Goods',
                                'motorcycles' => 'Motorcycles',
                                'trucks' => 'Trucks',
                                'machinery' => 'Machinery',
                                'breakbulk' => 'Break Bulk',
                            ])
                            ->required()
                            ->columnSpan(1),
                            
                        Forms\Components\Textarea::make('cargo_description')
                            ->label('Cargo Description')
                            ->rows(3)
                            ->default('')
                            ->required()
                            ->columnSpan(1),
                            
                        Forms\Components\Hidden::make('cargo_details')
                            ->default([])
                            ->dehydrated(),
                            
                    ])
                    ->columns(2),
                    
                Forms\Components\Section::make('Commodity Items')
                    ->description('Detailed commodity breakdown (New multi-commodity system)')
                    ->schema([
                        Forms\Components\Repeater::make('commodityItems')
                            ->relationship('commodityItems')
                            ->schema([
                                Forms\Components\Select::make('commodity_type')
                                    ->label('Type')
                                    ->options([
                                        'vehicles' => '🚗 Vehicles',
                                        'machinery' => '⚙️ Machinery',
                                        'boat' => '⛵ Boat',
                                        'general_cargo' => '📦 General Cargo',
                                    ])
                                    ->required()
                                    ->live()
                                    ->columnSpan(2),
                                    
                                Forms\Components\Select::make('category')
                                    ->label('Category')
                                    ->options(function (Forms\Get $get) {
                                        $type = $get('commodity_type');
                                        if (!$type) return [];
                                        
                                        $config = config("quotation.commodity_types.{$type}.categories", []);
                                        return $config;
                                    })
                                    ->visible(fn (Forms\Get $get) => in_array($get('commodity_type'), ['vehicles', 'machinery', 'general_cargo']))
                                    ->required(fn (Forms\Get $get) => in_array($get('commodity_type'), ['vehicles', 'machinery', 'general_cargo']))
                                    ->columnSpan(2),
                                    
                                Forms\Components\TextInput::make('make')
                                    ->label('Make')
                                    ->required(fn (Forms\Get $get) => in_array($get('commodity_type'), ['vehicles', 'machinery', 'boat']))
                                    ->visible(fn (Forms\Get $get) => in_array($get('commodity_type'), ['vehicles', 'machinery', 'boat']))
                                    ->columnSpan(1),
                                    
                                Forms\Components\TextInput::make('type_model')
                                    ->label('Type/Model')
                                    ->required(fn (Forms\Get $get) => in_array($get('commodity_type'), ['vehicles', 'machinery', 'boat']))
                                    ->visible(fn (Forms\Get $get) => in_array($get('commodity_type'), ['vehicles', 'machinery', 'boat']))
                                    ->columnSpan(1),
                                    
                                Forms\Components\Select::make('condition')
                                    ->label('Condition')
                                    ->options([
                                        'new' => 'New',
                                        'used' => 'Used',
                                        'damaged' => 'Damaged',
                                    ])
                                    ->visible(fn (Forms\Get $get) => in_array($get('commodity_type'), ['vehicles', 'machinery', 'boat']))
                                    ->columnSpan(1),
                                    
                                Forms\Components\Select::make('fuel_type')
                                    ->label('Fuel Type')
                                    ->options(function (Forms\Get $get) {
                                        $type = $get('commodity_type');
                                        if (!$type) return [];
                                        
                                        $config = config("quotation.commodity_types.{$type}.fuel_types", []);
                                        return $config;
                                    })
                                    ->visible(fn (Forms\Get $get) => in_array($get('commodity_type'), ['vehicles', 'machinery']))
                                    ->columnSpan(1),
                                    
                                Forms\Components\TextInput::make('length_cm')
                                    ->label('Length (cm)')
                                    ->numeric()
                                    ->columnSpan(1),
                                    
                                Forms\Components\TextInput::make('width_cm')
                                    ->label('Width (cm)')
                                    ->numeric()
                                    ->columnSpan(1),
                                    
                                Forms\Components\TextInput::make('height_cm')
                                    ->label('Height (cm)')
                                    ->numeric()
                                    ->columnSpan(1),
                                    
                                Forms\Components\TextInput::make('cbm')
                                    ->label('CBM (m³)')
                                    ->numeric()
                                    ->disabled()
                                    ->dehydrated()
                                    ->columnSpan(1),
                                    
                                Forms\Components\TextInput::make('weight_kg')
                                    ->label('Weight (kg)')
                                    ->numeric()
                                    ->visible(fn (Forms\Get $get) => !in_array($get('commodity_type'), ['general_cargo']))
                                    ->columnSpan(1),
                                    
                                Forms\Components\TextInput::make('bruto_weight_kg')
                                    ->label('Bruto Weight (kg)')
                                    ->numeric()
                                    ->visible(fn (Forms\Get $get) => $get('commodity_type') === 'general_cargo')
                                    ->columnSpan(1),
                                    
                                Forms\Components\TextInput::make('netto_weight_kg')
                                    ->label('Netto Weight (kg)')
                                    ->numeric()
                                    ->visible(fn (Forms\Get $get) => $get('commodity_type') === 'general_cargo')
                                    ->columnSpan(1),
                                    
                                Forms\Components\TextInput::make('wheelbase_cm')
                                    ->label('Wheelbase (cm)')
                                    ->numeric()
                                    ->visible(fn (Forms\Get $get) => 
                                        $get('commodity_type') === 'vehicles' && 
                                        in_array($get('category'), ['car', 'suv'])
                                    )
                                    ->columnSpan(1),
                                    
                                Forms\Components\TextInput::make('quantity')
                                    ->label('Quantity')
                                    ->numeric()
                                    ->default(1)
                                    ->required()
                                    ->columnSpan(1),
                                    
                                Forms\Components\Toggle::make('has_parts')
                                    ->label('Has Parts')
                                    ->visible(fn (Forms\Get $get) => $get('commodity_type') === 'machinery')
                                    ->columnSpan(1),
                                    
                                Forms\Components\Textarea::make('parts_description')
                                    ->label('Parts Description')
                                    ->rows(2)
                                    ->visible(fn (Forms\Get $get) => $get('commodity_type') === 'machinery' && $get('has_parts'))
                                    ->columnSpan(2),
                                    
                                Forms\Components\Toggle::make('has_trailer')
                                    ->label('Has Trailer')
                                    ->visible(fn (Forms\Get $get) => $get('commodity_type') === 'boat')
                                    ->columnSpan(1),
                                    
                                Forms\Components\Toggle::make('has_wooden_cradle')
                                    ->label('Has Wooden Cradle')
                                    ->visible(fn (Forms\Get $get) => $get('commodity_type') === 'boat')
                                    ->columnSpan(1),
                                    
                                Forms\Components\Toggle::make('has_iron_cradle')
                                    ->label('Has Iron Cradle')
                                    ->visible(fn (Forms\Get $get) => $get('commodity_type') === 'boat')
                                    ->columnSpan(1),
                                    
                                Forms\Components\Toggle::make('is_forkliftable')
                                    ->label('Forkliftable')
                                    ->visible(fn (Forms\Get $get) => 
                                        $get('commodity_type') === 'general_cargo' && 
                                        $get('category') !== 'palletized'
                                    )
                                    ->columnSpan(1),
                                    
                                Forms\Components\Toggle::make('is_hazardous')
                                    ->label('Hazardous')
                                    ->visible(fn (Forms\Get $get) => $get('commodity_type') === 'general_cargo')
                                    ->columnSpan(1),
                                    
                                Forms\Components\Toggle::make('is_unpacked')
                                    ->label('Unpacked')
                                    ->visible(fn (Forms\Get $get) => $get('commodity_type') === 'general_cargo')
                                    ->columnSpan(1),
                                    
                                Forms\Components\Toggle::make('is_ispm15')
                                    ->label('ISPM15 Wood')
                                    ->visible(fn (Forms\Get $get) => $get('commodity_type') === 'general_cargo')
                                    ->columnSpan(1),
                                    
                                Forms\Components\Textarea::make('extra_info')
                                    ->label('Extra Info')
                                    ->rows(2)
                                    ->columnSpan(2),
                            ])
                            ->columns(4)
                            ->itemLabel(fn (array $state): ?string => 
                                isset($state['commodity_type']) 
                                    ? ucfirst(str_replace('_', ' ', $state['commodity_type'])) . ' #' . ($state['line_number'] ?? '')
                                    : 'New Item'
                            )
                            ->collapsible()
                            ->cloneable()
                            ->reorderable()
                            ->defaultItems(0)
                            ->columnSpanFull(),
                            
                        Forms\Components\Placeholder::make('robaws_cargo_field')
                            ->label('Generated CARGO Field (for Robaws)')
                            ->content(fn ($record) => $record?->robaws_cargo_field ?? 'Not generated yet')
                            ->visible(fn ($record) => $record?->robaws_cargo_field)
                            ->columnSpan(1),
                            
                        Forms\Components\Placeholder::make('robaws_dim_field')
                            ->label('Generated DIM Field (for Robaws)')
                            ->content(fn ($record) => $record?->robaws_dim_field ?? 'Not generated yet')
                            ->visible(fn ($record) => $record?->robaws_dim_field)
                            ->columnSpan(1),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(fn ($record) => !$record?->hasMultiCommodityItems()),
                    
                Forms\Components\Section::make('Select Sailing')
                    ->description('Choose a specific sailing to filter carrier-specific articles')
                    ->schema([
                        Forms\Components\Placeholder::make('schedule_availability_status')
                            ->label('')
                            ->content(function (Forms\Get $get) {
                                $pol = $get('pol');
                                $pod = $get('pod');
                                
                                if (!$pol || !$pod) {
                                    return new \Illuminate\Support\HtmlString(
                                        '<div class="flex items-center gap-2 text-sm text-gray-500">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                            <span>Select POL and POD to check schedule availability</span>
                                        </div>'
                                    );
                                }
                                
                                // Check if custom ports are used (not in database)
                                $polIsCustom = !\App\Models\Port::where('name', 'like', "%{$pol}%")->exists();
                                $podIsCustom = !\App\Models\Port::where('name', 'like', "%{$pod}%")->exists();
                                
                                if ($polIsCustom || $podIsCustom) {
                                    return new \Illuminate\Support\HtmlString(
                                        '<div class="flex items-center gap-2 p-3 rounded-lg bg-blue-50 text-blue-700 border border-blue-200">
                                            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                            <span><strong>Custom port entered</strong> - Team will review and confirm availability</span>
                                        </div>'
                                    );
                                }
                                
                                // Check for schedules
                                $scheduleCount = \App\Models\ShippingSchedule::active()
                                    ->whereHas('polPort', fn($q) => $q->where('name', 'like', "%{$pol}%"))
                                    ->whereHas('podPort', fn($q) => $q->where('name', 'like', "%{$pod}%"))
                                    ->count();
                                
                                if ($scheduleCount > 0) {
                                    return new \Illuminate\Support\HtmlString(
                                        '<div class="flex items-center gap-2 p-3 rounded-lg bg-green-50 text-green-700 border border-green-200">
                                            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                            <span><strong>' . $scheduleCount . ' sailing' . ($scheduleCount > 1 ? 's' : '') . ' available</strong> - Select one below</span>
                                        </div>'
                                    );
                                }
                                
                                return new \Illuminate\Support\HtmlString(
                                    '<div class="flex items-center gap-2 p-3 rounded-lg bg-amber-50 text-amber-700 border border-amber-200">
                                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                                        <span><strong>No schedules found</strong> - Team review required for this port pair</span>
                                    </div>'
                                );
                            })
                            ->columnSpanFull(),
                            
                        Forms\Components\Select::make('selected_schedule_id')
                            ->label('Available Sailings')
                            ->options(function (Forms\Get $get) {
                                $pol = $get('pol');
                                $pod = $get('pod');
                                $serviceType = $get('service_type');
                                
                                if (!$pol || !$pod) {
                                    return [];
                                }
                                
                                return \App\Models\ShippingSchedule::active()
                                    ->whereHas('polPort', fn($q) => $q->where('name', 'like', "%{$pol}%"))
                                    ->whereHas('podPort', fn($q) => $q->where('name', 'like', "%{$pod}%"))
                                    // Service type filter removed - data format mismatch between config (RORO_IMPORT) and database (RORO)
                                    // User already selected service type separately, showing all sailings for route is helpful
                                    ->with(['carrier', 'polPort', 'podPort'])
                                    ->get()
                                    ->mapWithKeys(fn($schedule) => [
                                        $schedule->id => sprintf(
                                            '%s - %s → %s | Departs: %s | Transit: %d days',
                                            $schedule->carrier->name,
                                            $schedule->polPort->name,
                                            $schedule->podPort->name,
                                            $schedule->next_sailing_date?->format('M d, Y') ?? 'TBA',
                                            $schedule->transit_days ?? 0
                                        )
                                    ]);
                            })
                            ->live(onBlur: false)
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                if ($state) {
                                    $schedule = \App\Models\ShippingSchedule::with('carrier')->find($state);
                                    if ($schedule) {
                                        $set('preferred_carrier', $schedule->carrier->code);
                                    }
                                }
                            })
                            ->searchable()
                            ->helperText('Optional: Select a sailing to filter carrier-specific articles')
                            ->columnSpanFull(),
                            
                        Forms\Components\Placeholder::make('sailing_details')
                            ->label('Selected Sailing Details')
                            ->content(function (Forms\Get $get) {
                                $scheduleId = $get('selected_schedule_id');
                                if (!$scheduleId) {
                                    return 'No sailing selected';
                                }
                                
                                $schedule = \App\Models\ShippingSchedule::with(['carrier', 'polPort', 'podPort'])
                                    ->find($scheduleId);
                                    
                                if (!$schedule) {
                                    return 'Sailing not found';
                                }
                                
                                return new \Illuminate\Support\HtmlString(sprintf(
                                    '<div class="text-sm"><strong>%s</strong><br>Route: %s → %s<br>Service: %s<br>Transit: %d days<br>Next Sailing: %s</div>',
                                    $schedule->carrier->name,
                                    $schedule->polPort->name,
                                    $schedule->podPort->name,
                                    $schedule->service_name ?? 'N/A',
                                    $schedule->transit_days ?? 0,
                                    $schedule->next_sailing_date?->format('l, F j, Y') ?? 'TBA'
                                ));
                            })
                            ->visible(fn (Forms\Get $get) => $get('selected_schedule_id'))
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(false),
                    
                Forms\Components\Section::make('Articles & Pricing')
                    ->schema([
                        ArticleSelector::make('articles')
                            ->serviceType(fn ($get) => $get('service_type'))
                            ->carrierCode(fn ($get) => $get('preferred_carrier'))
                            ->quotationId(fn ($record) => $record?->id)
                            ->columnSpanFull(),
                            
                        PriceCalculator::make('pricing_summary')
                            ->pricingTierId(fn ($get) => $get('pricing_tier_id'))
                            ->customerRole(fn ($get) => $get('customer_role')) // Keep for backward compatibility
                            ->discountPercentage(fn ($get) => $get('discount_percentage') ?? 0)
                            ->vatRate(fn ($get) => $get('vat_rate') ?? 21)
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),
                    
                Forms\Components\Section::make('Pricing & Status')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->options([
                                'pending' => 'Pending',
                                'processing' => 'Processing',
                                'quoted' => 'Quoted',
                                'accepted' => 'Accepted',
                                'rejected' => 'Rejected',
                                'expired' => 'Expired',
                            ])
                            ->default('pending')
                            ->required()
                            ->columnSpan(1),
                            
                        Forms\Components\TextInput::make('discount_percentage')
                            ->numeric()
                            ->suffix('%')
                            ->default(0)
                            ->minValue(0)
                            ->maxValue(100)
                            ->columnSpan(1),
                            
                        Forms\Components\TextInput::make('vat_rate')
                            ->numeric()
                            ->suffix('%')
                            ->default(config('quotation.vat_rate', 21))
                            ->minValue(0)
                            ->maxValue(100)
                            ->columnSpan(1),
                            
                        Forms\Components\DatePicker::make('valid_until')
                            ->default(now()->addDays(30))
                            ->columnSpan(1),
                            
                        // Additional required fields
                        Forms\Components\Hidden::make('discount_amount')
                            ->default(0)
                            ->dehydrated(),
                        Forms\Components\Hidden::make('subtotal')
                            ->default(0)
                            ->dehydrated(),
                        Forms\Components\Hidden::make('total_excl_vat')
                            ->default(0)
                            ->dehydrated(),
                        Forms\Components\Hidden::make('vat_amount')
                            ->default(0)
                            ->dehydrated(),
                        Forms\Components\Hidden::make('total_incl_vat')
                            ->default(0)
                            ->dehydrated(),
                    ])
                    ->columns(2),
                    
                Forms\Components\Section::make('Offer Templates')
                    ->schema([
                        Forms\Components\Select::make('intro_template_id')
                            ->relationship('introTemplate', 'template_name')
                            ->searchable()
                            ->preload()
                            ->columnSpan(1),
                            
                        Forms\Components\Select::make('end_template_id')
                            ->relationship('endTemplate', 'template_name')
                            ->searchable()
                            ->preload()
                            ->columnSpan(1),
                    ])
                    ->columns(2)
                    ->collapsible(),
                    
                Forms\Components\Section::make('Notes')
                    ->schema([
                        Forms\Components\Textarea::make('internal_notes')
                            ->rows(4)
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('request_number')
                    ->label('Request #')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                    
                Tables\Columns\TextColumn::make('contact_name')
                    ->label('Contact')
                    ->searchable()
                    ->sortable()
                    ->description(fn ($record) => $record->client_name),
                    
                Tables\Columns\BadgeColumn::make('source')
                    ->colors([
                        'info' => 'prospect',
                        'success' => 'customer',
                        'warning' => 'intake',
                    ]),
                    
                Tables\Columns\TextColumn::make('simple_service_type')
                    ->label('Customer Choice')
                    ->badge()
                    ->formatStateUsing(fn ($state) => 
                        $state ? (config("quotation.simple_service_types.{$state}.icon", '') . ' ' . config("quotation.simple_service_types.{$state}.name", $state)) : '-'
                    )
                    ->color(fn (string $state = null): string => match ($state) {
                        'SEA_RORO' => 'info',
                        'SEA_CONTAINER' => 'primary',
                        'SEA_BREAKBULK' => 'warning',
                        'AIR' => 'success',
                        default => 'gray',
                    })
                    ->searchable()
                    ->toggleable()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('service_type')
                    ->label('Actual Service')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'RORO_EXPORT' => 'info',
                        'RORO_IMPORT' => 'success',
                        'FCL_CONSOL_EXPORT' => 'warning',
                        'FCL_IMPORT' => 'primary',
                        'FCL_EXPORT' => 'primary',
                        'LCL_IMPORT' => 'gray',
                        'LCL_EXPORT' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(function (string $state): string {
                        $serviceTypes = config('quotation.service_types', []);
                        if (isset($serviceTypes[$state]) && is_array($serviceTypes[$state])) {
                            return $serviceTypes[$state]['name'] ?? str_replace('_', ' ', $state);
                        }
                        return str_replace('_', ' ', $state);
                    })
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('route')
                    ->getStateUsing(function (QuotationRequest $record): string {
                        $parts = array_filter([
                            $record->por,
                            $record->pol,
                            $record->pod,
                            $record->fdest,
                        ]);
                        return implode(' → ', $parts) ?: 'N/A';
                    })
                    ->limit(30),
                    
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'gray' => 'draft',
                        'warning' => 'pending',
                        'info' => 'processing',
                        'success' => fn ($state) => in_array($state, ['quoted', 'accepted']),
                        'danger' => fn ($state) => in_array($state, ['rejected', 'expired']),
                    ])
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('total_incl_vat')
                    ->label('Total')
                    ->money('EUR')
                    ->sortable()
                    ->weight('bold'),
                    
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->toggleable(),
                    
                Tables\Columns\TextColumn::make('valid_until')
                    ->date()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->multiple()
                    ->options([
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'quoted' => 'Quoted',
                        'accepted' => 'Accepted',
                        'rejected' => 'Rejected',
                        'expired' => 'Expired',
                    ]),
                    
                Tables\Filters\SelectFilter::make('source')
                    ->options([
                        'prospect' => 'Prospect',
                        'customer' => 'Customer',
                        'intake' => 'Intake',
                    ]),
                    
                Tables\Filters\SelectFilter::make('service_type')
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
                    ->label('Service Type'),
                    
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')
                            ->label('Created From'),
                        Forms\Components\DatePicker::make('created_until')
                            ->label('Created Until'),
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
                    
                Tables\Filters\TernaryFilter::make('has_robaws_offer')
                    ->label('Synced to Robaws')
                    ->nullable()
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('robaws_offer_id'),
                        false: fn ($query) => $query->whereNull('robaws_offer_id'),
                    ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('updateStatus')
                        ->label('Update Status')
                        ->icon('heroicon-o-pencil')
                        ->form([
                            Forms\Components\Select::make('status')
                                ->label('New Status')
                                ->options([
                                    'pending' => 'Pending',
                                    'processing' => 'Processing',
                                    'quoted' => 'Quoted',
                                    'accepted' => 'Accepted',
                                    'rejected' => 'Rejected',
                                ])
                                ->required(),
                        ])
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data) {
                            $records->each->update(['status' => $data['status']]);
                        })
                        ->deselectRecordsAfterCompletion(),
                        
                    Tables\Actions\BulkAction::make('syncSuggestedArticles')
                        ->label('Sync Smart Articles')
                        ->icon('heroicon-o-sparkles')
                        ->color('primary')
                        ->form([
                            Forms\Components\Select::make('min_match_percentage')
                                ->label('Minimum Match Percentage')
                                ->options([
                                    '20' => '20% (Very Broad)',
                                    '30' => '30% (Recommended)',
                                    '50' => '50% (Conservative)',
                                    '70' => '70% (Strict)',
                                ])
                                ->default('30')
                                ->required(),
                                
                            Forms\Components\Select::make('max_articles')
                                ->label('Maximum Articles per Quotation')
                                ->options([
                                    '3' => '3 articles',
                                    '5' => '5 articles',
                                    '10' => '10 articles',
                                ])
                                ->default('5')
                                ->required(),
                                
                            Forms\Components\Toggle::make('auto_attach')
                                ->label('Automatically attach articles')
                                ->helperText('If disabled, articles will be suggested but not attached')
                                ->default(true),
                        ])
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data) {
                            $service = app(\App\Services\SmartArticleSelectionService::class);
                            $attachedCount = 0;
                            
                            foreach ($records as $quotation) {
                                try {
                                    $suggestions = $service->getTopSuggestions(
                                        $quotation, 
                                        (int) $data['max_articles'], 
                                        (int) $data['min_match_percentage']
                                    );
                                    
                                    if ($data['auto_attach']) {
                                        foreach ($suggestions as $suggestion) {
                                            if (!$quotation->articles()->where('robaws_articles_cache.id', $suggestion['article']->id)->exists()) {
                                                $quotation->articles()->attach($suggestion['article']->id);
                                                $attachedCount++;
                                            }
                                        }
                                    }
                                } catch (\Exception $e) {
                                    \Log::error('Failed to sync articles for quotation', [
                                        'quotation_id' => $quotation->id,
                                        'error' => $e->getMessage()
                                    ]);
                                }
                            }
                            
                            if ($data['auto_attach']) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Smart Articles Synced')
                                    ->body("Successfully attached {$attachedCount} articles to {$records->count()} quotations")
                                    ->success()
                                    ->send();
                            } else {
                                \Filament\Notifications\Notification::make()
                                    ->title('Smart Articles Suggested')
                                    ->body("Generated suggestions for {$records->count()} quotations")
                                    ->info()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                        
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
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
            'index' => Pages\ListQuotationRequests::route('/'),
            'create' => Pages\CreateQuotationRequest::route('/create'),
            'view' => Pages\ViewQuotationRequest::route('/{record}'),
            'edit' => Pages\EditQuotationRequest::route('/{record}/edit'),
        ];
    }
    
    public static function create(array $data): Model
    {
        // Remove request_number from data to ensure it's generated by the model
        unset($data['request_number']);
        
        \Log::info('QuotationRequestResource::create - Data before creation', [
            'data_keys' => array_keys($data),
            'request_number_in_data' => $data['request_number'] ?? 'NOT_SET',
        ]);
        
        $record = static::getModel()::create($data);
        
        \Log::info('QuotationRequestResource::create - Record created', [
            'id' => $record->id,
            'request_number' => $record->request_number,
        ]);
        
        return $record;
    }
    
    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', 'pending_review')->count() ?: null;
    }
    
    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * Derive trade direction from service type
     */
    public static function getDirectionFromServiceType(string $serviceType): string
    {
        if (str_contains($serviceType, '_EXPORT')) {
            return 'export';
        }
        if (str_contains($serviceType, '_IMPORT')) {
            return 'import';
        }
        if ($serviceType === 'CROSSTRADE') {
            return 'cross_trade';
        }
        // For ROAD_TRANSPORT, CUSTOMS, PORT_FORWARDING, OTHER
        return 'both';
    }
    
}

