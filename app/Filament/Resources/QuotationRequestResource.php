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
                            
                        Forms\Components\Select::make('customer_type')
                            ->options(config('quotation.customer_types', []))
                            ->default('GENERAL')
                            ->columnSpan(1),
                            
                        Forms\Components\Select::make('customer_role')
                            ->label('Customer Role')
                            ->options(config('quotation.customer_roles', []))
                            ->default('CONSIGNEE')
                            ->required()
                            ->searchable()
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
                        Forms\Components\Select::make('service_type')
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
                            ->columnSpan(2),
                            
                        Forms\Components\TextInput::make('por')
                            ->label('Place of Receipt (POR)')
                            ->placeholder('Optional - e.g., Brussels, Paris')
                            ->maxLength(100)
                            ->columnSpan(1),
                            
                        Forms\Components\Select::make('pol')
                            ->label('Port of Loading (POL)')
                            ->options(function () {
                                return \App\Models\Port::europeanOrigins()
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(function ($port) {
                                        return [$port->name => $port->name . ' (' . $port->code . '), ' . $port->country];
                                    });
                            })
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Forms\Set $set) => $set('selected_schedule_id', null))
                            ->columnSpan(1),
                            
                        Forms\Components\Select::make('pod')
                            ->label('Port of Discharge (POD)')
                            ->options(function () {
                                return \App\Models\Port::withActivePodSchedules()
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(function ($port) {
                                        return [$port->name => $port->name . ' (' . $port->code . '), ' . $port->country];
                                    });
                            })
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Forms\Set $set) => $set('selected_schedule_id', null))
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
                    
                Forms\Components\Section::make('Select Sailing')
                    ->description('Choose a specific sailing to filter carrier-specific articles')
                    ->schema([
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
                            ->customerType(fn ($get) => $get('customer_type'))
                            ->carrierCode(fn ($get) => $get('preferred_carrier'))
                            ->columnSpanFull(),
                            
                        PriceCalculator::make('pricing_summary')
                            ->customerRole(fn ($get) => $get('customer_role'))
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
                    
                Tables\Columns\TextColumn::make('service_type')
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

