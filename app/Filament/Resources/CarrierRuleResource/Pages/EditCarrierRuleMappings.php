<?php

namespace App\Filament\Resources\CarrierRuleResource\Pages;

use App\Filament\Resources\CarrierRuleResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCarrierRuleMappings extends EditRecord
{
    protected static string $resource = CarrierRuleResource::class;

    protected static string $view = 'filament.resources.carrier-rule-resource.pages.edit-carrier-rule-mappings';

    /**
     * Mapping ID to focus when page loads (from query parameter)
     */
    public ?int $focusMappingId = null;

    /**
     * Temporary storage for article mappings order (for sort_order updates)
     */
    protected ?array $articleMappingsOrder = null;

    /**
     * Override mount to track record loading and handle deep linking
     */
    public function mount(int | string $record): void
    {
        parent::mount($record);

        // Read mapping_id from query parameter for deep linking
        $mappingIdParam = request()->input('mapping_id')
            ?? request()->get('mapping_id')
            ?? request()->query('mapping_id')
            ?? (isset($_GET['mapping_id']) ? $_GET['mapping_id'] : null);

        // If still null, try parsing from request URI (handle URL encoding)
        if (!$mappingIdParam) {
            $requestUri = urldecode(request()->getRequestUri());
            if (preg_match('/[?&]mapping_id=(\d+)/', $requestUri, $matches)) {
                $mappingIdParam = $matches[1];
            }
        }

        $this->focusMappingId = $mappingIdParam ? (int) $mappingIdParam : null;
    }

    /**
     * Override to add conditional eager loading for article mappings
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if ($this->record) {
            // Get mapping_id from query parameter (try multiple methods)
            $mappingId = request()->input('mapping_id')
                ?? request()->get('mapping_id')
                ?? request()->query('mapping_id')
                ?? (isset($_GET['mapping_id']) ? $_GET['mapping_id'] : null);

            // If still null, try parsing from request URI
            if (!$mappingId) {
                $requestUri = urldecode(request()->getRequestUri());
                if (preg_match('/[?&]mapping_id=(\d+)/', $requestUri, $matches)) {
                    $mappingId = $matches[1];
                }
            }

            // Check if port_code and category are provided (for creating new mappings)
            $portCode = request()->input('port_code')
                ?? request()->get('port_code')
                ?? request()->query('port_code');
            $category = request()->input('category')
                ?? request()->get('category')
                ?? request()->query('category');

            // Always load article mappings for this dedicated page
            $this->record->load([
                'articleMappings' => function ($query) {
                    $query->orderBy('sort_order', 'asc');
                    // Do NOT load purchaseTariffs to keep it lightweight
                }
            ]);

            // Store port_code and category for potential use in the form
            if ($portCode || $category) {
                $data['_create_mapping_port_code'] = $portCode;
                $data['_create_mapping_category'] = $category;
            }
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('backToCarrierRules')
                ->label('Back to Carrier Rules')
                ->icon('heroicon-o-arrow-left')
                ->url(fn () => static::getResource()::getUrl('edit', ['record' => $this->record]))
                ->color('gray'),
            Actions\Action::make('sortArticleMappingsByPort')
                ->label('Sort Freight Mappings')
                ->icon('heroicon-o-arrows-up-down')
                ->color('gray')
                ->action(function () {
                    $this->sortArticleMappingsByPort();
                }),
        ];
    }

    /**
     * Get form state data for a given field
     * Always gets fresh state using reflection to ensure we have the latest order
     */
    protected function getFormStateData(string $fieldName): ?array
    {
        try {
            $formReflection = new \ReflectionClass($this->form);
            if ($formReflection->hasProperty('state')) {
                $stateProperty = $formReflection->getProperty('state');
                $stateProperty->setAccessible(true);
                $formState = $stateProperty->getValue($this->form);

                if (isset($formState[$fieldName]) && is_array($formState[$fieldName])) {
                    return $formState[$fieldName];
                }
            }
        } catch (\Exception $e) {
            // Reflection failed, try alternative approach
        }

        // Fallback: use data property (but this might be stale)
        if (isset($this->data[$fieldName]) && is_array($this->data[$fieldName])) {
            return $this->data[$fieldName];
        }

        return null;
    }

    protected function beforeSave(): void
    {
        // Get form state data for article mappings
        $articleMappingsData = $this->getFormStateData('articleMappings');

        // Capture article mappings order
        if ($articleMappingsData && is_array($articleMappingsData)) {
            $articleMappingsOrder = [];
            $orderedMappings = array_values($articleMappingsData);

            foreach ($orderedMappings as $index => $mapping) {
                $mappingArray = is_array($mapping) ? $mapping : (array) $mapping;
                if (isset($mappingArray['id'])) {
                    $articleMappingsOrder[] = [
                        'id' => $mappingArray['id'],
                        'sort_order' => $index + 1,
                    ];
                }
            }

            $this->articleMappingsOrder = $articleMappingsOrder;
        }
    }

    protected function afterSave(): void
    {
        // Update article mappings sort_order based on form state order
        if ($this->articleMappingsOrder && is_array($this->articleMappingsOrder) && count($this->articleMappingsOrder) > 0) {
            $mappingIds = array_column($this->articleMappingsOrder, 'id');
            $mappings = \App\Models\CarrierArticleMapping::whereIn('id', $mappingIds)->get()->keyBy('id');

            foreach ($this->articleMappingsOrder as $orderData) {
                $mapping = $mappings->get($orderData['id']);
                if ($mapping && $mapping->sort_order != $orderData['sort_order']) {
                    $mapping->sort_order = $orderData['sort_order'];
                    $mapping->save();
                }
            }
        }
    }

    /**
     * Sort article mappings (freight mappings) alphabetically by port name
     */
    public function sortArticleMappingsByPort(): void
    {
        $items = $this->record->articleMappings()->with('article')->orderBy('sort_order')->get();

        $sorted = $items->sortBy(function ($item) {
            // Get the first port ID from port_ids array
            $portIds = $item->port_ids ?? [];
            if (empty($portIds)) {
                return 'ZZZ'; // Items without ports go to end
            }

            // Get the first port name
            $port = \App\Models\Port::find($portIds[0]);
            return $port ? $port->name : 'ZZZ';
        }, SORT_NATURAL | SORT_FLAG_CASE)->values();

        foreach ($sorted as $index => $item) {
            $item->sort_order = $index + 1;
            $item->save();
        }

        Notification::make()
            ->title('Freight Mappings sorted by port name')
            ->success()
            ->send();

        // Redirect to refresh the form with updated sort order
        $this->redirect($this->getResource()::getUrl('edit-mappings', ['record' => $this->record]));
    }

    /**
     * Override form to show only Article Mappings
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Actions')
                    ->schema([
                        Forms\Components\Placeholder::make('sort_info')
                            ->label('')
                            ->content(new \Illuminate\Support\HtmlString('
                                <div class="flex justify-end mb-4">
                                    <p class="text-sm text-gray-600 dark:text-gray-400">
                                        Use the "Sort Freight Mappings" button in the page header to sort by port name.
                                    </p>
                                </div>
                            '))
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->columnSpanFull(),

                Forms\Components\Repeater::make('articleMappings')
                    ->relationship('articleMappings', modifyQueryUsing: function ($query) {
                        // Load all mappings (no limit)
                        return $query->orderBy('sort_order', 'asc');
                    })
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
                        // Anchor for deep linking - renders data-mapping-id attribute
                        Forms\Components\Placeholder::make('mapping_anchor')
                            ->content('')
                            ->extraAttributes(fn ($record) => $record?->id ? ['data-mapping-id' => (string) $record->id] : [])
                            ->dehydrated(false)
                            ->columnSpanFull()
                            ->hiddenLabel(),

                        Forms\Components\Select::make('article_id')
                            ->label('Article')
                            ->options(function ($livewire) {
                                $carrierId = null;
                                
                                // Get carrier_id from livewire record (parent carrier)
                                if (isset($livewire) && is_object($livewire) && method_exists($livewire, 'getRecord')) {
                                    try {
                                        $record = $livewire->getRecord();
                                        $carrierId = $record ? $record->id : null;
                                    } catch (\Throwable $e) {
                                        // Silent fail, will return all articles if carrier not found
                                    }
                                }
                                
                                // Build query
                                $query = \App\Models\RobawsArticleCache::query()
                                    ->where('is_parent_item', true)
                                    ->where('is_active', true);
                                
                                // Filter articles by carrier to prevent wrong carrier mappings
                                // Universal articles (null shipping_carrier_id) can be mapped to any carrier
                                if ($carrierId) {
                                    $query->where(function ($q) use ($carrierId) {
                                        $q->where('shipping_carrier_id', $carrierId)
                                          ->orWhereNull('shipping_carrier_id'); // Allow universal articles
                                    });
                                }
                                
                                return $query->orderBy('article_name')
                                    ->get()
                                    ->mapWithKeys(function ($article) {
                                        return [$article->id => $article->article_name . ' (' . ($article->article_code ?? 'N/A') . ')'];
                                    });
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
                            ->searchable()
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
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search) {
                                $searchLower = strtolower($search);
                                $ports = \App\Models\Port::where(function($q) use ($searchLower) {
                                    $q->whereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"])
                                      ->orWhereRaw('LOWER(code) LIKE ?', ["%{$searchLower}%"]);
                                })
                                ->orWhereHas('aliases', function($q) use ($searchLower) {
                                    $q->where('alias_normalized', 'LIKE', "%{$searchLower}%")
                                      ->where('is_active', true);
                                })
                                ->orderBy('name')
                                ->limit(50)
                                ->get()
                                ->unique('id')
                                ->mapWithKeys(function ($port) {
                                    return [$port->id => $port->formatFull()];
                                });
                                
                                return $ports->all();
                            })
                            ->getOptionLabelsUsing(function (array $values): array {
                                // Only load ports when displaying already-selected values
                                if (empty($values)) {
                                    return [];
                                }
                                
                                return \App\Models\Port::whereIn('id', $values)
                                    ->get()
                                    ->mapWithKeys(function ($port) {
                                        return [$port->id => $port->formatFull()];
                                    })
                                    ->toArray();
                            })
                            ->multiple()
                            ->helperText('Select one or more ports. Leave empty for global rule. Search includes aliases.')
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
                            ->helperText('Select port groups (e.g., WAF, MED). If both Ports and Port Groups are set, rule matches if either matches.')
                            ->columnSpan(1),

                        Forms\Components\Select::make('vehicle_categories')
                            ->label('Vehicle Categories')
                            ->options(function () {
                                return config('quotation.commodity_types.vehicles.categories', []);
                            })
                            ->searchable()
                            ->multiple()
                            ->helperText('Select one or more vehicle categories. Leave empty for all categories. Note: If Category Group is selected, this field should be empty.')
                            ->columnSpan(1)
                            ->disabled(fn ($get) => !empty($get('category_group_ids')) && empty($get('vehicle_categories')))
                            ->live(),

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
                            ->helperText('OR use one or more category groups. Note: If Vehicle Categories are selected, this field should be empty.')
                            ->columnSpan(1)
                            ->disabled(fn ($get) => !empty($get('vehicle_categories')) && empty($get('category_group_ids')))
                            ->live(),

                        Forms\Components\TagsInput::make('vessel_names')
                            ->label('Vessel Names')
                            ->helperText('Enter vessel names (one per line or comma-separated). Leave empty for all vessels.')
                            ->columnSpan(1),

                        Forms\Components\TagsInput::make('vessel_classes')
                            ->label('Vessel Classes')
                            ->helperText('Enter vessel classes (one per line or comma-separated). Leave empty for all vessel classes.')
                            ->columnSpan(1),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->columnSpan(1),
                    ])
                    ->label('Freight Mappings')
                    ->defaultItems(0)
                    ->itemLabel(function (array $state): ?string {
                        // Use the name field directly - no database queries to avoid N+1 and memory issues
                        if (!empty($state['name'])) {
                            return $state['name'];
                        }
                        
                        // Fallback: use article_id if name is not set (should rarely happen)
                        if (!empty($state['article_id'])) {
                            return 'Article #' . $state['article_id'] . ' Mapping';
                        }
                        
                        return 'New Freight Mapping';
                    })
                    ->helperText('When mappings match the quote context, only these mapped articles (and universal articles with empty commodity type) are shown. If no mappings exist, the system falls back to commodity type matching. Vehicle Categories and Category Groups are mutually exclusive - set one or the other, not both.')
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Get view data - pass focusMappingId to view
     */
    public function getViewData(): array
    {
        return array_merge(parent::getViewData() ?? [], [
            'focusMappingId' => $this->focusMappingId,
        ]);
    }
}
