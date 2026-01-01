<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CarrierRuleResource\Pages;
use App\Models\ShippingCarrier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CarrierRuleResource extends Resource
{
    protected static ?string $model = ShippingCarrier::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Carrier Rules';

    protected static ?string $modelLabel = 'Carrier Rules';

    protected static ?string $pluralModelLabel = 'Carrier Rules';

    protected static ?string $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('carrier_rules_tabs')
                    ->tabs([
                        // Tab 1: Overview
                        Forms\Components\Tabs\Tab::make('overview')
                            ->label('Overview')
                            ->icon('heroicon-o-information-circle')
                            ->schema([
                                Forms\Components\Section::make('Carrier Information')
                                    ->schema([
                                        Forms\Components\TextInput::make('name')
                                            ->label('Carrier Name')
                                            ->disabled()
                                            ->dehydrated(false),
                                        
                                        Forms\Components\TextInput::make('code')
                                            ->label('Carrier Code')
                                            ->disabled()
                                            ->dehydrated(false),
                                    ])
                                    ->columns(2)
                                    ->collapsible(),

                                Forms\Components\Section::make('Internal Comments')
                                    ->schema([
                                        Forms\Components\Textarea::make('internal_comments')
                                            ->label('Internal Comments')
                                            ->rows(6)
                                            ->placeholder('Add internal notes, reminders, or operational guidelines for this carrier...')
                                            ->helperText('These comments are only visible to internal staff and are not shown to customers.')
                                            ->columnSpanFull(),
                                    ])
                                    ->collapsible(),
                            ]),

                        // Tab 2: Category Groups
                        Forms\Components\Tabs\Tab::make('category_groups')
                            ->label('Category Groups')
                            ->icon('heroicon-o-folder')
                            ->schema([
                                Forms\Components\Repeater::make('categoryGroups')
                                    ->relationship('categoryGroups')
                                    ->addable()
                                    ->deletable()
                                    ->reorderable('sort_order')
                                    ->cloneable()
                                    ->collapsible()
                                    ->collapsed()
                                    ->schema([
                                        Forms\Components\TextInput::make('code')
                                            ->label('Group Code')
                                            ->required()
                                            ->maxLength(50)
                                            ->placeholder('e.g., CARS, LM_CARGO')
                                            ->helperText('Unique code for this group (uppercase, underscores)')
                                            ->columnSpan(1)
                                            ->disabled(fn ($context) => $context === 'view'),

                                        Forms\Components\TextInput::make('display_name')
                                            ->label('Display Name')
                                            ->required()
                                            ->maxLength(255)
                                            ->placeholder('e.g., Cars, LM Cargo')
                                            ->columnSpan(1)
                                            ->disabled(fn ($context) => $context === 'view'),

                                        Forms\Components\TagsInput::make('aliases')
                                            ->label('Aliases')
                                            ->placeholder('Add alias and press Enter')
                                            ->helperText('Alternative names for this group (e.g., "LM", "High & Heavy")')
                                            ->columnSpanFull()
                                            ->disabled(fn ($context) => $context === 'view'),

                                        Forms\Components\TextInput::make('priority')
                                            ->label('Priority')
                                            ->required()
                                            ->numeric()
                                            ->default(0)
                                            ->helperText('Higher priority = checked first')
                                            ->columnSpan(1)
                                            ->disabled(fn ($context) => $context === 'view'),

                                        Forms\Components\DatePicker::make('effective_from')
                                            ->label('Effective From')
                                            ->helperText('Rule becomes active on this date')
                                            ->columnSpan(1)
                                            ->disabled(fn ($context) => $context === 'view'),

                                        Forms\Components\DatePicker::make('effective_to')
                                            ->label('Effective To')
                                            ->helperText('Rule expires on this date (optional)')
                                            ->columnSpan(1)
                                            ->disabled(fn ($context) => $context === 'view'),

                                        Forms\Components\Toggle::make('is_active')
                                            ->label('Active')
                                            ->default(true)
                                            ->columnSpan(1)
                                            ->disabled(fn ($context) => $context === 'view'),

                                        Forms\Components\CheckboxList::make('member_categories')
                                            ->label('Vehicle Categories')
                                            ->options(function () {
                                                $categories = config('quotation.commodity_types.vehicles.categories', []);
                                                return $categories;
                                            })
                                            ->columns(2)
                                            ->searchable()
                                            ->bulkToggleable()
                                            ->gridDirection('row')
                                            ->helperText('Select one or more vehicle categories that belong to this group')
                                            ->columnSpanFull()
                                            ->disabled(fn ($context) => $context === 'view')
                                            ->dehydrated(true) // Keep in form data for processing
                                            ->afterStateHydrated(function ($component, $state, $record) {
                                                // If state is empty/null and we have a record, load from members relationship
                                                if (empty($state) && $record && isset($record->id)) {
                                                    $memberCategories = $record->members()->pluck('vehicle_category')->toArray();
                                                    $component->state($memberCategories);
                                                }
                                            }),
                                    ])
                                    ->label('Category Groups')
                                    ->defaultItems(0)
                                    ->itemLabel(fn (array $state): ?string => $state['display_name'] ?? 'New Group')
                                    ->columnSpanFull(),
                            ]),

                        // Tab 3: Port Groups
                        Forms\Components\Tabs\Tab::make('port_groups')
                            ->label('Port Groups')
                            ->icon('heroicon-o-map-pin')
                            ->schema([
                                Forms\Components\Repeater::make('portGroups')
                                    ->relationship('portGroups')
                                    ->addable()
                                    ->deletable()
                                    ->reorderable('sort_order')
                                    ->cloneable()
                                    ->collapsible()
                                    ->collapsed()
                                    ->schema([
                                        Forms\Components\TextInput::make('code')
                                            ->label('Group Code')
                                            ->required()
                                            ->maxLength(50)
                                            ->placeholder('e.g., WAF, MED')
                                            ->helperText('Unique code for this port group (uppercase, underscores)')
                                            ->columnSpan(1)
                                            ->disabled(fn ($context) => $context === 'view'),

                                        Forms\Components\TextInput::make('display_name')
                                            ->label('Display Name')
                                            ->required()
                                            ->maxLength(255)
                                            ->placeholder('e.g., West Africa, Mediterranean')
                                            ->columnSpan(1)
                                            ->disabled(fn ($context) => $context === 'view'),

                                        Forms\Components\TagsInput::make('aliases')
                                            ->label('Aliases')
                                            ->placeholder('Add alias and press Enter')
                                            ->helperText('Alternative names for this port group')
                                            ->columnSpanFull()
                                            ->disabled(fn ($context) => $context === 'view'),

                                        Forms\Components\TextInput::make('priority')
                                            ->label('Priority')
                                            ->required()
                                            ->numeric()
                                            ->default(0)
                                            ->helperText('Higher priority = checked first')
                                            ->columnSpan(1)
                                            ->disabled(fn ($context) => $context === 'view'),

                                        Forms\Components\DatePicker::make('effective_from')
                                            ->label('Effective From')
                                            ->helperText('Rule becomes active on this date')
                                            ->columnSpan(1)
                                            ->disabled(fn ($context) => $context === 'view'),

                                        Forms\Components\DatePicker::make('effective_to')
                                            ->label('Effective To')
                                            ->helperText('Rule expires on this date (optional)')
                                            ->columnSpan(1)
                                            ->disabled(fn ($context) => $context === 'view'),

                                        Forms\Components\Toggle::make('is_active')
                                            ->label('Active')
                                            ->default(true)
                                            ->columnSpan(1)
                                            ->disabled(fn ($context) => $context === 'view'),

                                        Forms\Components\Select::make('member_ports')
                                            ->label('Ports')
                                            ->options(function () {
                                                return \App\Models\Port::orderBy('name')
                                                    ->get()
                                                    ->mapWithKeys(function ($port) {
                                                        return [$port->id => $port->formatFull()];
                                                    });
                                            })
                                            ->searchable()
                                            ->multiple()
                                            ->preload()
                                            ->helperText('Select one or more ports that belong to this group')
                                            ->columnSpanFull()
                                            ->disabled(fn ($context) => $context === 'view')
                                            ->dehydrated(true) // Keep in form data for processing
                                            ->afterStateHydrated(function ($component, $state, $record) {
                                                // If state is empty/null and we have a record, load from members relationship
                                                if (empty($state) && $record && isset($record->id)) {
                                                    $memberPorts = $record->members()->pluck('port_id')->toArray();
                                                    $component->state($memberPorts);
                                                }
                                            }),
                                    ])
                                    ->label('Port Groups')
                                    ->defaultItems(0)
                                    ->itemLabel(fn (array $state): ?string => $state['display_name'] ?? 'New Port Group')
                                    ->columnSpanFull(),
                            ]),

                        // Tab 4: Acceptance Rules
                        Forms\Components\Tabs\Tab::make('acceptance')
                            ->label('Acceptance Rules')
                            ->icon('heroicon-o-check-circle')
                            ->schema([
                                Forms\Components\Repeater::make('acceptanceRules')
                                    ->relationship('acceptanceRules')
                                    ->reorderable('sort_order')
                                    ->collapsible()
                                    ->collapsed()
                                    ->mutateRelationshipDataBeforeSaveUsing(function (array $data): array {
                                        // Normalize: if category_group_ids set => vehicle_categories = null
                                        if (!empty($data['category_group_ids'])) {
                                            $data['vehicle_categories'] = null;
                                            $data['category_group_id'] = null; // Clear legacy field
                                        }
                                        // Normalize: if vehicle_categories non-empty => category_group_ids = null
                                        if (!empty($data['vehicle_categories'])) {
                                            $data['category_group_ids'] = null;
                                            $data['category_group_id'] = null; // Clear legacy field
                                        }
                                        return $data;
                                    })
                                    ->schema([
                                        Forms\Components\TextInput::make('name')
                                            ->label('Rule Name')
                                            ->maxLength(255)
                                            ->helperText('Optional: Give this rule a descriptive name')
                                            ->afterStateHydrated(function ($component, $state, $record, Forms\Get $get) {
                                                // If name is empty, generate a default name based on rule criteria
                                                if (empty($state)) {
                                                    $name = null;
                                                    
                                                    // Check form state first (for new items or when editing)
                                                    $vehicleCategory = $get('vehicle_category') ?? ($record->vehicle_category ?? null);
                                                    $categoryGroupId = $get('category_group_id') ?? ($record->category_group_id ?? null);
                                                    
                                                    if (!empty($vehicleCategory)) {
                                                        $name = ucfirst($vehicleCategory) . ' Rule';
                                                    } elseif (!empty($categoryGroupId)) {
                                                        $group = \App\Models\CarrierCategoryGroup::find($categoryGroupId);
                                                        $name = ($group ? $group->display_name : 'Group #' . $categoryGroupId) . ' Rule';
                                                    } else {
                                                        $name = 'Global Rule';
                                                    }
                                                    
                                                    if ($name) {
                                                        $component->state($name);
                                                    }
                                                }
                                            })
                                            ->reactive()
                                            ->columnSpanFull(),

                                        Forms\Components\Select::make('port_ids')
                                            ->label('Ports (POD)')
                                            ->options(function () {
                                                return \App\Models\Port::orderBy('name')
                                                    ->get()
                                                    ->mapWithKeys(function ($port) {
                                                        return [$port->id => $port->formatFull()];
                                                    });
                                            })
                                            ->searchable()
                                            ->multiple()
                                            ->preload()
                                            ->helperText('Select one or more ports. Leave empty for global rule.')
                                            ->columnSpan(1),

                                        Forms\Components\Select::make('port_group_ids')
                                            ->label('Port Groups')
                                            ->options(function (Forms\Get $get, $livewire) {
                                                try {
                                                    $carrierId = null;
                                                    if (isset($livewire) && method_exists($livewire, 'getRecord')) {
                                                        $record = $livewire->getRecord();
                                                        $carrierId = $record ? $record->id : null;
                                                    }
                                                    
                                                    if (!$carrierId) {
                                                        return [];
                                                    }
                                                    
                                                    return \App\Models\CarrierPortGroup::where('carrier_id', $carrierId)
                                                        ->active()
                                                        ->orderBy('sort_order')
                                                        ->pluck('display_name', 'id')
                                                        ->toArray();
                                                } catch (\Throwable $e) {
                                                    return [];
                                                }
                                            })
                                            ->getOptionLabelUsing(function ($value) {
                                                if (!$value) {
                                                    return null;
                                                }
                                                try {
                                                    $group = \App\Models\CarrierPortGroup::find($value);
                                                    return $group ? $group->display_name : null;
                                                } catch (\Throwable $e) {
                                                    return null;
                                                }
                                            })
                                            ->multiple()
                                            ->preload()
                                            ->helperText('Select port groups (e.g., WAF, MED). If both Ports and Port Groups are set, rule matches if either matches.')
                                            ->columnSpan(1),

                                        Forms\Components\Hidden::make('port_id')
                                            ->dehydrated(false),

                                        Forms\Components\Select::make('vehicle_categories')
                                            ->label('Vehicle Categories')
                                            ->options(function () {
                                                return config('quotation.commodity_types.vehicles.categories', []);
                                            })
                                            ->searchable()
                                            ->multiple()
                                            ->preload()
                                            ->helperText('Select one or more vehicle categories. Leave empty for all categories. Note: If Category Group is selected, this field should be empty.')
                                            ->columnSpan(1)
                                            ->disabled(fn ($get) => !empty($get('category_group_ids'))),

                                        Forms\Components\Hidden::make('vehicle_category')
                                            ->dehydrated(false),

                                        Forms\Components\Select::make('category_group_ids')
                                            ->label('Category Groups')
                                            ->options(function (Forms\Get $get, $livewire) {
                                                try {
                                                    $carrierId = null;
                                                    
                                                    // Get carrier ID from livewire's record
                                                    if (isset($livewire) && method_exists($livewire, 'getRecord')) {
                                                        $record = $livewire->getRecord();
                                                        $carrierId = $record ? $record->id : null;
                                                    }
                                                    
                                                    if (!$carrierId) {
                                                        return [];
                                                    }
                                                    
                                                    return \App\Models\CarrierCategoryGroup::where('carrier_id', $carrierId)
                                                        ->active()
                                                        ->orderBy('sort_order')
                                                        ->pluck('display_name', 'id')
                                                        ->toArray();
                                                } catch (\Throwable $e) {
                                                    \Log::error('Category group options error: ' . $e->getMessage());
                                                    return [];
                                                }
                                            })
                                            ->getOptionLabelUsing(function ($value) {
                                                if (!$value) {
                                                    return null;
                                                }
                                                try {
                                                    $group = \App\Models\CarrierCategoryGroup::find($value);
                                                    return $group ? $group->display_name : null;
                                                } catch (\Throwable $e) {
                                                    return null;
                                                }
                                            })
                                            ->multiple()
                                            ->preload()
                                            ->helperText('OR use one or more category groups. Note: If Vehicle Categories are selected, this field should be empty.')
                                            ->columnSpan(1)
                                            ->disabled(fn ($get) => !empty($get('vehicle_categories')))
                                            ->reactive(),

                                        Forms\Components\Select::make('vessel_names')
                                            ->label('Vessel Names')
                                            ->options(function ($get) {
                                                try {
                                                    $carrierId = $get('../../../../id');
                                                    
                                                    if (!$carrierId) {
                                                        return [];
                                                    }
                                                    
                                                    return \App\Models\ShippingSchedule::where('carrier_id', $carrierId)
                                                        ->whereNotNull('vessel_name')
                                                        ->distinct()
                                                        ->orderBy('vessel_name')
                                                        ->pluck('vessel_name', 'vessel_name');
                                                } catch (\Throwable $e) {
                                                    return [];
                                                }
                                            })
                                            ->multiple()
                                            ->helperText('Select one or more vessel names. Leave empty for all vessels.')
                                            ->columnSpan(1),

                                        Forms\Components\Hidden::make('vessel_name')
                                            ->dehydrated(false),

                                        Forms\Components\CheckboxList::make('vessel_classes')
                                            ->label('Vessel Classes')
                                            ->options(function ($get) {
                                                try {
                                                    $carrierId = $get('../../../../id');
                                                    
                                                    if (!$carrierId) {
                                                        return [];
                                                    }
                                                    
                                                    return \App\Models\ShippingSchedule::where('carrier_id', $carrierId)
                                                        ->whereNotNull('vessel_class')
                                                        ->distinct()
                                                        ->orderBy('vessel_class')
                                                        ->pluck('vessel_class', 'vessel_class');
                                                } catch (\Throwable $e) {
                                                    return [];
                                                }
                                            })
                                            ->searchable()
                                            ->bulkToggleable()
                                            ->gridDirection('row')
                                            ->helperText('Select one or more vessel classes. Leave empty for all vessel classes.')
                                            ->columnSpan(1)
                                            ->dehydrated(true),

                                        Forms\Components\Hidden::make('vessel_class')
                                            ->dehydrated(false),

                                        Forms\Components\Section::make('Dimension Limits')
                                            ->schema([
                                                Forms\Components\TextInput::make('min_length_cm')
                                                    ->label('Min Length (cm)')
                                                    ->numeric()
                                                    ->step(0.1)
                                                    ->columnSpan(1),

                                                Forms\Components\TextInput::make('min_width_cm')
                                                    ->label('Min Width (cm)')
                                                    ->numeric()
                                                    ->step(0.1)
                                                    ->columnSpan(1),

                                                Forms\Components\TextInput::make('min_height_cm')
                                                    ->label('Min Height (cm)')
                                                    ->numeric()
                                                    ->step(0.1)
                                                    ->columnSpan(1),

                                                Forms\Components\TextInput::make('min_cbm')
                                                    ->label('Min CBM')
                                                    ->numeric()
                                                    ->step(0.1)
                                                    ->columnSpan(1),

                                                Forms\Components\TextInput::make('min_weight_kg')
                                                    ->label('Min Weight (kg)')
                                                    ->numeric()
                                                    ->step(0.1)
                                                    ->columnSpan(1),

                                                Forms\Components\TextInput::make('max_length_cm')
                                                    ->label('Max Length (cm)')
                                                    ->numeric()
                                                    ->step(0.1)
                                                    ->columnSpan(1)
                                                    ->rules([
                                                        function (Forms\Get $get) {
                                                            $min = $get('min_length_cm');
                                                            $max = $get('max_length_cm');
                                                            if ($min !== null && $max !== null && $min > $max) {
                                                                return 'Max Length must be greater than or equal to Min Length.';
                                                            }
                                                            return null;
                                                        },
                                                    ]),

                                                Forms\Components\TextInput::make('max_width_cm')
                                                    ->label('Max Width (cm)')
                                                    ->numeric()
                                                    ->step(0.1)
                                                    ->columnSpan(1)
                                                    ->rules([
                                                        function (Forms\Get $get) {
                                                            $min = $get('min_width_cm');
                                                            $max = $get('max_width_cm');
                                                            if ($min !== null && $max !== null && $min > $max) {
                                                                return 'Max Width must be greater than or equal to Min Width.';
                                                            }
                                                            return null;
                                                        },
                                                    ]),

                                                Forms\Components\TextInput::make('max_height_cm')
                                                    ->label('Max Height (cm)')
                                                    ->numeric()
                                                    ->step(0.1)
                                                    ->columnSpan(1)
                                                    ->rules([
                                                        function (Forms\Get $get) {
                                                            $min = $get('min_height_cm');
                                                            $max = $get('max_height_cm');
                                                            if ($min !== null && $max !== null && $min > $max) {
                                                                return 'Max Height must be greater than or equal to Min Height.';
                                                            }
                                                            return null;
                                                        },
                                                    ]),

                                                Forms\Components\TextInput::make('max_cbm')
                                                    ->label('Max CBM')
                                                    ->numeric()
                                                    ->step(0.1)
                                                    ->columnSpan(1)
                                                    ->rules([
                                                        function (Forms\Get $get) {
                                                            $min = $get('min_cbm');
                                                            $max = $get('max_cbm');
                                                            if ($min !== null && $max !== null && $min > $max) {
                                                                return 'Max CBM must be greater than or equal to Min CBM.';
                                                            }
                                                            return null;
                                                        },
                                                    ]),

                                                Forms\Components\TextInput::make('max_weight_kg')
                                                    ->label('Max Weight (kg)')
                                                    ->numeric()
                                                    ->step(0.1)
                                                    ->columnSpan(1)
                                                    ->rules([
                                                        function (Forms\Get $get) {
                                                            $min = $get('min_weight_kg');
                                                            $max = $get('max_weight_kg');
                                                            if ($min !== null && $max !== null && $min > $max) {
                                                                return 'Max Weight must be greater than or equal to Min Weight.';
                                                            }
                                                            return null;
                                                        },
                                                    ]),

                                                Forms\Components\Toggle::make('min_is_hard')
                                                    ->label('Min limits are hard (reject if below)')
                                                    ->helperText('If enabled, cargo below minimums will be rejected. If disabled, warnings will be shown but cargo allowed.')
                                                    ->default(false)
                                                    ->columnSpanFull(),
                                            ])
                                            ->columns(5)
                                            ->collapsible()
                                            ->columnSpanFull(),

                                        Forms\Components\Section::make('Operational Requirements')
                                            ->schema([
                                                Forms\Components\Toggle::make('must_be_empty')
                                                    ->label('Must Be Empty')
                                                    ->columnSpan(1),

                                                Forms\Components\Toggle::make('must_be_self_propelled')
                                                    ->label('Ground Unit Must Be Self-Propelled')
                                                    ->default(true)
                                                    ->helperText('The vehicle itself (ground unit) must be self-propelled. Trailers are exempt (not self-propelled). Loaded units are not checked.')
                                                    ->columnSpan(1),

                                                Forms\Components\Select::make('allow_accessories')
                                                    ->label('Allow Accessories')
                                                    ->options([
                                                        'NONE' => 'None',
                                                        'ACCESSORIES_OF_UNIT_ONLY' => 'Accessories of Unit Only',
                                                        'UNRESTRICTED' => 'Unrestricted',
                                                    ])
                                                    ->default('UNRESTRICTED')
                                                    ->columnSpan(1),

                                                Forms\Components\Toggle::make('complete_vehicles_only')
                                                    ->label('Complete Vehicles Only')
                                                    ->columnSpan(1),

                                                Forms\Components\Toggle::make('allows_stacked')
                                                    ->label('Allows Stacked')
                                                    ->columnSpan(1),

                                                Forms\Components\Toggle::make('allows_piggy_back')
                                                    ->label('Allows Piggy Back')
                                                    ->columnSpan(1),
                                            ])
                                            ->columns(3)
                                            ->collapsible()
                                            ->columnSpanFull(),

                                        Forms\Components\Section::make('Soft Limits (Upon Request)')
                                            ->schema([
                                                Forms\Components\TextInput::make('soft_max_height_cm')
                                                    ->label('Soft Max Height (cm)')
                                                    ->numeric()
                                                    ->step(0.1)
                                                    ->columnSpan(1),

                                                Forms\Components\Toggle::make('soft_height_requires_approval')
                                                    ->label('Requires Approval')
                                                    ->columnSpan(1),

                                                Forms\Components\TextInput::make('soft_max_weight_kg')
                                                    ->label('Soft Max Weight (kg)')
                                                    ->numeric()
                                                    ->step(0.1)
                                                    ->columnSpan(1),

                                                Forms\Components\Toggle::make('soft_weight_requires_approval')
                                                    ->label('Requires Approval')
                                                    ->columnSpan(1),
                                            ])
                                            ->columns(4)
                                            ->collapsible()
                                            ->columnSpanFull(),

                                        Forms\Components\Section::make('Destination Terms')
                                            ->schema([
                                                Forms\Components\Toggle::make('is_free_out')
                                                    ->label('Free Out')
                                                    ->columnSpan(1),

                                                Forms\Components\Toggle::make('requires_waiver')
                                                    ->label('Requires Waiver')
                                                    ->columnSpan(1),

                                                Forms\Components\Toggle::make('waiver_provided_by_carrier')
                                                    ->label('Waiver Provided by Carrier')
                                                    ->columnSpan(1),
                                            ])
                                            ->columns(3)
                                            ->collapsible()
                                            ->columnSpanFull(),

                                        Forms\Components\Textarea::make('notes')
                                            ->label('Notes')
                                            ->rows(2)
                                            ->columnSpanFull(),

                                        Forms\Components\TextInput::make('priority')
                                            ->label('Priority')
                                            ->required()
                                            ->numeric()
                                            ->default(0)
                                            ->columnSpan(1),

                                        Forms\Components\DatePicker::make('effective_from')
                                            ->label('Effective From')
                                            ->columnSpan(1),

                                        Forms\Components\DatePicker::make('effective_to')
                                            ->label('Effective To')
                                            ->columnSpan(1),

                                        Forms\Components\Toggle::make('is_active')
                                            ->label('Active')
                                            ->default(true)
                                            ->columnSpan(1),
                                    ])
                                    ->label('Acceptance Rules')
                                    ->defaultItems(0)
                                    ->itemLabel(function (array $state): ?string {
                                        if (!empty($state['name'])) {
                                            return $state['name'];
                                        }
                                        
                                        if (!empty($state['vehicle_category'])) {
                                            return $state['vehicle_category'] . ' Rule';
                                        }
                                        
                                        if (!empty($state['category_group_ids']) && is_array($state['category_group_ids'])) {
                                            $groupNames = collect($state['category_group_ids'])
                                                ->map(fn($id) => \App\Models\CarrierCategoryGroup::find($id)?->display_name ?? 'Group #' . $id)
                                                ->join(', ');
                                            return $groupNames . ' Rule';
                                        }
                                        if (!empty($state['category_group_id'])) {
                                            $group = \App\Models\CarrierCategoryGroup::find($state['category_group_id']);
                                            return ($group ? $group->display_name : 'Group #' . $state['category_group_id']) . ' Rule';
                                        }
                                        
                                        return 'Global Rule';
                                    })
                                    ->collapsible()
                                    ->cloneable()
                                    ->columnSpanFull(),
                            ]),

                        // Tab 4: Transform Rules
                        Forms\Components\Tabs\Tab::make('transforms')
                            ->label('Transforms')
                            ->icon('heroicon-o-arrow-path')
                            ->schema([
                                Forms\Components\Placeholder::make('transform_info')
                                    ->label('How Transform Rules Work')
                                    ->content('Transform rules modify the LM (Linear Meter) calculation for overwidth cargo. The calculation depends on the cargo width relative to the trigger threshold:
                                    
• If width ≤ trigger_width_gt_cm: LM = (Length × 250cm) / 250cm (minimum width 250cm applies)
• If width > trigger_width_gt_cm: LM = (Length × Width) / divisor_cm (actual width used)

Example: For trigger = 260cm, divisor = 250cm:
- Width 255cm: LM = (L × 250) / 250 = L (e.g., 10m length = 10 LM)
- Width 261cm: LM = (L × 261) / 250 = L × 1.044 (e.g., 10m length = 10.44 LM)

If no transform rules match for a port, the global fallback formula L×max(W,250)/250 is used.')
                                    ->columnSpanFull(),

                                Forms\Components\Repeater::make('transformRules')
                                    ->relationship('transformRules')
                                    ->reorderable('sort_order')
                                    ->collapsible()
                                    ->collapsed()
                                    ->mutateRelationshipDataBeforeSaveUsing(function (array $data): array {
                                        if (!empty($data['category_group_ids'])) {
                                            $data['vehicle_categories'] = null;
                                            $data['category_group_id'] = null; // Clear legacy field
                                        }
                                        if (!empty($data['vehicle_categories'])) {
                                            $data['category_group_ids'] = null;
                                            $data['category_group_id'] = null; // Clear legacy field
                                        }
                                        return $data;
                                    })
                                    ->schema([
                                        Forms\Components\Select::make('port_ids')
                                            ->label('Ports (POD)')
                                            ->options(function () {
                                                return \App\Models\Port::orderBy('name')
                                                    ->get()
                                                    ->mapWithKeys(function ($port) {
                                                        return [$port->id => $port->formatFull()];
                                                    });
                                            })
                                            ->searchable()
                                            ->multiple()
                                            ->preload()
                                            ->helperText('Select one or more ports. Leave empty for global rule.')
                                            ->columnSpan(1),

                                        Forms\Components\Select::make('port_group_ids')
                                            ->label('Port Groups')
                                            ->options(function (Forms\Get $get, $livewire) {
                                                try {
                                                    $carrierId = null;
                                                    if (isset($livewire) && method_exists($livewire, 'getRecord')) {
                                                        $record = $livewire->getRecord();
                                                        $carrierId = $record ? $record->id : null;
                                                    }
                                                    
                                                    if (!$carrierId) {
                                                        return [];
                                                    }
                                                    
                                                    return \App\Models\CarrierPortGroup::where('carrier_id', $carrierId)
                                                        ->active()
                                                        ->orderBy('sort_order')
                                                        ->pluck('display_name', 'id')
                                                        ->toArray();
                                                } catch (\Throwable $e) {
                                                    return [];
                                                }
                                            })
                                            ->getOptionLabelUsing(function ($value) {
                                                if (!$value) {
                                                    return null;
                                                }
                                                try {
                                                    $group = \App\Models\CarrierPortGroup::find($value);
                                                    return $group ? $group->display_name : null;
                                                } catch (\Throwable $e) {
                                                    return null;
                                                }
                                            })
                                            ->multiple()
                                            ->preload()
                                            ->helperText('Select port groups (e.g., WAF, MED). If both Ports and Port Groups are set, rule matches if either matches.')
                                            ->columnSpan(1),

                                        Forms\Components\Hidden::make('port_id')
                                            ->dehydrated(false),

                                        Forms\Components\Select::make('vehicle_categories')
                                            ->label('Vehicle Categories')
                                            ->options(function () {
                                                return config('quotation.commodity_types.vehicles.categories', []);
                                            })
                                            ->searchable()
                                            ->multiple()
                                            ->preload()
                                            ->helperText('Select one or more vehicle categories. Leave empty for all categories. Note: If Category Group is selected, this field should be empty.')
                                            ->columnSpan(1)
                                            ->disabled(fn ($get) => !empty($get('category_group_ids'))),

                                        Forms\Components\Hidden::make('vehicle_category')
                                            ->dehydrated(false),

                                        Forms\Components\Select::make('category_group_ids')
                                            ->label('Category Groups')
                                            ->options(function (Forms\Get $get, $livewire) {
                                                try {
                                                    $carrierId = null;
                                                    
                                                    // Try livewire first, but check if it's actually an object
                                                    if (isset($livewire) && is_object($livewire) && method_exists($livewire, 'getRecord')) {
                                                        try {
                                                            $record = $livewire->getRecord();
                                                            $carrierId = $record ? $record->id : null;
                                                        } catch (\Throwable $e) {
                                                            // Livewire getRecord failed, fall through to $get
                                                        }
                                                    }
                                                    
                                                    // Fallback to $get if livewire didn't work
                                                    if (!$carrierId) {
                                                        $carrierId = $get('../../../../id');
                                                    }
                                                    
                                                    if (!$carrierId) {
                                                        return [];
                                                    }
                                                    
                                                    return \App\Models\CarrierCategoryGroup::where('carrier_id', $carrierId)
                                                        ->orderBy('display_name')
                                                        ->pluck('display_name', 'id')
                                                        ->toArray();
                                                } catch (\Throwable $e) {
                                                    \Log::error('Category group options error: ' . $e->getMessage());
                                                    return [];
                                                }
                                            })
                                            ->getOptionLabelUsing(function ($value) {
                                                if (!$value) {
                                                    return null;
                                                }
                                                try {
                                                    $group = \App\Models\CarrierCategoryGroup::find($value);
                                                    return $group ? $group->display_name : null;
                                                } catch (\Throwable $e) {
                                                    return null;
                                                }
                                            })
                                            ->multiple()
                                            ->preload()
                                            ->helperText('OR use one or more category groups. Note: If Vehicle Categories are selected, this field should be empty.')
                                            ->columnSpan(1)
                                            ->disabled(fn ($get) => !empty($get('vehicle_categories')))
                                            ->reactive(),

                                        Forms\Components\Select::make('vessel_names')
                                            ->label('Vessel Names')
                                            ->options(function (Forms\Get $get, $livewire) {
                                                try {
                                                    $carrierId = null;
                                                    
                                                    // Try livewire first, but check if it's actually an object
                                                    if (isset($livewire) && is_object($livewire) && method_exists($livewire, 'getRecord')) {
                                                        try {
                                                            $record = $livewire->getRecord();
                                                            $carrierId = $record ? $record->id : null;
                                                        } catch (\Throwable $e) {
                                                            // Livewire getRecord failed, fall through to $get
                                                        }
                                                    }
                                                    
                                                    // Fallback to $get if livewire didn't work
                                                    if (!$carrierId) {
                                                        $carrierId = $get('../../../../id');
                                                    }
                                                    
                                                    if (!$carrierId) {
                                                        return [];
                                                    }
                                                    
                                                    return \App\Models\ShippingSchedule::where('carrier_id', $carrierId)
                                                        ->whereNotNull('vessel_name')
                                                        ->distinct()
                                                        ->orderBy('vessel_name')
                                                        ->pluck('vessel_name', 'vessel_name');
                                                } catch (\Throwable $e) {
                                                    return [];
                                                }
                                            })
                                            ->multiple()
                                            ->helperText('Select one or more vessel names. Leave empty for all vessels.')
                                            ->columnSpan(1),

                                        Forms\Components\Hidden::make('vessel_name')
                                            ->dehydrated(false),

                                        Forms\Components\CheckboxList::make('vessel_classes')
                                            ->label('Vessel Classes')
                                            ->options(function ($get) {
                                                try {
                                                    $carrierId = $get('../../../../id');
                                                    
                                                    if (!$carrierId) {
                                                        return [];
                                                    }
                                                    
                                                    return \App\Models\ShippingSchedule::where('carrier_id', $carrierId)
                                                        ->whereNotNull('vessel_class')
                                                        ->distinct()
                                                        ->orderBy('vessel_class')
                                                        ->pluck('vessel_class', 'vessel_class');
                                                } catch (\Throwable $e) {
                                                    return [];
                                                }
                                            })
                                            ->searchable()
                                            ->bulkToggleable()
                                            ->gridDirection('row')
                                            ->helperText('Select one or more vessel classes. Leave empty for all vessel classes.')
                                            ->columnSpan(1)
                                            ->dehydrated(true),

                                        Forms\Components\Hidden::make('vessel_class')
                                            ->dehydrated(false),

                                        Forms\Components\Select::make('transform_code')
                                            ->label('Transform Type')
                                            ->required()
                                            ->options([
                                                'OVERWIDTH_LM_RECALC' => 'Overwidth LM Recalculation',
                                            ])
                                            ->default('OVERWIDTH_LM_RECALC')
                                            ->disabled()
                                            ->columnSpanFull(),

                                        Forms\Components\Section::make('Transform Parameters')
                                            ->schema([
                                                Forms\Components\TextInput::make('params.trigger_width_gt_cm')
                                                    ->label('Trigger Width (cm)')
                                                    ->required()
                                                    ->numeric()
                                                    ->step(0.1)
                                                    ->default(260)
                                                    ->helperText('Width threshold in cm. For widths ≤ this value: LM = (L × 250) / 250 (minimum width 250cm). For widths > this value: LM = (L × W) / divisor_cm.')
                                                    ->columnSpan(1),

                                                Forms\Components\TextInput::make('params.divisor_cm')
                                                    ->label('Divisor (cm)')
                                                    ->required()
                                                    ->numeric()
                                                    ->step(0.1)
                                                    ->default(250)
                                                    ->helperText('Divisor used ONLY when width > trigger_width_gt_cm. Formula: LM = (L × W) / divisor_cm. Usually 250 (2.5m).')
                                                    ->columnSpan(1),
                                            ])
                                            ->columns(2)
                                            ->columnSpanFull(),

                                        Forms\Components\TextInput::make('priority')
                                            ->label('Priority')
                                            ->required()
                                            ->numeric()
                                            ->default(0)
                                            ->columnSpan(1),

                                        Forms\Components\DatePicker::make('effective_from')
                                            ->label('Effective From')
                                            ->columnSpan(1),

                                        Forms\Components\DatePicker::make('effective_to')
                                            ->label('Effective To')
                                            ->columnSpan(1),

                                        Forms\Components\Toggle::make('is_active')
                                            ->label('Active')
                                            ->default(true)
                                            ->columnSpan(1),
                                    ])
                                    ->label('Transform Rules')
                                    ->defaultItems(0)
                                    ->itemLabel(fn (array $state): ?string => 
                                        'Overwidth: ≤' . ($state['params']['trigger_width_gt_cm'] ?? 260) . 'cm → L×250/250; >' . ($state['params']['trigger_width_gt_cm'] ?? 260) . 'cm → L×W/' . ($state['params']['divisor_cm'] ?? 250) . 'cm'
                                    )
                                    ->collapsible()
                                    ->cloneable()
                                    ->columnSpanFull(),
                            ]),

                        // Tab 5: Surcharge Rules
                        Forms\Components\Tabs\Tab::make('surcharges')
                            ->label('Surcharges')
                            ->icon('heroicon-o-currency-dollar')
                            ->schema([
                                Forms\Components\Repeater::make('surchargeRules')
                                    ->relationship('surchargeRules')
                                    ->reorderable('sort_order')
                                    ->collapsible()
                                    ->collapsed()
                                    ->mutateRelationshipDataBeforeSaveUsing(function (array $data): array {
                                        if (!empty($data['category_group_ids'])) {
                                            $data['vehicle_categories'] = null;
                                            $data['category_group_id'] = null; // Clear legacy field
                                        }
                                        if (!empty($data['vehicle_categories'])) {
                                            $data['category_group_ids'] = null;
                                            $data['category_group_id'] = null; // Clear legacy field
                                        }
                                        return $data;
                                    })
                                    ->schema([
                                        Forms\Components\Select::make('port_ids')
                                            ->label('Ports (POD)')
                                            ->options(function () {
                                                return \App\Models\Port::orderBy('name')
                                                    ->get()
                                                    ->mapWithKeys(function ($port) {
                                                        return [$port->id => $port->formatFull()];
                                                    });
                                            })
                                            ->searchable()
                                            ->multiple()
                                            ->preload()
                                            ->helperText('Select one or more ports. Leave empty for global rule.')
                                            ->columnSpan(1),

                                        Forms\Components\Select::make('port_group_ids')
                                            ->label('Port Groups')
                                            ->options(function (Forms\Get $get, $livewire) {
                                                try {
                                                    $carrierId = null;
                                                    if (isset($livewire) && method_exists($livewire, 'getRecord')) {
                                                        $record = $livewire->getRecord();
                                                        $carrierId = $record ? $record->id : null;
                                                    }
                                                    
                                                    if (!$carrierId) {
                                                        return [];
                                                    }
                                                    
                                                    return \App\Models\CarrierPortGroup::where('carrier_id', $carrierId)
                                                        ->active()
                                                        ->orderBy('sort_order')
                                                        ->pluck('display_name', 'id')
                                                        ->toArray();
                                                } catch (\Throwable $e) {
                                                    return [];
                                                }
                                            })
                                            ->getOptionLabelUsing(function ($value) {
                                                if (!$value) {
                                                    return null;
                                                }
                                                try {
                                                    $group = \App\Models\CarrierPortGroup::find($value);
                                                    return $group ? $group->display_name : null;
                                                } catch (\Throwable $e) {
                                                    return null;
                                                }
                                            })
                                            ->multiple()
                                            ->preload()
                                            ->helperText('Select port groups (e.g., WAF, MED). If both Ports and Port Groups are set, rule matches if either matches.')
                                            ->columnSpan(1),

                                        Forms\Components\Hidden::make('port_id')
                                            ->dehydrated(false),

                                        Forms\Components\Select::make('vehicle_categories')
                                            ->label('Vehicle Categories')
                                            ->options(function () {
                                                return config('quotation.commodity_types.vehicles.categories', []);
                                            })
                                            ->searchable()
                                            ->multiple()
                                            ->preload()
                                            ->helperText('Select one or more vehicle categories. Leave empty for all categories. Note: If Category Group is selected, this field should be empty.')
                                            ->columnSpan(1)
                                            ->disabled(fn ($get) => !empty($get('category_group_ids'))),

                                        Forms\Components\Hidden::make('vehicle_category')
                                            ->dehydrated(false),

                                        Forms\Components\Select::make('category_group_ids')
                                            ->label('Category Groups')
                                            ->options(function (Forms\Get $get) {
                                                try {
                                                    $carrierId = $get('../../../../id');
                                                    
                                                    if (!$carrierId) {
                                                        return [];
                                                    }
                                                    
                                                    return \App\Models\CarrierCategoryGroup::where('carrier_id', $carrierId)
                                                        ->orderBy('display_name')
                                                        ->pluck('display_name', 'id')
                                                        ->toArray();
                                                } catch (\Throwable $e) {
                                                    \Log::error('Category group options error: ' . $e->getMessage());
                                                    return [];
                                                }
                                            })
                                            ->getOptionLabelUsing(function ($value) {
                                                if (!$value) {
                                                    return null;
                                                }
                                                try {
                                                    $group = \App\Models\CarrierCategoryGroup::find($value);
                                                    return $group ? $group->display_name : null;
                                                } catch (\Throwable $e) {
                                                    return null;
                                                }
                                            })
                                            ->multiple()
                                            ->preload()
                                            ->helperText('OR use one or more category groups. Note: If Vehicle Categories are selected, this field should be empty.')
                                            ->columnSpan(1)
                                            ->disabled(fn ($get) => !empty($get('vehicle_categories')))
                                            ->reactive(),

                                        Forms\Components\Select::make('vessel_names')
                                            ->label('Vessel Names')
                                            ->options(function ($get) {
                                                try {
                                                    $carrierId = $get('../../../../id');
                                                    
                                                    if (!$carrierId) {
                                                        return [];
                                                    }
                                                    
                                                    return \App\Models\ShippingSchedule::where('carrier_id', $carrierId)
                                                        ->whereNotNull('vessel_name')
                                                        ->distinct()
                                                        ->orderBy('vessel_name')
                                                        ->pluck('vessel_name', 'vessel_name');
                                                } catch (\Throwable $e) {
                                                    return [];
                                                }
                                            })
                                            ->multiple()
                                            ->helperText('Select one or more vessel names. Leave empty for all vessels.')
                                            ->columnSpan(1),

                                        Forms\Components\Hidden::make('vessel_name')
                                            ->dehydrated(false),

                                        Forms\Components\CheckboxList::make('vessel_classes')
                                            ->label('Vessel Classes')
                                            ->options(function ($get) {
                                                try {
                                                    $carrierId = $get('../../../../id');
                                                    
                                                    if (!$carrierId) {
                                                        return [];
                                                    }
                                                    
                                                    return \App\Models\ShippingSchedule::where('carrier_id', $carrierId)
                                                        ->whereNotNull('vessel_class')
                                                        ->distinct()
                                                        ->orderBy('vessel_class')
                                                        ->pluck('vessel_class', 'vessel_class');
                                                } catch (\Throwable $e) {
                                                    return [];
                                                }
                                            })
                                            ->searchable()
                                            ->bulkToggleable()
                                            ->gridDirection('row')
                                            ->helperText('Select one or more vessel classes. Leave empty for all vessel classes.')
                                            ->columnSpan(1)
                                            ->dehydrated(true),

                                        Forms\Components\Hidden::make('vessel_class')
                                            ->dehydrated(false),

                                        Forms\Components\TextInput::make('event_code')
                                            ->label('Event Code')
                                            ->required()
                                            ->maxLength(50)
                                            ->placeholder('e.g., TRACKING_PERCENT, OVERWIDTH_STEP_BLOCKS')
                                            ->helperText('Unique identifier for this surcharge event')
                                            ->columnSpan(1),

                                        Forms\Components\TextInput::make('name')
                                            ->label('Surcharge Name')
                                            ->required()
                                            ->maxLength(255)
                                            ->columnSpan(1),

                                        Forms\Components\Select::make('calc_mode')
                                            ->label('Calculation Mode')
                                            ->required()
                                            ->options([
                                                'FLAT' => 'Flat Amount',
                                                'PER_UNIT' => 'Per Unit',
                                                'PERCENT_OF_BASIC_FREIGHT' => 'Percent of Basic Freight',
                                                'WEIGHT_TIER' => 'Weight Tier',
                                                'PER_TON_ABOVE' => 'Per Ton Above',
                                                'PER_TANK' => 'Per Tank',
                                                'PER_LM' => 'Per LM',
                                                'WIDTH_STEP_BLOCKS' => 'Width Step Blocks',
                                                'WIDTH_LM_BASIS' => 'Width LM Basis',
                                            ])
                                            ->live()
                                            ->columnSpanFull(),

                                        Forms\Components\Section::make('Calculation Parameters')
                                            ->schema(function (Forms\Get $get) {
                                                $calcMode = $get('calc_mode');
                                                $schema = [];

                                                switch ($calcMode) {
                                                    case 'PERCENT_OF_BASIC_FREIGHT':
                                                        $schema[] = Forms\Components\TextInput::make('params.percentage')
                                                            ->label('Percentage')
                                                            ->required()
                                                            ->numeric()
                                                            ->step(0.1)
                                                            ->suffix('%')
                                                            ->columnSpanFull();
                                                        break;

                                                    case 'WEIGHT_TIER':
                                                        $schema[] = Forms\Components\Repeater::make('params.tiers')
                                                            ->label('Weight Tiers')
                                                            ->schema([
                                                                Forms\Components\TextInput::make('max_kg')
                                                                    ->label('Max Weight (kg)')
                                                                    ->numeric()
                                                                    ->step(0.1)
                                                                    ->helperText('Leave empty for "above" tier')
                                                                    ->columnSpan(1),
                                                                Forms\Components\TextInput::make('amount')
                                                                    ->label('Amount')
                                                                    ->required()
                                                                    ->numeric()
                                                                    ->step(0.1)
                                                                    ->columnSpan(1),
                                                            ])
                                                            ->columns(2)
                                                            ->defaultItems(1)
                                                            ->columnSpanFull();
                                                        break;

                                                    case 'WIDTH_STEP_BLOCKS':
                                                        $schema[] = Forms\Components\TextInput::make('params.trigger_width_gt_cm')
                                                            ->label('Trigger Width (cm)')
                                                            ->numeric()
                                                            ->step(0.1)
                                                            ->helperText('Optional: only apply if width exceeds this')
                                                            ->columnSpan(1);
                                                        $schema[] = Forms\Components\TextInput::make('params.threshold_cm')
                                                            ->label('Threshold (cm)')
                                                            ->required()
                                                            ->numeric()
                                                            ->step(0.1)
                                                            ->default(250)
                                                            ->columnSpan(1);
                                                        $schema[] = Forms\Components\TextInput::make('params.block_cm')
                                                            ->label('Block Size (cm)')
                                                            ->required()
                                                            ->numeric()
                                                            ->step(0.1)
                                                            ->default(25)
                                                            ->helperText('e.g., 10, 20, or 25')
                                                            ->columnSpan(1);
                                                        $schema[] = Forms\Components\Select::make('params.rounding')
                                                            ->label('Rounding')
                                                            ->required()
                                                            ->options([
                                                                'CEIL' => 'Ceil (Round Up)',
                                                                'FLOOR' => 'Floor (Round Down)',
                                                                'ROUND' => 'Round',
                                                            ])
                                                            ->default('CEIL')
                                                            ->columnSpan(1);
                                                        $schema[] = Forms\Components\Select::make('params.qty_basis')
                                                            ->label('Quantity Basis')
                                                            ->required()
                                                            ->options([
                                                                'LM' => 'Per LM',
                                                                'UNIT' => 'Per Unit',
                                                            ])
                                                            ->default('LM')
                                                            ->columnSpan(1);
                                                        $schema[] = Forms\Components\TextInput::make('params.amount_per_block')
                                                            ->label('Amount Per Block')
                                                            ->required()
                                                            ->numeric()
                                                            ->step(0.1)
                                                            ->columnSpan(1);
                                                        $schema[] = Forms\Components\TextInput::make('params.exclusive_group')
                                                            ->label('Exclusive Group')
                                                            ->maxLength(50)
                                                            ->helperText('e.g., OVERWIDTH (only one rule per group applies)')
                                                            ->columnSpanFull();
                                                        break;

                                                    case 'WIDTH_LM_BASIS':
                                                        $schema[] = Forms\Components\TextInput::make('params.trigger_width_gt_cm')
                                                            ->label('Trigger Width (cm)')
                                                            ->required()
                                                            ->numeric()
                                                            ->step(0.1)
                                                            ->default(260)
                                                            ->columnSpan(1);
                                                        $schema[] = Forms\Components\TextInput::make('params.amount_per_lm')
                                                            ->label('Amount Per LM')
                                                            ->required()
                                                            ->numeric()
                                                            ->step(0.1)
                                                            ->columnSpan(1);
                                                        $schema[] = Forms\Components\TextInput::make('params.exclusive_group')
                                                            ->label('Exclusive Group')
                                                            ->maxLength(50)
                                                            ->helperText('e.g., OVERWIDTH')
                                                            ->columnSpanFull();
                                                        break;

                                                    case 'PER_UNIT':
                                                    case 'PER_TANK':
                                                    case 'PER_LM':
                                                    case 'PER_TON_ABOVE':
                                                        $schema[] = Forms\Components\TextInput::make('params.amount')
                                                            ->label('Amount')
                                                            ->required()
                                                            ->numeric()
                                                            ->step(0.1)
                                                            ->columnSpanFull();
                                                        break;

                                                    case 'FLAT':
                                                    default:
                                                        $schema[] = Forms\Components\TextInput::make('params.amount')
                                                            ->label('Flat Amount')
                                                            ->required()
                                                            ->numeric()
                                                            ->step(0.1)
                                                            ->columnSpanFull();
                                                        break;
                                                }

                                                return $schema;
                                            })
                                            ->columnSpanFull(),

                                        Forms\Components\TextInput::make('priority')
                                            ->label('Priority')
                                            ->required()
                                            ->numeric()
                                            ->default(0)
                                            ->columnSpan(1),

                                        Forms\Components\DatePicker::make('effective_from')
                                            ->label('Effective From')
                                            ->columnSpan(1),

                                        Forms\Components\DatePicker::make('effective_to')
                                            ->label('Effective To')
                                            ->columnSpan(1),

                                        Forms\Components\Toggle::make('is_active')
                                            ->label('Active')
                                            ->default(true)
                                            ->columnSpan(1),
                                    ])
                                    ->label('Surcharge Rules')
                                    ->defaultItems(0)
                                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? 'New Surcharge')
                                    ->collapsible()
                                    ->cloneable()
                                    ->reorderable()
                                    ->columnSpanFull(),
                            ]),

                        // Tab 6: Article Mapping
                        Forms\Components\Tabs\Tab::make('article_mapping')
                            ->label('Article Mapping')
                            ->icon('heroicon-o-link')
                            ->schema([
                                Forms\Components\Repeater::make('surchargeArticleMaps')
                                    ->relationship('surchargeArticleMaps')
                                    ->reorderable('sort_order')
                                    ->collapsible()
                                    ->collapsed()
                                    ->mutateRelationshipDataBeforeSaveUsing(function (array $data): array {
                                        if (!empty($data['category_group_ids'])) {
                                            $data['vehicle_categories'] = null;
                                            $data['category_group_id'] = null; // Clear legacy field
                                        }
                                        if (!empty($data['vehicle_categories'])) {
                                            $data['category_group_ids'] = null;
                                            $data['category_group_id'] = null; // Clear legacy field
                                        }
                                        return $data;
                                    })
                                    ->schema([
                                        Forms\Components\Select::make('port_ids')
                                            ->label('Ports (POD)')
                                            ->options(function () {
                                                return \App\Models\Port::orderBy('name')
                                                    ->get()
                                                    ->mapWithKeys(function ($port) {
                                                        return [$port->id => $port->formatFull()];
                                                    });
                                            })
                                            ->searchable()
                                            ->multiple()
                                            ->preload()
                                            ->helperText('Select one or more ports. Leave empty for global rule.')
                                            ->columnSpan(1),

                                        Forms\Components\Select::make('port_group_ids')
                                            ->label('Port Groups')
                                            ->options(function (Forms\Get $get, $livewire) {
                                                try {
                                                    $carrierId = null;
                                                    if (isset($livewire) && method_exists($livewire, 'getRecord')) {
                                                        $record = $livewire->getRecord();
                                                        $carrierId = $record ? $record->id : null;
                                                    }
                                                    
                                                    if (!$carrierId) {
                                                        return [];
                                                    }
                                                    
                                                    return \App\Models\CarrierPortGroup::where('carrier_id', $carrierId)
                                                        ->active()
                                                        ->orderBy('sort_order')
                                                        ->pluck('display_name', 'id')
                                                        ->toArray();
                                                } catch (\Throwable $e) {
                                                    return [];
                                                }
                                            })
                                            ->getOptionLabelUsing(function ($value) {
                                                if (!$value) {
                                                    return null;
                                                }
                                                try {
                                                    $group = \App\Models\CarrierPortGroup::find($value);
                                                    return $group ? $group->display_name : null;
                                                } catch (\Throwable $e) {
                                                    return null;
                                                }
                                            })
                                            ->multiple()
                                            ->preload()
                                            ->helperText('Select port groups (e.g., WAF, MED). If both Ports and Port Groups are set, rule matches if either matches.')
                                            ->columnSpan(1),

                                        Forms\Components\Hidden::make('port_id')
                                            ->dehydrated(false),

                                        Forms\Components\Select::make('vehicle_categories')
                                            ->label('Vehicle Categories')
                                            ->options(function () {
                                                return config('quotation.commodity_types.vehicles.categories', []);
                                            })
                                            ->searchable()
                                            ->multiple()
                                            ->preload()
                                            ->helperText('Select one or more vehicle categories. Leave empty for all categories. Note: If Category Group is selected, this field should be empty.')
                                            ->columnSpan(1)
                                            ->disabled(fn ($get) => !empty($get('category_group_ids'))),

                                        Forms\Components\Hidden::make('vehicle_category')
                                            ->dehydrated(false),

                                        Forms\Components\Select::make('category_group_ids')
                                            ->label('Category Groups')
                                            ->options(function (Forms\Get $get) {
                                                try {
                                                    $carrierId = $get('../../../../id');
                                                    
                                                    if (!$carrierId) {
                                                        return [];
                                                    }
                                                    
                                                    return \App\Models\CarrierCategoryGroup::where('carrier_id', $carrierId)
                                                        ->orderBy('display_name')
                                                        ->pluck('display_name', 'id')
                                                        ->toArray();
                                                } catch (\Throwable $e) {
                                                    \Log::error('Category group options error: ' . $e->getMessage());
                                                    return [];
                                                }
                                            })
                                            ->getOptionLabelUsing(function ($value) {
                                                if (!$value) {
                                                    return null;
                                                }
                                                try {
                                                    $group = \App\Models\CarrierCategoryGroup::find($value);
                                                    return $group ? $group->display_name : null;
                                                } catch (\Throwable $e) {
                                                    return null;
                                                }
                                            })
                                            ->multiple()
                                            ->preload()
                                            ->helperText('OR use one or more category groups. Note: If Vehicle Categories are selected, this field should be empty.')
                                            ->columnSpan(1)
                                            ->disabled(fn ($get) => !empty($get('vehicle_categories')))
                                            ->reactive(),

                                        Forms\Components\Select::make('vessel_names')
                                            ->label('Vessel Names')
                                            ->options(function ($get) {
                                                try {
                                                    $carrierId = $get('../../../../id');
                                                    
                                                    if (!$carrierId) {
                                                        return [];
                                                    }
                                                    
                                                    return \App\Models\ShippingSchedule::where('carrier_id', $carrierId)
                                                        ->whereNotNull('vessel_name')
                                                        ->distinct()
                                                        ->orderBy('vessel_name')
                                                        ->pluck('vessel_name', 'vessel_name');
                                                } catch (\Throwable $e) {
                                                    return [];
                                                }
                                            })
                                            ->multiple()
                                            ->helperText('Select one or more vessel names. Leave empty for all vessels.')
                                            ->columnSpan(1),

                                        Forms\Components\Hidden::make('vessel_name')
                                            ->dehydrated(false),

                                        Forms\Components\CheckboxList::make('vessel_classes')
                                            ->label('Vessel Classes')
                                            ->options(function ($get) {
                                                try {
                                                    $carrierId = $get('../../../../id');
                                                    
                                                    if (!$carrierId) {
                                                        return [];
                                                    }
                                                    
                                                    return \App\Models\ShippingSchedule::where('carrier_id', $carrierId)
                                                        ->whereNotNull('vessel_class')
                                                        ->distinct()
                                                        ->orderBy('vessel_class')
                                                        ->pluck('vessel_class', 'vessel_class');
                                                } catch (\Throwable $e) {
                                                    return [];
                                                }
                                            })
                                            ->searchable()
                                            ->bulkToggleable()
                                            ->gridDirection('row')
                                            ->helperText('Select one or more vessel classes. Leave empty for all vessel classes.')
                                            ->columnSpan(1)
                                            ->dehydrated(true),

                                        Forms\Components\Hidden::make('vessel_class')
                                            ->dehydrated(false),

                                        Forms\Components\TextInput::make('event_code')
                                            ->label('Event Code')
                                            ->required()
                                            ->maxLength(50)
                                            ->helperText('Must match a surcharge rule event_code')
                                            ->columnSpan(1),

                                        Forms\Components\Select::make('article_id')
                                            ->label('Robaws Article')
                                            ->relationship('article', 'article_name')
                                            ->searchable()
                                            ->preload()
                                            ->required()
                                            ->columnSpan(1),

                                        Forms\Components\Select::make('qty_mode')
                                            ->label('Quantity Mode')
                                            ->required()
                                            ->options([
                                                'FLAT' => 'Flat',
                                                'PER_UNIT' => 'Per Unit',
                                                'PERCENT_OF_BASIC_FREIGHT' => 'Percent of Basic Freight',
                                                'WEIGHT_TIER' => 'Weight Tier',
                                                'PER_TON_ABOVE' => 'Per Ton Above',
                                                'PER_TANK' => 'Per Tank',
                                                'PER_LM' => 'Per LM',
                                                'WIDTH_STEP_BLOCKS' => 'Width Step Blocks',
                                                'WIDTH_LM_BASIS' => 'Width LM Basis',
                                            ])
                                            ->columnSpan(1),

                                        Forms\Components\KeyValue::make('params')
                                            ->label('Override Parameters (JSON)')
                                            ->helperText('Optional: override default params from surcharge rule')
                                            ->columnSpanFull(),

                                        Forms\Components\DatePicker::make('effective_from')
                                            ->label('Effective From')
                                            ->columnSpan(1),

                                        Forms\Components\DatePicker::make('effective_to')
                                            ->label('Effective To')
                                            ->columnSpan(1),

                                        Forms\Components\Toggle::make('is_active')
                                            ->label('Active')
                                            ->default(true)
                                            ->columnSpan(1),
                                    ])
                                    ->label('Article Mappings')
                                    ->defaultItems(0)
                                    ->itemLabel(fn (array $state): ?string => 
                                        ($state['event_code'] ?? 'New') . ' → ' . ($state['article_id'] ?? 'Article')
                                    )
                                    ->collapsible()
                                    ->cloneable()
                                    ->reorderable()
                                    ->columnSpanFull(),
                            ]),

                        // Tab 7: Clauses
                        Forms\Components\Tabs\Tab::make('clauses')
                            ->label('Clauses')
                            ->icon('heroicon-o-document-text')
                            ->schema([
                                Forms\Components\Repeater::make('clauses')
                                    ->relationship('clauses')
                                    ->reorderable('sort_order')
                                    ->collapsible()
                                    ->collapsed()
                                    ->schema([
                                        Forms\Components\Select::make('port_id')
                                            ->label('Port (POD)')
                                            ->relationship('port', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->columnSpan(1),

                                        Forms\Components\TextInput::make('vessel_name')
                                            ->label('Vessel Name')
                                            ->maxLength(100)
                                            ->columnSpan(1),

                                        Forms\Components\TextInput::make('vessel_class')
                                            ->label('Vessel Class')
                                            ->maxLength(50)
                                            ->columnSpan(1),

                                        Forms\Components\Select::make('clause_type')
                                            ->label('Clause Type')
                                            ->required()
                                            ->options([
                                                'LEGAL' => 'Legal',
                                                'OPERATIONAL' => 'Operational',
                                                'LIABILITY' => 'Liability',
                                            ])
                                            ->columnSpan(1),

                                        Forms\Components\RichEditor::make('text')
                                            ->label('Clause Text')
                                            ->required()
                                            ->toolbarButtons([
                                                'bold',
                                                'italic',
                                                'underline',
                                                'bulletList',
                                                'orderedList',
                                                'link',
                                            ])
                                            ->columnSpanFull(),

                                        Forms\Components\DatePicker::make('effective_from')
                                            ->label('Effective From')
                                            ->columnSpan(1),

                                        Forms\Components\DatePicker::make('effective_to')
                                            ->label('Effective To')
                                            ->columnSpan(1),

                                        Forms\Components\Toggle::make('is_active')
                                            ->label('Active')
                                            ->default(true)
                                            ->columnSpan(1),
                                    ])
                                    ->label('Clauses')
                                    ->defaultItems(0)
                                    ->itemLabel(fn (array $state): ?string => 
                                        ($state['clause_type'] ?? 'New') . ' Clause'
                                    )
                                    ->collapsible()
                                    ->cloneable()
                                    ->reorderable()
                                    ->columnSpanFull(),
                            ]),

                        // Tab 8: Freight Mapping (ALLOWLIST)
                        Forms\Components\Tabs\Tab::make('article_mappings')
                            ->label('Freight Mapping')
                            ->icon('heroicon-o-squares-2x2')
                            ->schema([
                                Forms\Components\Repeater::make('articleMappings')
                                    ->relationship('articleMappings')
                                    ->reorderable('sort_order')
                                    ->collapsible()
                                    ->collapsed()
                                    ->mutateRelationshipDataBeforeSaveUsing(function (array $data): array {
                                        // Normalize empty arrays to null
                                        foreach (['port_ids', 'port_group_ids', 'vehicle_categories', 'category_group_ids', 'vessel_names', 'vessel_classes'] as $field) {
                                            if (isset($data[$field]) && empty($data[$field])) {
                                                $data[$field] = null;
                                            }
                                        }
                                        
                                        // Category group vs vehicle category exclusivity (MUTUALLY EXCLUSIVE)
                                        if (!empty($data['category_group_ids'])) {
                                            $data['vehicle_categories'] = null;
                                        }
                                        if (!empty($data['vehicle_categories'])) {
                                            $data['category_group_ids'] = null;
                                        }
                                        
                                        return $data;
                                    })
                                    ->schema([
                                        Forms\Components\Select::make('article_id')
                                            ->label('Article')
                                            ->relationship('article', 'article_name', function ($query) {
                                                return $query->where('is_parent_article', true)
                                                    ->where('is_active', true);
                                            })
                                            ->searchable()
                                            ->preload()
                                            ->required()
                                            ->reactive()
                                            ->afterStateUpdated(function (Forms\Set $set, $state, Forms\Get $get) {
                                                // Auto-populate name when article is selected (if name is empty)
                                                if (!empty($state)) {
                                                    try {
                                                        $article = \App\Models\RobawsArticleCache::find($state);
                                                        if ($article) {
                                                            $articleName = $article->article_name ?? '';
                                                            $shortName = explode(',', $articleName)[0];
                                                            $shortName = strlen($shortName) > 40 ? substr($shortName, 0, 40) . '...' : $shortName;
                                                            // Only set if name is currently empty or default
                                                            $currentName = $get('name');
                                                            if (empty($currentName) || $currentName === 'Freight Mapping') {
                                                                $set('name', $shortName . ' Mapping');
                                                            }
                                                        }
                                                    } catch (\Throwable $e) {
                                                        // Silent fail
                                                    }
                                                }
                                            })
                                            ->getOptionLabelUsing(function ($value) {
                                                if (!$value) {
                                                    return null;
                                                }
                                                try {
                                                    $article = \App\Models\RobawsArticleCache::find($value);
                                                    return $article ? ($article->article_name . ' (' . ($article->article_code ?? 'N/A') . ')') : null;
                                                } catch (\Throwable $e) {
                                                    return null;
                                                }
                                            })
                                            ->helperText('Select the article to map. Only active parent articles are shown.')
                                            ->columnSpanFull(),

                                        Forms\Components\TextInput::make('name')
                                            ->label('Mapping Name')
                                            ->maxLength(255)
                                            ->helperText('Optional: Give this mapping a descriptive name. Auto-populated from article name if left empty.')
                                            ->afterStateHydrated(function ($component, $state, $record, Forms\Get $get) {
                                                // If name is empty, generate a default name based on article
                                                if (empty($state)) {
                                                    $name = null;
                                                    
                                                    // Check form state first (for new items or when editing)
                                                    $articleId = $get('article_id') ?? ($record->article_id ?? null);
                                                    
                                                    if (!empty($articleId)) {
                                                        try {
                                                            $article = \App\Models\RobawsArticleCache::find($articleId);
                                                            if ($article) {
                                                                // Extract a short name from article name (first part before comma or first 40 chars)
                                                                $articleName = $article->article_name ?? '';
                                                                $shortName = explode(',', $articleName)[0];
                                                                $shortName = strlen($shortName) > 40 ? substr($shortName, 0, 40) . '...' : $shortName;
                                                                $name = $shortName . ' Mapping';
                                                            }
                                                        } catch (\Throwable $e) {
                                                            // Silent fail, will use default below
                                                        }
                                                    }
                                                    
                                                    // If still no name, use default
                                                    if (empty($name)) {
                                                        $name = 'Freight Mapping';
                                                    }
                                                    
                                                    // Set the state if component exists
                                                    if ($component) {
                                                        $component->state($name);
                                                    }
                                                }
                                            })
                                            ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state) {
                                                // If name is cleared and article is selected, auto-populate again
                                                if (empty($state)) {
                                                    $articleId = $get('article_id');
                                                    if (!empty($articleId)) {
                                                        try {
                                                            $article = \App\Models\RobawsArticleCache::find($articleId);
                                                            if ($article) {
                                                                $articleName = $article->article_name ?? '';
                                                                $shortName = explode(',', $articleName)[0];
                                                                $shortName = strlen($shortName) > 40 ? substr($shortName, 0, 40) . '...' : $shortName;
                                                                $set('name', $shortName . ' Mapping');
                                                            }
                                                        } catch (\Throwable $e) {
                                                            // Silent fail
                                                        }
                                                    }
                                                }
                                            })
                                            ->dehydrated()
                                            ->reactive()
                                            ->columnSpanFull(),

                                        Forms\Components\Select::make('port_ids')
                                            ->label('Ports (POD)')
                                            ->options(function () {
                                                return \App\Models\Port::orderBy('name')
                                                    ->get()
                                                    ->mapWithKeys(function ($port) {
                                                        return [$port->id => $port->formatFull()];
                                                    });
                                            })
                                            ->searchable()
                                            ->multiple()
                                            ->preload()
                                            ->helperText('Select one or more ports. Leave empty for global rule.')
                                            ->columnSpan(1),

                                        Forms\Components\Select::make('port_group_ids')
                                            ->label('Port Groups')
                                            ->options(function (Forms\Get $get, $livewire) {
                                                try {
                                                    $carrierId = null;
                                                    if (isset($livewire) && method_exists($livewire, 'getRecord')) {
                                                        $record = $livewire->getRecord();
                                                        $carrierId = $record ? $record->id : null;
                                                    }
                                                    
                                                    if (!$carrierId) {
                                                        $carrierId = $get('../../../../id');
                                                    }
                                                    
                                                    if (!$carrierId) {
                                                        return [];
                                                    }
                                                    
                                                    return \App\Models\CarrierPortGroup::where('carrier_id', $carrierId)
                                                        ->active()
                                                        ->orderBy('sort_order')
                                                        ->pluck('display_name', 'id')
                                                        ->toArray();
                                                } catch (\Throwable $e) {
                                                    return [];
                                                }
                                            })
                                            ->getOptionLabelUsing(function ($value) {
                                                if (!$value) {
                                                    return null;
                                                }
                                                try {
                                                    $group = \App\Models\CarrierPortGroup::find($value);
                                                    return $group ? $group->display_name : null;
                                                } catch (\Throwable $e) {
                                                    return null;
                                                }
                                            })
                                            ->multiple()
                                            ->preload()
                                            ->helperText('Select port groups (e.g., WAF, MED). If both Ports and Port Groups are set, rule matches if either matches.')
                                            ->columnSpan(1),

                                        Forms\Components\Select::make('vehicle_categories')
                                            ->label('Vehicle Categories')
                                            ->options(function () {
                                                return config('quotation.commodity_types.vehicles.categories', []);
                                            })
                                            ->searchable()
                                            ->multiple()
                                            ->preload()
                                            ->helperText('Select one or more vehicle categories. Leave empty for all categories. Note: If Category Group is selected, this field should be empty.')
                                            ->columnSpan(1)
                                            ->disabled(fn ($get) => !empty($get('category_group_ids'))),

                                        Forms\Components\Select::make('category_group_ids')
                                            ->label('Category Groups')
                                            ->options(function (Forms\Get $get, $livewire) {
                                                try {
                                                    $carrierId = null;
                                                    
                                                    // Use livewire->getRecord() (same pattern as Acceptance Rules)
                                                    // Do NOT use $get('../../../../id') as it returns Livewire component ID, not carrier ID
                                                    if (isset($livewire) && is_object($livewire) && method_exists($livewire, 'getRecord')) {
                                                        try {
                                                            $record = $livewire->getRecord();
                                                            $carrierId = $record ? $record->id : null;
                                                        } catch (\Throwable $e) {
                                                            // Silent fail, will return empty array below
                                                        }
                                                    }
                                                    
                                                    if (!$carrierId) {
                                                        return [];
                                                    }
                                                    
                                                    return \App\Models\CarrierCategoryGroup::where('carrier_id', $carrierId)
                                                        ->where('is_active', true)
                                                        ->orderBy('display_name')
                                                        ->pluck('display_name', 'id')
                                                        ->toArray();
                                                } catch (\Throwable $e) {
                                                    \Log::error('Category group options error: ' . $e->getMessage());
                                                    return [];
                                                }
                                            })
                                            ->getOptionLabelUsing(function ($value) {
                                                if (!$value) {
                                                    return null;
                                                }
                                                try {
                                                    $group = \App\Models\CarrierCategoryGroup::find($value);
                                                    return $group ? $group->display_name : null;
                                                } catch (\Throwable $e) {
                                                    return null;
                                                }
                                            })
                                            ->multiple()
                                            ->preload()
                                            ->helperText('OR use one or more category groups. Note: If Vehicle Categories are selected, this field should be empty.')
                                            ->columnSpan(1)
                                            ->disabled(fn ($get) => !empty($get('vehicle_categories')))
                                            ->reactive(),

                                        Forms\Components\TagsInput::make('vessel_names')
                                            ->label('Vessel Names')
                                            ->helperText('Enter vessel names (one per line or comma-separated). Leave empty for all vessels.')
                                            ->columnSpan(1),

                                        Forms\Components\TagsInput::make('vessel_classes')
                                            ->label('Vessel Classes')
                                            ->helperText('Enter vessel classes (one per line or comma-separated). Leave empty for all vessel classes.')
                                            ->columnSpan(1),

                                        Forms\Components\TextInput::make('priority')
                                            ->label('Priority')
                                            ->numeric()
                                            ->default(0)
                                            ->helperText('Higher priority = checked first')
                                            ->columnSpan(1),

                                        Forms\Components\DatePicker::make('effective_from')
                                            ->label('Effective From')
                                            ->helperText('Rule becomes active on this date')
                                            ->columnSpan(1),

                                        Forms\Components\DatePicker::make('effective_to')
                                            ->label('Effective To')
                                            ->helperText('Rule expires on this date')
                                            ->columnSpan(1),

                                        Forms\Components\Toggle::make('is_active')
                                            ->label('Active')
                                            ->default(true)
                                            ->columnSpan(1),
                                    ])
                                    ->label('Freight Mappings')
                                    ->defaultItems(0)
                                    ->itemLabel(function (array $state): ?string {
                                        if (!empty($state['name'])) {
                                            return $state['name'];
                                        }
                                        
                                        if (!empty($state['article_id'])) {
                                            try {
                                                $article = \App\Models\RobawsArticleCache::find($state['article_id']);
                                                if ($article) {
                                                    $shortName = explode(',', $article->article_name ?? '')[0];
                                                    $shortName = strlen($shortName) > 30 ? substr($shortName, 0, 30) . '...' : $shortName;
                                                    return $shortName . ' Mapping';
                                                }
                                            } catch (\Throwable $e) {
                                                // Silent fail, fall through to default
                                            }
                                        }
                                        
                                        return 'New Freight Mapping';
                                    })
                                    ->helperText('When mappings match the quote context, only these mapped articles (and universal articles with empty commodity type) are shown. If no mappings exist, the system falls back to commodity type matching. Vehicle Categories and Category Groups are mutually exclusive - set one or the other, not both.')
                                    ->columnSpanFull(),
                            ]),

                        // Tab 9: Simulator
                        Forms\Components\Tabs\Tab::make('simulator')
                            ->label('Simulator')
                            ->icon('heroicon-o-beaker')
                            ->schema([
                                Forms\Components\Section::make('Cargo Input')
                                    ->schema([
                                        Forms\Components\Select::make('simulator_port_id')
                                            ->label('Port of Discharge (POD)')
                                            ->options(function () {
                                                return \App\Models\Port::where('type', 'pod')
                                                    ->orderBy('name')
                                                    ->pluck('name', 'id');
                                            })
                                            ->searchable()
                                            ->preload()
                                            ->columnSpan(1),

                                        Forms\Components\TextInput::make('simulator_vessel_name')
                                            ->label('Vessel Name')
                                            ->maxLength(100)
                                            ->columnSpan(1),

                                        Forms\Components\TextInput::make('simulator_vessel_class')
                                            ->label('Vessel Class')
                                            ->maxLength(50)
                                            ->columnSpan(1),

                                        Forms\Components\Select::make('simulator_vehicle_category')
                                            ->label('Vehicle Category')
                                            ->options(function () {
                                                $categories = config('quotation.commodity_types.vehicles.categories', []);
                                                return $categories;
                                            })
                                            ->searchable()
                                            ->columnSpan(1),

                                        Forms\Components\Select::make('simulator_category_group')
                                            ->label('Category Group (Quick Quote)')
                                            ->options(function ($record) {
                                                if (!$record) {
                                                    return [];
                                                }
                                                return \App\Models\CarrierCategoryGroup::where('carrier_id', $record->id)
                                                    ->pluck('display_name', 'code')
                                                    ->toArray();
                                            })
                                            ->searchable()
                                            ->columnSpan(1),

                                        Forms\Components\Section::make('Dimensions & Weight')
                                            ->schema([
                                                Forms\Components\TextInput::make('simulator_length_cm')
                                                    ->label('Length (cm)')
                                                    ->numeric()
                                                    ->step(0.1)
                                                    ->columnSpan(1),

                                                Forms\Components\TextInput::make('simulator_width_cm')
                                                    ->label('Width (cm)')
                                                    ->numeric()
                                                    ->step(0.1)
                                                    ->columnSpan(1),

                                                Forms\Components\TextInput::make('simulator_height_cm')
                                                    ->label('Height (cm)')
                                                    ->numeric()
                                                    ->step(0.1)
                                                    ->columnSpan(1),

                                                Forms\Components\TextInput::make('simulator_cbm')
                                                    ->label('CBM')
                                                    ->numeric()
                                                    ->step(0.1)
                                                    ->columnSpan(1),

                                                Forms\Components\TextInput::make('simulator_weight_kg')
                                                    ->label('Weight (kg)')
                                                    ->numeric()
                                                    ->step(0.1)
                                                    ->columnSpan(1),

                                                Forms\Components\TextInput::make('simulator_unit_count')
                                                    ->label('Unit Count')
                                                    ->numeric()
                                                    ->default(1)
                                                    ->columnSpan(1),
                                            ])
                                            ->columns(6)
                                            ->columnSpanFull(),

                                        Forms\Components\TextInput::make('simulator_basic_freight')
                                            ->label('Basic Freight Amount (for % calculations)')
                                            ->numeric()
                                            ->step(0.1)
                                            ->helperText('Optional: needed for percentage-based surcharges')
                                            ->columnSpanFull(),

                                        Forms\Components\CheckboxList::make('simulator_flags')
                                            ->label('Commodity Flags')
                                            ->options([
                                                'tank_truck' => 'Tank Truck',
                                                'non_self_propelled' => 'Non-Self-Propelled',
                                                'stacked' => 'Stacked',
                                                'piggy_back' => 'Piggy Back',
                                            ])
                                            ->columns(4)
                                            ->columnSpanFull(),

                                        Forms\Components\Placeholder::make('simulator_note')
                                            ->label('')
                                            ->content('Note: Simulator functionality will be implemented in the page component.')
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2)
                                    ->columnSpanFull(),

                                Forms\Components\Section::make('Simulation Results')
                                    ->schema([
                                        Forms\Components\Placeholder::make('simulator_results')
                                            ->label('Results')
                                            ->content('Click "Run Simulation" to see results')
                                            ->columnSpanFull(),
                                    ])
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Carrier')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('categoryGroups_count')
                    ->label('Category Groups')
                    ->counts('categoryGroups')
                    ->sortable(),

                Tables\Columns\TextColumn::make('acceptanceRules_count')
                    ->label('Acceptance Rules')
                    ->counts('acceptanceRules')
                    ->sortable(),

                Tables\Columns\TextColumn::make('surchargeRules_count')
                    ->label('Surcharge Rules')
                    ->counts('surchargeRules')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('is_active')
                    ->label('Status')
                    ->options([
                        1 => 'Active',
                        0 => 'Inactive',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
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
            'index' => Pages\ListCarrierRules::route('/'),
            'create' => Pages\CreateCarrierRule::route('/create'),
            'edit' => Pages\EditCarrierRule::route('/{record}/edit'),
            'view' => Pages\ViewCarrierRule::route('/{record}'),
        ];
    }
}

