<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RobawsArticleResource\Pages;
use App\Models\RobawsArticleCache;
use App\Models\Port;
use App\Services\Robaws\RobawsArticleProvider;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RobawsArticleResource extends Resource
{
    protected static ?string $model = RobawsArticleCache::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    
    protected static ?string $navigationLabel = 'Robaws Articles';
    
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
                                '45FT HC' => '45FT HC',
                                '20GP' => '20GP',
                                '40GP' => '40GP',
                                '40HQ' => '40HQ',
                                'LM' => 'LM',
                                'kg' => 'kg',
                                'm3' => 'm³',
                                'piece' => 'piece',
                                'shipment' => 'shipment',
                                'container' => 'container',
                                'unit' => 'unit',
                            ])
                            ->searchable()
                            ->columnSpan(1),
                    ])
                    ->columns(3),
                    
                Forms\Components\Section::make('Smart Article Selection Fields')
                    ->description('Fields used for intelligent article filtering and suggestions')
                    ->schema([
                        Forms\Components\Select::make('shipping_carrier_id')
                            ->label('Shipping Line')
                            ->relationship('shippingCarrier', 'name', function ($query) {
                                return $query->where('is_active', true)
                                    ->orderBy('name');
                            })
                            ->getOptionLabelFromRecordUsing(function ($record) {
                                return $record->name . ($record->code ? ' (' . $record->code . ')' : '');
                            })
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->helperText('Select carrier/supplier from Robaws suppliers. This ensures consistent carrier values across the system.')
                            ->afterStateUpdated(function ($set, $state, $get) {
                                // Auto-update shipping_line from carrier name when carrier is selected
                                if ($state) {
                                    $carrier = \App\Models\ShippingCarrier::find($state);
                                    if ($carrier) {
                                        $set('shipping_line', $carrier->name);
                                    }
                                } else {
                                    // Clear shipping_line if carrier is cleared
                                    $set('shipping_line', null);
                                }
                            })
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
                            ->placeholder('Select Transport Mode')
                            ->columnSpan(1),
                            
                        Forms\Components\TextInput::make('service_type')
                            ->label('Service Type')
                            ->maxLength(100)
                            ->placeholder('e.g., IMPORT, EXPORT, OTHER')
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
                            
                        Forms\Components\Select::make('pol_port_id')
                            ->label('POL')
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search) {
                                // Use database-agnostic case-insensitive matching (matches existing codebase pattern)
                                $useIlike = DB::getDriverName() === 'pgsql';
                                
                                return Port::query()
                                    ->where(function($q) use ($search, $useIlike) {
                                        if ($useIlike) {
                                            $q->where('name', 'ILIKE', "%{$search}%")
                                              ->orWhere('code', 'ILIKE', "%{$search}%");
                                        } else {
                                            $q->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($search) . '%'])
                                              ->orWhereRaw('LOWER(code) LIKE ?', ['%' . strtolower($search) . '%']);
                                        }
                                    })
                                    ->where('is_active', true)
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(fn ($port) => [$port->id => "{$port->name} ({$port->code})"]);
                            })
                            ->getOptionLabelUsing(function ($value) {
                                // Use static cache to prevent N+1 queries
                                static $portsCache = [];
                                
                                if (!isset($portsCache[$value])) {
                                    $port = Port::find($value);
                                    $portsCache[$value] = $port ? "{$port->name} ({$port->code})" : null;
                                }
                                
                                return $portsCache[$value];
                            })
                            ->afterStateUpdated(function ($state, callable $set, Forms\Get $get) {
                                if ($state) {
                                    $port = Port::find($state);
                                    if ($port) {
                                        // Get existing code BEFORE setting new one (for validation logging)
                                        $existingCode = $get('pol_code');
                                        
                                        // Optional: Log warning if pol_code was manually changed (for data integrity)
                                        if ($existingCode && $existingCode !== $port->code) {
                                            \Log::warning('Port code mismatch detected during selection', [
                                                'pol_port_id' => $state,
                                                'existing_pol_code' => $existingCode,
                                                'port_code' => $port->code
                                            ]);
                                        }
                                        
                                        // Sync pol_code when port is selected (for traceability)
                                        $set('pol_code', $port->code);
                                    }
                                } else {
                                    $set('pol_code', null);
                                }
                            })
                            ->placeholder('Select POL Port')
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('pol_code')
                            ->label('POL Code')
                            ->disabled()
                            ->dehydrated()
                            ->columnSpan(1),
                            
                        Forms\Components\Select::make('pod_port_id')
                            ->label('POD')
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search) {
                                // Use database-agnostic case-insensitive matching (matches existing codebase pattern)
                                $useIlike = DB::getDriverName() === 'pgsql';
                                
                                return Port::query()
                                    ->where(function($q) use ($search, $useIlike) {
                                        if ($useIlike) {
                                            $q->where('name', 'ILIKE', "%{$search}%")
                                              ->orWhere('code', 'ILIKE', "%{$search}%");
                                        } else {
                                            $q->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($search) . '%'])
                                              ->orWhereRaw('LOWER(code) LIKE ?', ['%' . strtolower($search) . '%']);
                                        }
                                    })
                                    ->where('is_active', true)
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(fn ($port) => [$port->id => "{$port->name} ({$port->code})"]);
                            })
                            ->getOptionLabelUsing(function ($value) {
                                // Use static cache to prevent N+1 queries
                                static $portsCache = [];
                                
                                if (!isset($portsCache[$value])) {
                                    $port = Port::find($value);
                                    $portsCache[$value] = $port ? "{$port->name} ({$port->code})" : null;
                                }
                                
                                return $portsCache[$value];
                            })
                            ->afterStateUpdated(function ($state, callable $set, Forms\Get $get) {
                                if ($state) {
                                    $port = Port::find($state);
                                    if ($port) {
                                        // Get existing code BEFORE setting new one (for validation logging)
                                        $existingCode = $get('pod_code');
                                        
                                        // Optional: Log warning if pod_code was manually changed (for data integrity)
                                        if ($existingCode && $existingCode !== $port->code) {
                                            \Log::warning('Port code mismatch detected during selection', [
                                                'pod_port_id' => $state,
                                                'existing_pod_code' => $existingCode,
                                                'port_code' => $port->code
                                            ]);
                                        }
                                        
                                        // Sync pod_code when port is selected (for traceability)
                                        $set('pod_code', $port->code);
                                    }
                                } else {
                                    $set('pod_code', null);
                                }
                            })
                            ->placeholder('Select POD Port')
                            ->columnSpan(1),
                            
                        Forms\Components\TextInput::make('pod_code')
                            ->label('POD Code')
                            ->disabled()
                            ->dehydrated()
                            ->columnSpan(1),
                            
                        Forms\Components\TextInput::make('pol_terminal')
                            ->label('POL Terminal')
                            ->maxLength(100)
                            ->placeholder('e.g., Terminal 1, Terminal 2')
                            ->columnSpan(1),
                    ])
                    ->columns(3),
                    
                Forms\Components\Section::make('Classification & Metadata')
                    ->schema([
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
                            ->searchable()
                            ->columnSpan(1),
                            
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
                            ->columnSpan(1),
                            
                        Forms\Components\Toggle::make('is_surcharge')
                            ->label('Surcharge Add-on')
                            ->columnSpan(1),
                            
                        Forms\Components\Toggle::make('is_mandatory')
                            ->label('Mandatory')
                            ->columnSpan(1),
                            
                        Forms\Components\Toggle::make('requires_manual_review')
                            ->label('Requires Review')
                            ->columnSpan(1),
                            
                        Forms\Components\Toggle::make('is_parent_item')
                            ->label('Parent Item')
                            ->helperText('Maps to Robaws PARENT ITEM field. Automatically syncs with parent article flag.')
                            ->columnSpan(1),
                            
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->columnSpan(1),
                    ])
                    ->columns(3),
                    
                Forms\Components\Section::make('Dates & Pricing')
                    ->schema([
                        Forms\Components\DatePicker::make('update_date')
                            ->label('Update Date')
                            ->native(false)
                            ->displayFormat('d-m-Y'),
                            
                        Forms\Components\DatePicker::make('validity_date')
                            ->label('Validity Date')
                            ->native(false)
                            ->displayFormat('d-m-Y'),
                            
                        Forms\Components\TextInput::make('cost_price')
                            ->label('Cost Price')
                            ->numeric()
                            ->prefix('€')
                            ->helperText(function ($record) {
                                if ($record && $record->cost_price !== null) {
                                    $breakdown = $record->purchase_price_breakdown ?? [];
                                    $unitType = $breakdown['total_unit_type'] ?? 'LUMPSUM';
                                    return 'Total includes ' . $unitType . ' values only';
                                }
                                return null;
                            }),
                            
                        Forms\Components\Placeholder::make('purchase_price_breakdown_display')
                            ->label('Purchase Price Breakdown')
                            ->content(function ($record) {
                                $breakdown = $record->purchase_price_breakdown ?? [];
                                if (empty($breakdown) || !is_array($breakdown)) {
                                    return new \Illuminate\Support\HtmlString('<p class="text-gray-500">No breakdown data available</p>');
                                }
                                
                                $currency = $breakdown['currency'] ?? 'EUR';
                                $html = '<div class="space-y-3">';
                                
                                // Display Total Purchase Cost with unit type
                                if (isset($breakdown['total']) && $record && $record->cost_price !== null) {
                                    $total = number_format((float) $breakdown['total'], 2, ',', '');
                                    $unitType = $breakdown['total_unit_type'] ?? 'LUMPSUM';
                                    $html .= '<div class="border-b border-gray-200 dark:border-gray-700 pb-3 mb-3">';
                                    $html .= '<div class="font-bold text-lg text-gray-900 dark:text-gray-100">Total Purchase Cost: ' . htmlspecialchars("{$currency} {$total} ({$unitType})") . '</div>';
                                    $html .= '</div>';
                                }
                                
                                // Base Freight
                                $baseFreight = $breakdown['base_freight'] ?? null;
                                if ($baseFreight && isset($baseFreight['amount'])) {
                                    $amount = number_format((float) $baseFreight['amount'], 2, ',', '');
                                    $unit = $baseFreight['unit'] ?? 'LUMPSUM';
                                    $html .= '<div class="border-b border-gray-200 dark:border-gray-700 pb-2">';
                                    $html .= '<div class="font-semibold text-gray-700 dark:text-gray-300 mb-1">Base Freight</div>';
                                    $html .= '<div class="text-sm text-gray-900 dark:text-gray-100">' . htmlspecialchars("{$currency} {$amount} ({$unit})") . '</div>';
                                    $html .= '</div>';
                                }
                                
                                // Surcharges
                                $surcharges = $breakdown['surcharges'] ?? [];
                                if (!empty($surcharges)) {
                                    $html .= '<div class="border-b border-gray-200 dark:border-gray-700 pb-2">';
                                    $html .= '<div class="font-semibold text-gray-700 dark:text-gray-300 mb-2">Surcharges</div>';
                                    $html .= '<div class="space-y-1">';
                                    $labels = [
                                        'baf' => 'BAF',
                                        'ets' => 'ETS',
                                        'port_additional' => 'Port Additional',
                                        'admin_fxe' => 'Admin Fee',
                                        'thc' => 'THC',
                                        'measurement_costs' => 'Measurement Costs',
                                        'congestion_surcharge' => 'Congestion Surcharge',
                                        'iccm' => 'ICCM',
                                    ];
                                    $hasSurcharges = false;
                                    foreach ($surcharges as $key => $surcharge) {
                                        if (isset($surcharge['amount']) && $surcharge['amount'] > 0) {
                                            $hasSurcharges = true;
                                            $amount = number_format((float) $surcharge['amount'], 2, ',', '');
                                            $unit = $surcharge['unit'] ?? 'LUMPSUM';
                                            $label = $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
                                            $html .= '<div class="text-sm flex justify-between"><span class="text-gray-600 dark:text-gray-400">' . htmlspecialchars($label) . ':</span> <span class="text-gray-900 dark:text-gray-100 font-medium">' . htmlspecialchars("{$currency} {$amount} ({$unit})") . '</span></div>';
                                        }
                                    }
                                    if (!$hasSurcharges) {
                                        $html .= '<div class="text-sm text-gray-500">None</div>';
                                    }
                                    $html .= '</div>';
                                    $html .= '</div>';
                                }
                                
                                // Metadata
                                $html .= '<div class="grid grid-cols-2 gap-2 text-sm">';
                                if (!empty($breakdown['carrier_name'])) {
                                    $html .= '<div><span class="text-gray-600 dark:text-gray-400">Carrier:</span> <span class="font-medium text-gray-900 dark:text-gray-100">' . htmlspecialchars($breakdown['carrier_name']) . '</span></div>';
                                }
                                if (!empty($breakdown['last_synced_at'])) {
                                    try {
                                        $date = \Carbon\Carbon::parse($breakdown['last_synced_at'])->format('d-m-Y H:i:s');
                                        $html .= '<div><span class="text-gray-600 dark:text-gray-400">Last Synced:</span> <span class="text-gray-900 dark:text-gray-100">' . htmlspecialchars($date) . '</span></div>';
                                    } catch (\Exception $e) {
                                        $html .= '<div><span class="text-gray-600 dark:text-gray-400">Last Synced:</span> <span class="text-gray-900 dark:text-gray-100">' . htmlspecialchars($breakdown['last_synced_at']) . '</span></div>';
                                    }
                                }
                                if (!empty($breakdown['source'])) {
                                    $html .= '<div><span class="text-gray-600 dark:text-gray-400">Source:</span> <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200">' . htmlspecialchars(ucfirst($breakdown['source'])) . '</span></div>';
                                }
                                if (!empty($breakdown['update_date'])) {
                                    try {
                                        $date = \Carbon\Carbon::parse($breakdown['update_date'])->format('d-m-Y');
                                        $html .= '<div><span class="text-gray-600 dark:text-gray-400">Update Date:</span> <span class="text-gray-900 dark:text-gray-100">' . htmlspecialchars($date) . '</span></div>';
                                    } catch (\Exception $e) {
                                        $html .= '<div><span class="text-gray-600 dark:text-gray-400">Update Date:</span> <span class="text-gray-900 dark:text-gray-100">' . htmlspecialchars($breakdown['update_date']) . '</span></div>';
                                    }
                                }
                                if (!empty($breakdown['validity_date'])) {
                                    try {
                                        $date = \Carbon\Carbon::parse($breakdown['validity_date'])->format('d-m-Y');
                                        $html .= '<div><span class="text-gray-600 dark:text-gray-400">Validity Date:</span> <span class="text-gray-900 dark:text-gray-100">' . htmlspecialchars($date) . '</span></div>';
                                    } catch (\Exception $e) {
                                        $html .= '<div><span class="text-gray-600 dark:text-gray-400">Validity Date:</span> <span class="text-gray-900 dark:text-gray-100">' . htmlspecialchars($breakdown['validity_date']) . '</span></div>';
                                    }
                                }
                                $html .= '</div>';
                                
                                $html .= '</div>';
                                return new \Illuminate\Support\HtmlString($html);
                            })
                            ->visible(fn ($record) => !empty($record?->purchase_price_breakdown) && is_array($record->purchase_price_breakdown))
                            ->columnSpanFull()
                            ->dehydrated(false),
                        Forms\Components\Placeholder::make('max_dimensions_breakdown_display')
                            ->label('Max Dimensions & Weight')
                            ->content(function ($record) {
                                $breakdown = $record->max_dimensions_breakdown ?? [];
                                if (empty($breakdown) || !is_array($breakdown)) {
                                    return new \Illuminate\Support\HtmlString('<p class="text-gray-500">No max dimensions data available</p>');
                                }
                                
                                $html = '<div class="space-y-3">';
                                
                                // Max Dimensions
                                if (isset($breakdown['max_length_cm']) || isset($breakdown['max_width_cm']) || isset($breakdown['max_height_cm'])) {
                                    $dims = [];
                                    if (isset($breakdown['max_length_cm'])) {
                                        $dims[] = 'L: ' . number_format((float) $breakdown['max_length_cm'], 0, ',', '') . 'cm';
                                    }
                                    if (isset($breakdown['max_width_cm'])) {
                                        $dims[] = 'W: ' . number_format((float) $breakdown['max_width_cm'], 0, ',', '') . 'cm';
                                    }
                                    if (isset($breakdown['max_height_cm'])) {
                                        $dims[] = 'H: ' . number_format((float) $breakdown['max_height_cm'], 0, ',', '') . 'cm';
                                    }
                                    if (!empty($dims)) {
                                        $html .= '<div class="border-b border-gray-200 dark:border-gray-700 pb-2">';
                                        $html .= '<div class="font-semibold text-gray-700 dark:text-gray-300 mb-1">Max Dimensions</div>';
                                        $html .= '<div class="text-sm text-gray-900 dark:text-gray-100">' . htmlspecialchars(implode(' × ', $dims)) . '</div>';
                                        $html .= '</div>';
                                    }
                                }
                                
                                // Max Weight
                                if (isset($breakdown['max_weight_kg'])) {
                                    $weight = number_format((float) $breakdown['max_weight_kg'], 0, ',', '');
                                    $html .= '<div class="border-b border-gray-200 dark:border-gray-700 pb-2">';
                                    $html .= '<div class="font-semibold text-gray-700 dark:text-gray-300 mb-1">Max Weight</div>';
                                    $html .= '<div class="text-sm text-gray-900 dark:text-gray-100">' . htmlspecialchars("{$weight}kg") . '</div>';
                                    $html .= '</div>';
                                }
                                
                                // Max CBM
                                if (isset($breakdown['max_cbm'])) {
                                    $cbm = number_format((float) $breakdown['max_cbm'], 2, ',', '');
                                    $html .= '<div class="border-b border-gray-200 dark:border-gray-700 pb-2">';
                                    $html .= '<div class="font-semibold text-gray-700 dark:text-gray-300 mb-1">Max CBM</div>';
                                    $html .= '<div class="text-sm text-gray-900 dark:text-gray-100">' . htmlspecialchars($cbm) . '</div>';
                                    $html .= '</div>';
                                }
                                
                                // Metadata
                                $html .= '<div class="grid grid-cols-2 gap-2 text-sm">';
                                if (!empty($breakdown['carrier_name'])) {
                                    $html .= '<div><span class="text-gray-600 dark:text-gray-400">Carrier:</span> <span class="font-medium text-gray-900 dark:text-gray-100">' . htmlspecialchars($breakdown['carrier_name']) . '</span></div>';
                                }
                                if (!empty($breakdown['port_name'])) {
                                    $html .= '<div><span class="text-gray-600 dark:text-gray-400">Port:</span> <span class="text-gray-900 dark:text-gray-100">' . htmlspecialchars($breakdown['port_name']) . '</span></div>';
                                }
                                if (!empty($breakdown['vehicle_category'])) {
                                    $html .= '<div><span class="text-gray-600 dark:text-gray-400">Vehicle Category:</span> <span class="text-gray-900 dark:text-gray-100">' . htmlspecialchars(ucfirst(str_replace('_', ' ', $breakdown['vehicle_category']))) . '</span></div>';
                                }
                                if (!empty($breakdown['update_date'])) {
                                    try {
                                        $date = \Carbon\Carbon::parse($breakdown['update_date'])->format('d-m-Y');
                                        $html .= '<div><span class="text-gray-600 dark:text-gray-400">Update Date:</span> <span class="text-gray-900 dark:text-gray-100">' . htmlspecialchars($date) . '</span></div>';
                                    } catch (\Exception $e) {
                                        $html .= '<div><span class="text-gray-600 dark:text-gray-400">Update Date:</span> <span class="text-gray-900 dark:text-gray-100">' . htmlspecialchars($breakdown['update_date']) . '</span></div>';
                                    }
                                }
                                if (!empty($breakdown['validity_date'])) {
                                    try {
                                        $date = \Carbon\Carbon::parse($breakdown['validity_date'])->format('d-m-Y');
                                        $html .= '<div><span class="text-gray-600 dark:text-gray-400">Validity Date:</span> <span class="text-gray-900 dark:text-gray-100">' . htmlspecialchars($date) . '</span></div>';
                                    } catch (\Exception $e) {
                                        $html .= '<div><span class="text-gray-600 dark:text-gray-400">Validity Date:</span> <span class="text-gray-900 dark:text-gray-100">' . htmlspecialchars($breakdown['validity_date']) . '</span></div>';
                                    }
                                }
                                $html .= '</div>';
                                
                                $html .= '</div>';
                                return new \Illuminate\Support\HtmlString($html);
                            })
                            ->visible(fn ($record) => !empty($record?->max_dimensions_breakdown) && is_array($record->max_dimensions_breakdown))
                            ->columnSpanFull()
                            ->dehydrated(false),
                    ])
                    ->columns(2),
                    
                Forms\Components\Section::make('Additional Information')
                    ->schema([
                        Forms\Components\Textarea::make('sales_name')
                            ->label('Salesname')
                            ->rows(6)
                            ->columnSpanFull(),
                            
                        Forms\Components\TextInput::make('mandatory_condition')
                            ->label('Mandatory Condition')
                            ->maxLength(500)
                            ->columnSpanFull(),
                            
                        Forms\Components\Textarea::make('notes')
                            ->rows(2)
                            ->columnSpanFull(),
                            
                        Forms\Components\Textarea::make('article_info')
                            ->label('Article Info (Raw)')
                            ->rows(3)
                            ->disabled()
                            ->columnSpanFull(),
                    ])
                    ->collapsed(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        $tableResult = $table
            ->modifyQueryUsing(function (Builder $query) {
                return $query;
            })
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
                    ->tooltip(function ($state, $record = null): ?string {
                        try {
                            if (is_string($state) && strlen($state) > 50) {
                                return $state;
                            }
                            return null;
                        } catch (\Exception $e) {
                            return null;
                        }
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
                    
                Tables\Columns\IconColumn::make('is_parent_item')
                    ->boolean()
                    ->label('Parent')
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->tooltip('Parent item status from Robaws API')
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
                    
                // FIXED: Access database columns directly instead of using accessor property
                Tables\Columns\TextColumn::make('effective_validity_date')
                    ->label('Valid Until')
                    ->getStateUsing(function ($record) {
                        try {
                            // Access columns directly instead of accessor to avoid issues
                            return $record->validity_date_override ?? $record->validity_date ?? null;
                        } catch (\Exception $e) {
                            return null;
                        }
                    })
                    ->date('M d, Y')
                    ->placeholder('Not set')
                    ->color(function ($state) {
                        try {
                            return $state && $state >= now() ? 'success' : 'gray';
                        } catch (\Exception $e) {
                            return 'gray';
                        }
                    })
                    ->toggleable(),
                    
                // FIXED: Simplified callbacks with better error handling
                Tables\Columns\TextColumn::make('applicable_services')
                    ->badge()
                    ->formatStateUsing(function ($state, $record = null) {
                        try {
                            if ($state === null || $state === '') {
                                if ($record && $record->service_type) {
                                    return str_replace('_', ' ', $record->service_type);
                                }
                                return 'Not specified';
                            }
                            
                            if (is_string($state)) {
                                $decoded = json_decode($state, true);
                                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                    $services = $decoded;
                                } else {
                                    return str_replace('_', ' ', $state);
                                }
                            } else if (is_array($state)) {
                                $services = $state;
                            } else {
                                if ($record && $record->service_type) {
                                    return str_replace('_', ' ', $record->service_type);
                                }
                                return 'Not specified';
                            }
                            
                            if (isset($services) && is_array($services) && count($services) > 0) {
                                return implode(', ', array_map(fn($s) => str_replace('_', ' ', $s), $services));
                            }
                            
                            if ($record && $record->service_type) {
                                return str_replace('_', ' ', $record->service_type);
                            }
                            
                            return 'Not specified';
                        } catch (\Exception $e) {
                            return 'Error';
                        }
                    })
                    ->color('success')
                    ->limit(50)
                    ->tooltip(function ($state, $record = null) {
                        if (!$record) {
                            return 'Service types this article applies to';
                        }
                        try {
                            $rawServices = method_exists($record, 'getRawOriginal') ? $record->getRawOriginal('applicable_services') : $record->applicable_services;
                            if (is_string($rawServices)) {
                                $decoded = json_decode($rawServices, true);
                                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                    $rawServices = $decoded;
                                }
                            }
                            if (is_array($rawServices) && count($rawServices) > 2) {
                                return implode(', ', array_map(fn($s) => str_replace('_', ' ', $s), $rawServices));
                            }
                            if ($record->pol && $record->pod) {
                                return 'Direction-aware services based on POL/POD routing';
                            }
                            return 'Service types this article applies to';
                        } catch (\Exception $e) {
                            return 'Service types this article applies to';
                        }
                    })
                    ->toggleable(),
                    
                // children_count column disabled - was causing issues with withCount/relationships
                    
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

                Tables\Filters\SelectFilter::make('pol_port_id')
                    ->label('POL Port')
                    ->options(function () {
                        try {
                            $ports = Port::where('is_active', true)
                                ->orderBy('name')
                                ->get();
                            
                            return $ports->mapWithKeys(fn ($port) => [
                                $port->id => "{$port->name} ({$port->code})"
                            ]);
                        } catch (\Exception $e) {
                            return [];
                        }
                    }),
                        
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

                Tables\Filters\SelectFilter::make('pod_port_id')
                    ->label('POD Port')
                    ->options(function () {
                        try {
                            $ports = Port::where('is_active', true)
                                ->orderBy('name')
                                ->get();
                            
                            return $ports->mapWithKeys(fn ($port) => [
                                $port->id => "{$port->name} ({$port->code})"
                            ]);
                        } catch (\Exception $e) {
                            return [];
                        }
                    }),
                    
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
                    
                Tables\Actions\Action::make('push_to_robaws')
                    ->label('Push to Robaws')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Push article changes to Robaws?')
                    ->modalDescription(function (RobawsArticleCache $record) {
                        $pushService = app(\App\Services\Robaws\RobawsArticlePushService::class);
                        $changedFields = $pushService->getChangedFieldsSinceLastPush($record);
                        
                        if (empty($changedFields)) {
                            return 'No changes detected since last push. All current field values will be pushed.';
                        }
                        
                        $pushableFields = $pushService->getPushableFields();
                        $fieldLabels = array_column($pushableFields, 'label', 'key');
                        $changedLabels = array_map(fn($key) => $fieldLabels[$key] ?? $key, $changedFields);
                        
                        return 'The following fields have changed: ' . implode(', ', $changedLabels);
                    })
                    ->form(function (RobawsArticleCache $record) {
                        $pushService = app(\App\Services\Robaws\RobawsArticlePushService::class);
                        $pushableFields = $pushService->getPushableFields();
                        $changedFields = $pushService->getChangedFieldsSinceLastPush($record);
                        
                        $options = [];
                        $descriptions = [];
                        foreach ($pushableFields as $field) {
                            $options[$field['key']] = $field['label'];
                            $descriptions[$field['key']] = $field['robaws_field'] . ' (' . $field['group'] . ')';
                        }
                        
                        return [
                            Forms\Components\CheckboxList::make('fields_to_push')
                                ->label('Fields to Push')
                                ->options($options)
                                ->default($changedFields ?: array_keys($options))
                                ->required()
                                ->descriptions($descriptions)
                                ->columns(2)
                                ->helperText('Select which fields to push to Robaws. Changed fields are pre-selected.'),
                        ];
                    })
                    ->action(function (RobawsArticleCache $record, array $data) {
                        $pushService = app(\App\Services\Robaws\RobawsArticlePushService::class);
                        $result = $pushService->pushArticleToRobaws(
                            $record,
                            $data['fields_to_push'],
                            0, // sleepMs
                            true, // retryOnFailure
                            2 // maxRetries
                        );
                        
                        if ($result['success']) {
                            $fieldsPushed = !empty($result['fields_pushed']) 
                                ? implode(', ', $result['fields_pushed']) 
                                : 'selected fields';
                            Notification::make()
                                ->title('Article pushed successfully')
                                ->body("Pushed {$fieldsPushed} for: {$record->article_name}")
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Push failed')
                                ->body($result['error'])
                                ->danger()
                                ->send();
                        }
                    })
                    ->visible(fn (RobawsArticleCache $record) => !empty($record->robaws_article_id)),
                    
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
                    Tables\Actions\BulkAction::make('push_to_robaws')
                        ->label('Push to Robaws')
                        ->icon('heroicon-o-arrow-up-tray')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Push selected articles to Robaws?')
                        ->modalDescription(function (\Illuminate\Support\Collection $records) {
                            $validCount = $records->filter(fn($r) => !empty($r->robaws_article_id))->count();
                            $invalidCount = $records->count() - $validCount;
                            
                            $message = "Pushing {$validCount} article(s) to Robaws.";
                            if ($invalidCount > 0) {
                                $message .= " {$invalidCount} article(s) without Robaws ID will be skipped.";
                            }
                            
                            return $message;
                        })
                        ->form(function (\Illuminate\Support\Collection $records) {
                            $pushService = app(\App\Services\Robaws\RobawsArticlePushService::class);
                            $pushableFields = $pushService->getPushableFields();
                            
                            $options = [];
                            $descriptions = [];
                            foreach ($pushableFields as $field) {
                                $options[$field['key']] = $field['label'];
                                $descriptions[$field['key']] = $field['robaws_field'] . ' (' . $field['group'] . ')';
                            }
                            
                            return [
                                Forms\Components\CheckboxList::make('fields_to_push')
                                    ->label('Fields to Push')
                                    ->options($options)
                                    ->default(array_keys($options))
                                    ->required()
                                    ->descriptions($descriptions)
                                    ->columns(2)
                                    ->helperText('Select which fields to push to Robaws for all selected articles.'),
                            ];
                        })
                        ->action(function (\Illuminate\Support\Collection $records, array $data) {
                            $pushService = app(\App\Services\Robaws\RobawsArticlePushService::class);
                            $validRecords = $records->filter(fn($r) => !empty($r->robaws_article_id));
                            
                            $result = $pushService->pushBulkArticles(
                                $validRecords,
                                $data['fields_to_push'],
                                100, // sleepMs (100ms = 10 req/sec, safe rate limiting)
                                true // retryOnFailure
                            );
                            
                            if ($result['success'] > 0) {
                                $fieldsPushed = !empty($result['fields_pushed']) 
                                    ? implode(', ', $result['fields_pushed']) 
                                    : 'selected fields';
                                Notification::make()
                                    ->title('Articles pushed successfully')
                                    ->body("Pushed {$result['success']} article(s). Fields: {$fieldsPushed}")
                                    ->success()
                                    ->duration(8000)
                                    ->send();
                            }
                            
                            if ($result['failed'] > 0) {
                                Notification::make()
                                    ->title('Some pushes failed')
                                    ->body("{$result['failed']} article(s) failed to push. Check logs for details.")
                                    ->warning()
                                    ->duration(10000)
                                    ->send();
                            }
                        })
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

                            foreach (['is_surcharge', 'is_mandatory', 'requires_manual_review', 'is_parent_item'] as $booleanField) {
                                if (array_key_exists($booleanField, $updates)) {
                                    $updates[$booleanField] = $updates[$booleanField] === '1';
                                }
                            }

                            foreach (['update_date', 'validity_date'] as $dateField) {
                                if (array_key_exists($dateField, $updates) && $updates[$dateField]) {
                                    $updates[$dateField] = Carbon::parse($updates[$dateField])->format('Y-m-d');
                                }
                            }

                            foreach (['unit_price', 'cost_price'] as $numericField) {
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
            ->defaultSort('id', 'desc');
        
        return $tableResult;
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