<?php

namespace App\Filament\Resources\RobawsArticleResource\RelationManagers;

use App\Models\RobawsArticleCache;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CompositeItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'children';

    protected static ?string $title = 'Composite Items / Surcharges';
    
    protected static ?string $recordTitleAttribute = 'article_name';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('child_article_id')
                    ->label('Child Article')
                    ->options(function ($livewire) {
                        // Get parent article
                        $parent = $livewire->getOwnerRecord();
                        
                        // Filter to surcharge articles or articles not already children
                        $excludeIds = $parent->children()->pluck('robaws_articles_cache.id')->toArray();
                        
                        // Use database-agnostic case-insensitive matching
                        $useIlike = \Illuminate\Support\Facades\DB::getDriverName() === 'pgsql';
                        
                        return RobawsArticleCache::where(function ($query) use ($useIlike) {
                                $query->where('is_surcharge', true)
                                    ->orWhere(function ($subQ) use ($useIlike) {
                                        if ($useIlike) {
                                            $subQ->where('article_type', 'ILIKE', '%SURCHARGE%')
                                                ->orWhere('article_type', 'ILIKE', '%Surcharges%')
                                                ->orWhere('article_type', 'ILIKE', '%LOCAL CHARGES%')
                                                ->orWhere('article_type', 'ILIKE', '%Administrative%');
                                        } else {
                                            $subQ->whereRaw('LOWER(article_type) LIKE ?', ['%surcharge%'])
                                                ->orWhereRaw('LOWER(article_type) LIKE ?', ['%surcharges%'])
                                                ->orWhereRaw('LOWER(article_type) LIKE ?', ['%local charges%'])
                                                ->orWhereRaw('LOWER(article_type) LIKE ?', ['%administrative%']);
                                        }
                                    });
                            })
                            ->where('is_parent_article', false)
                            ->whereNotIn('id', $excludeIds)
                            ->get()
                            ->mapWithKeys(function ($article) {
                                return [$article->id => $article->article_code . ' - ' . $article->article_name];
                            });
                    })
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search) {
                        // Use database-agnostic case-insensitive matching
                        $useIlike = \Illuminate\Support\Facades\DB::getDriverName() === 'pgsql';
                        
                        return RobawsArticleCache::where(function ($query) use ($useIlike) {
                                $query->where('is_surcharge', true)
                                    ->orWhere(function ($subQ) use ($useIlike) {
                                        if ($useIlike) {
                                            $subQ->where('article_type', 'ILIKE', '%SURCHARGE%')
                                                ->orWhere('article_type', 'ILIKE', '%Surcharges%')
                                                ->orWhere('article_type', 'ILIKE', '%LOCAL CHARGES%')
                                                ->orWhere('article_type', 'ILIKE', '%Administrative%');
                                        } else {
                                            $subQ->whereRaw('LOWER(article_type) LIKE ?', ['%surcharge%'])
                                                ->orWhereRaw('LOWER(article_type) LIKE ?', ['%surcharges%'])
                                                ->orWhereRaw('LOWER(article_type) LIKE ?', ['%local charges%'])
                                                ->orWhereRaw('LOWER(article_type) LIKE ?', ['%administrative%']);
                                        }
                                    });
                            })
                            ->where('is_parent_article', false)
                            ->where(function ($query) use ($search, $useIlike) {
                                if ($useIlike) {
                                    $query->where('article_code', 'ILIKE', "%{$search}%")
                                        ->orWhere('article_name', 'ILIKE', "%{$search}%");
                                } else {
                                    $query->whereRaw('LOWER(article_code) LIKE ?', ['%' . strtolower($search) . '%'])
                                        ->orWhereRaw('LOWER(article_name) LIKE ?', ['%' . strtolower($search) . '%']);
                                }
                            })
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(function ($article) {
                                return [$article->id => $article->article_code . ' - ' . $article->article_name];
                            });
                    })
                    ->required()
                    ->columnSpan(2),
                    
                Forms\Components\TextInput::make('sort_order')
                    ->label('Sort Order')
                    ->numeric()
                    ->default(1)
                    ->required(),
                    
                Forms\Components\Select::make('cost_type')
                    ->label('Cost Type')
                    ->options([
                        'Material' => 'Material',
                        'Labor' => 'Labor',
                        'Service' => 'Service',
                        'Equipment' => 'Equipment',
                    ])
                    ->default('Material')
                    ->required(),
                    
                Forms\Components\TextInput::make('default_quantity')
                    ->label('Default Quantity')
                    ->numeric()
                    ->default(1.0)
                    ->step(0.01)
                    ->required(),
                    
                Forms\Components\TextInput::make('default_cost_price')
                    ->label('Default Cost Price')
                    ->numeric()
                    ->prefix('€')
                    ->step(0.01),
                    
                Forms\Components\TextInput::make('unit_type')
                    ->label('Unit Type')
                    ->maxLength(50)
                    ->placeholder('e.g., shipm., w/m, unit, lumps.'),
                    
                Forms\Components\Select::make('child_type')
                    ->label('Child Type')
                    ->options([
                        'mandatory' => 'Mandatory (Always Added)',
                        'optional' => 'Optional (Customer Chooses)',
                        'conditional' => 'Conditional (Auto-Added if Conditions Match)',
                    ])
                    ->default('optional')
                    ->required()
                    ->reactive()
                    ->helperText('Mandatory: Always added. Optional: Customer chooses. Conditional: Auto-added if conditions match.')
                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                        // Auto-update is_required and is_conditional based on child_type
                        // (These fields are kept for backward compatibility but are controlled by child_type)
                        if ($state === 'mandatory') {
                            $set('is_required', true);
                            $set('is_conditional', false);
                        } elseif ($state === 'conditional') {
                            $set('is_required', false);
                            $set('is_conditional', true);
                        } else { // optional
                            $set('is_required', false);
                            $set('is_conditional', false);
                        }
                    }),
                    
                Forms\Components\Textarea::make('conditions')
                    ->label('Conditions (JSON)')
                    ->helperText('JSON object defining when this item should be included. Example: {"commodity": ["EXCAVATOR"], "dimensions": {"width_m_gt": 2.50}, "route": {"pol": ["ANR"], "pod": ["CNSHA"]}}')
                    ->visible(fn (Forms\Get $get) => $get('child_type') === 'conditional')
                    ->columnSpan(2)
                    ->rows(4),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('article_name')
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable()
                    ->width('50px'),
                    
                Tables\Columns\TextColumn::make('article_name')
                    ->label('Article')
                    ->searchable()
                    ->sortable()
                    ->limit(50),
                    
                Tables\Columns\TextColumn::make('pivot.cost_type')
                    ->label('Cost Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Material' => 'success',
                        'Labor' => 'warning',
                        'Service' => 'info',
                        default => 'gray',
                    }),
                    
                Tables\Columns\TextColumn::make('pivot.unit_type')
                    ->label('Unit Type')
                    ->default('—')
                    ->formatStateUsing(function ($state, $record) {
                        $pivotUnitType = $state;
                        $childUnitType = $record->unit_type ?? null;
                        // Treat null, empty string, or the default em dash (—) as "no value"
                        // The em dash can appear as different characters, so check for common variants
                        $isEmpty = empty($pivotUnitType) || 
                                   $pivotUnitType === '—' || 
                                   $pivotUnitType === '—' || 
                                   $pivotUnitType === '-' ||
                                   trim($pivotUnitType) === '';
                        return !$isEmpty ? $pivotUnitType : ($childUnitType ?: '—');
                    }),
                    
                Tables\Columns\TextColumn::make('pivot.default_quantity')
                    ->label('Quantity')
                    ->numeric(decimalPlaces: 2),
                    
                Tables\Columns\TextColumn::make('pivot.default_cost_price')
                    ->label('Cost Price')
                    ->money('EUR')
                    ->default('—'),
                    
                Tables\Columns\TextColumn::make('pivot.child_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'mandatory' => 'danger',
                        'optional' => 'success',
                        'conditional' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'mandatory' => 'Mandatory',
                        'optional' => 'Optional',
                        'conditional' => 'Conditional',
                        default => $state,
                    }),
                    
                Tables\Columns\IconColumn::make('pivot.is_required')
                    ->label('Required')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray'),
                    
                Tables\Columns\IconColumn::make('pivot.is_conditional')
                    ->label('Conditional')
                    ->boolean()
                    ->trueIcon('heroicon-o-cog-6-tooth')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('warning')
                    ->falseColor('gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('child_type')
                    ->label('Child Type')
                    ->options([
                        'mandatory' => 'Mandatory',
                        'optional' => 'Optional',
                        'conditional' => 'Conditional',
                    ]),
                    
                Tables\Filters\SelectFilter::make('cost_type')
                    ->options([
                        'Material' => 'Material',
                        'Labor' => 'Labor',
                        'Service' => 'Service',
                        'Equipment' => 'Equipment',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\Action::make('attach')
                    ->label('Attach Child Article')
                    ->icon('heroicon-o-plus')
                    ->form([
                        Forms\Components\Select::make('child_article_id')
                            ->label('Child Article')
                            ->options(function () {
                                // #region agent log
                                $logPath = base_path('.cursor/debug.log');
                                if (file_exists(dirname($logPath)) || is_dir(dirname($logPath))) {
                                    @file_put_contents($logPath, json_encode(['id' => 'log_' . time() . '_' . uniqid(), 'timestamp' => time() * 1000, 'location' => 'CompositeItemsRelationManager.php:277', 'message' => 'child_article_id options() called', 'data' => ['hypothesisId' => 'A'], 'sessionId' => 'debug-session', 'runId' => 'run1']) . "\n", FILE_APPEND | LOCK_EX);
                                }
                                // #endregion
                                $parent = $this->getOwnerRecord();
                                $excludeIds = $parent->children()->pluck('robaws_articles_cache.id')->toArray();
                                
                                $useIlike = \Illuminate\Support\Facades\DB::getDriverName() === 'pgsql';
                                
                                return RobawsArticleCache::where(function ($query) use ($useIlike) {
                                        $query->where('is_surcharge', true)
                                            ->orWhere(function ($subQ) use ($useIlike) {
                                                if ($useIlike) {
                                                    $subQ->where('article_type', 'ILIKE', '%SURCHARGE%')
                                                        ->orWhere('article_type', 'ILIKE', '%Surcharges%')
                                                        ->orWhere('article_type', 'ILIKE', '%LOCAL CHARGES%')
                                                        ->orWhere('article_type', 'ILIKE', '%Administrative%');
                                                } else {
                                                    $subQ->whereRaw('LOWER(article_type) LIKE ?', ['%surcharge%'])
                                                        ->orWhereRaw('LOWER(article_type) LIKE ?', ['%surcharges%'])
                                                        ->orWhereRaw('LOWER(article_type) LIKE ?', ['%local charges%'])
                                                        ->orWhereRaw('LOWER(article_type) LIKE ?', ['%administrative%']);
                                                }
                                            });
                                    })
                                    ->where('is_parent_article', false)
                                    ->whereNotIn('id', $excludeIds)
                                    ->orderBy('article_code')
                                    ->get()
                                    ->mapWithKeys(function ($article) {
                                        return [$article->id => $article->article_code . ' - ' . $article->article_name];
                                    });
                            })
                            ->searchable()
                            ->required()
                            ->live(onBlur: false)
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                // #region agent log
                                $logPath = base_path('.cursor/debug.log');
                                if (file_exists(dirname($logPath)) || is_dir(dirname($logPath))) {
                                    file_put_contents($logPath, json_encode(['id' => 'log_' . time() . '_' . uniqid(), 'timestamp' => time() * 1000, 'location' => 'CompositeItemsRelationManager.php:313', 'message' => 'child_article_id afterStateUpdated called', 'data' => ['selectedId' => $state, 'env' => app()->environment(), 'hypothesisId' => 'B'], 'sessionId' => 'debug-session', 'runId' => 'run1']) . "\n", FILE_APPEND | LOCK_EX);
                                }
                                // #endregion
                                if ($state) {
                                    $childArticle = RobawsArticleCache::find($state);
                                    $unitType = $childArticle->unit_type ?? null;
                                    // #region agent log
                                    if (file_exists(dirname($logPath)) || is_dir(dirname($logPath))) {
                                        file_put_contents($logPath, json_encode(['id' => 'log_' . time() . '_' . uniqid(), 'timestamp' => time() * 1000, 'location' => 'CompositeItemsRelationManager.php:320', 'message' => 'Setting unit_type from child article', 'data' => ['articleId' => $state, 'unitType' => $unitType, 'articleCode' => $childArticle->article_code ?? null, 'env' => app()->environment(), 'hypothesisId' => 'B'], 'sessionId' => 'debug-session', 'runId' => 'run1']) . "\n", FILE_APPEND | LOCK_EX);
                                    }
                                    // #endregion
                                    if ($unitType) {
                                        $set('unit_type', $unitType);
                                    } else {
                                        // Clear unit_type if article has no unit_type
                                        $set('unit_type', null);
                                    }
                                } else {
                                    // Clear unit_type if no article selected
                                    $set('unit_type', null);
                                }
                            })
                            ->getSearchResultsUsing(function (string $search) {
                                $parent = $this->getOwnerRecord();
                                $excludeIds = $parent->children()->pluck('robaws_articles_cache.id')->toArray();
                                
                                $useIlike = \Illuminate\Support\Facades\DB::getDriverName() === 'pgsql';
                                
                                return RobawsArticleCache::where(function ($query) use ($useIlike) {
                                        $query->where('is_surcharge', true)
                                            ->orWhere(function ($subQ) use ($useIlike) {
                                                if ($useIlike) {
                                                    $subQ->where('article_type', 'ILIKE', '%SURCHARGE%')
                                                        ->orWhere('article_type', 'ILIKE', '%Surcharges%')
                                                        ->orWhere('article_type', 'ILIKE', '%LOCAL CHARGES%')
                                                        ->orWhere('article_type', 'ILIKE', '%Administrative%');
                                                } else {
                                                    $subQ->whereRaw('LOWER(article_type) LIKE ?', ['%surcharge%'])
                                                        ->orWhereRaw('LOWER(article_type) LIKE ?', ['%surcharges%'])
                                                        ->orWhereRaw('LOWER(article_type) LIKE ?', ['%local charges%'])
                                                        ->orWhereRaw('LOWER(article_type) LIKE ?', ['%administrative%']);
                                                }
                                            });
                                    })
                                    ->where('is_parent_article', false)
                                    ->whereNotIn('id', $excludeIds)
                                    ->where(function ($query) use ($search, $useIlike) {
                                        if ($useIlike) {
                                            $query->where('article_code', 'ILIKE', "%{$search}%")
                                                ->orWhere('article_name', 'ILIKE', "%{$search}%");
                                        } else {
                                            $query->whereRaw('LOWER(article_code) LIKE ?', ['%' . strtolower($search) . '%'])
                                                ->orWhereRaw('LOWER(article_name) LIKE ?', ['%' . strtolower($search) . '%']);
                                        }
                                    })
                                    ->orderBy('article_code')
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(function ($article) {
                                        return [$article->id => $article->article_code . ' - ' . $article->article_name];
                                    });
                            }),
                        Forms\Components\TextInput::make('sort_order')
                            ->label('Sort Order')
                            ->numeric()
                            ->default(fn () => $this->getOwnerRecord()->children()->count() + 1)
                            ->required(),
                        Forms\Components\Select::make('cost_type')
                            ->options([
                                'Material' => 'Material',
                                'Labor' => 'Labor',
                                'Service' => 'Service',
                                'Equipment' => 'Equipment',
                            ])
                            ->default('Material')
                            ->required(),
                        Forms\Components\TextInput::make('default_quantity')
                            ->label('Default Quantity')
                            ->numeric()
                            ->default(1.0)
                            ->step(0.01)
                            ->required(),
                        Forms\Components\TextInput::make('default_cost_price')
                            ->label('Default Cost Price')
                            ->numeric()
                            ->prefix('€')
                            ->step(0.01),
                        Forms\Components\TextInput::make('unit_type')
                            ->label('Unit Type')
                            ->maxLength(50)
                            ->default(function (Forms\Get $get) {
                                // #region agent log
                                $logPath = base_path('.cursor/debug.log');
                                $childArticleId = $get('child_article_id');
                                if (file_exists(dirname($logPath)) || is_dir(dirname($logPath))) {
                                    file_put_contents($logPath, json_encode(['id' => 'log_' . time() . '_' . uniqid(), 'timestamp' => time() * 1000, 'location' => 'CompositeItemsRelationManager.php:393', 'message' => 'unit_type default() called in attach form', 'data' => ['childArticleId' => $childArticleId, 'env' => app()->environment(), 'hypothesisId' => 'C'], 'sessionId' => 'debug-session', 'runId' => 'run1']) . "\n", FILE_APPEND | LOCK_EX);
                                }
                                // #endregion
                                if ($childArticleId) {
                                    $childArticle = RobawsArticleCache::find($childArticleId);
                                    $unitType = $childArticle->unit_type ?? null;
                                    // #region agent log
                                    if (file_exists(dirname($logPath)) || is_dir(dirname($logPath))) {
                                        file_put_contents($logPath, json_encode(['id' => 'log_' . time() . '_' . uniqid(), 'timestamp' => time() * 1000, 'location' => 'CompositeItemsRelationManager.php:400', 'message' => 'unit_type default() returning value in attach form', 'data' => ['unitType' => $unitType, 'env' => app()->environment(), 'hypothesisId' => 'C'], 'sessionId' => 'debug-session', 'runId' => 'run1']) . "\n", FILE_APPEND | LOCK_EX);
                                    }
                                    // #endregion
                                    return $unitType;
                                }
                                return null;
                            })
                            ->live(onBlur: false),
                        Forms\Components\Select::make('child_type')
                            ->label('Child Type')
                            ->options([
                                'mandatory' => 'Mandatory (Always Added)',
                                'optional' => 'Optional (Customer Chooses)',
                                'conditional' => 'Conditional (Auto-Added if Conditions Match)',
                            ])
                            ->default('optional')
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                if ($state === 'mandatory') {
                                    $set('is_required', true);
                                    $set('is_conditional', false);
                                } elseif ($state === 'conditional') {
                                    $set('is_required', false);
                                    $set('is_conditional', true);
                                } else {
                                    $set('is_required', false);
                                    $set('is_conditional', false);
                                }
                            }),
                        Forms\Components\Textarea::make('conditions')
                            ->label('Conditions (JSON)')
                            ->helperText('JSON object defining when this item should be included')
                            ->visible(fn (Forms\Get $get) => $get('child_type') === 'conditional')
                            ->rows(4),
                    ])
                    ->action(function (array $data) {
                        // #region agent log
                        $logPath = base_path('.cursor/debug.log');
                        if (file_exists(dirname($logPath)) || is_dir(dirname($logPath))) {
                            @file_put_contents($logPath, json_encode(['id' => 'log_' . time() . '_' . uniqid(), 'timestamp' => time() * 1000, 'location' => 'CompositeItemsRelationManager.php:405', 'message' => 'attach action called', 'data' => ['formData' => $data, 'hypothesisId' => 'D'], 'sessionId' => 'debug-session', 'runId' => 'run1']) . "\n", FILE_APPEND | LOCK_EX);
                        }
                        // #endregion
                        $parent = $this->getOwnerRecord();
                        
                        // Ensure child_type is set
                        $childType = $data['child_type'] ?? 'optional';
                        $isRequired = ($childType === 'mandatory');
                        $isConditional = ($childType === 'conditional');
                        
                        // Get child article to use its unit_type as fallback if form unit_type is not provided
                        $childArticle = RobawsArticleCache::find($data['child_article_id'] ?? null);
                        $childArticleUnitType = $childArticle->unit_type ?? null;
                        // #region agent log
                        $logPath = base_path('.cursor/debug.log');
                        if (file_exists(dirname($logPath)) || is_dir(dirname($logPath))) {
                            @file_put_contents($logPath, json_encode(['id' => 'log_' . time() . '_' . uniqid(), 'timestamp' => time() * 1000, 'location' => 'CompositeItemsRelationManager.php:416', 'message' => 'Child article unit_type retrieved', 'data' => ['formUnitType' => $data['unit_type'] ?? null, 'childArticleUnitType' => $childArticleUnitType, 'hypothesisId' => 'D'], 'sessionId' => 'debug-session', 'runId' => 'run1']) . "\n", FILE_APPEND | LOCK_EX);
                        }
                        // #endregion
                        
                        // Prepare pivot data
                        $pivotData = [
                            'sort_order' => $data['sort_order'] ?? ($parent->children()->count() + 1),
                            'cost_type' => $data['cost_type'] ?? 'Material',
                            'default_quantity' => $data['default_quantity'] ?? 1.0,
                            'default_cost_price' => $data['default_cost_price'] ?? null,
                            'unit_type' => $data['unit_type'] ?? $childArticleUnitType ?? null,
                            'child_type' => $childType,
                            'is_required' => $isRequired,
                            'is_conditional' => $isConditional,
                            'conditions' => !empty($data['conditions']) ? $data['conditions'] : null,
                        ];
                        
                        // Attach with all pivot data - use direct DB insert to avoid distinct issues
                        $parent->children()->attach($data['child_article_id'], $pivotData);
                    })
                    ->successNotificationTitle('Child article attached successfully'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->form(fn (Tables\Actions\EditAction $action): array => [
                        Forms\Components\TextInput::make('sort_order')
                            ->label('Sort Order')
                            ->numeric()
                            ->required(),
                        Forms\Components\Select::make('cost_type')
                            ->options([
                                'Material' => 'Material',
                                'Labor' => 'Labor',
                                'Service' => 'Service',
                                'Equipment' => 'Equipment',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('default_quantity')
                            ->label('Default Quantity')
                            ->numeric()
                            ->step(0.01)
                            ->required(),
                        Forms\Components\TextInput::make('default_cost_price')
                            ->label('Default Cost Price')
                            ->numeric()
                            ->prefix('€')
                            ->step(0.01),
                        Forms\Components\TextInput::make('unit_type')
                            ->label('Unit Type')
                            ->maxLength(50),
                        Forms\Components\Select::make('child_type')
                            ->label('Child Type')
                            ->options([
                                'mandatory' => 'Mandatory (Always Added)',
                                'optional' => 'Optional (Customer Chooses)',
                                'conditional' => 'Conditional (Auto-Added if Conditions Match)',
                            ])
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                // Auto-update is_required and is_conditional based on child_type
                                // (These fields are kept for backward compatibility but are controlled by child_type)
                                if ($state === 'mandatory') {
                                    $set('is_required', true);
                                    $set('is_conditional', false);
                                } elseif ($state === 'conditional') {
                                    $set('is_required', false);
                                    $set('is_conditional', true);
                                } else {
                                    $set('is_required', false);
                                    $set('is_conditional', false);
                                }
                            }),
                        Forms\Components\Textarea::make('conditions')
                            ->label('Conditions (JSON)')
                            ->helperText('JSON object defining when this item should be included')
                            ->visible(fn (Forms\Get $get) => $get('child_type') === 'conditional')
                            ->rows(4),
                    ]),
                Tables\Actions\DetachAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort_order', 'asc')
            ->reorderable('sort_order')
            ->description('Manage composite items and surcharges that are automatically included when this article is selected in an offer');
    }
}

