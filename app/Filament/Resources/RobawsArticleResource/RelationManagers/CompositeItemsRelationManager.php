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
                    ->options(RobawsArticleCache::all()->pluck('article_name', 'id'))
                    ->searchable()
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
                    
                Forms\Components\Toggle::make('is_required')
                    ->label('Required')
                    ->default(true)
                    ->helperText('If enabled, this item will always be included'),
                    
                Forms\Components\Toggle::make('is_conditional')
                    ->label('Conditional')
                    ->default(false)
                    ->helperText('If enabled, this item will only be included under certain conditions'),
                    
                Forms\Components\Textarea::make('conditions')
                    ->label('Conditions (JSON)')
                    ->helperText('JSON object defining when this item should be included')
                    ->visible(fn (Forms\Get $get) => $get('is_conditional'))
                    ->columnSpan(2),
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
                    ->default('—'),
                    
                Tables\Columns\TextColumn::make('pivot.default_quantity')
                    ->label('Quantity')
                    ->numeric(decimalPlaces: 2),
                    
                Tables\Columns\TextColumn::make('pivot.default_cost_price')
                    ->label('Cost Price')
                    ->money('EUR')
                    ->default('—'),
                    
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
                Tables\Filters\SelectFilter::make('cost_type')
                    ->options([
                        'Material' => 'Material',
                        'Labor' => 'Labor',
                        'Service' => 'Service',
                        'Equipment' => 'Equipment',
                    ]),
                    
                Tables\Filters\TernaryFilter::make('is_required')
                    ->label('Required Items')
                    ->placeholder('All items')
                    ->trueLabel('Required only')
                    ->falseLabel('Optional only'),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->preloadRecordSelect()
                    ->form(fn (Tables\Actions\AttachAction $action): array => [
                        $action->getRecordSelect(),
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
                            ->maxLength(50),
                        Forms\Components\Toggle::make('is_required')
                            ->label('Required')
                            ->default(true),
                        Forms\Components\Toggle::make('is_conditional')
                            ->label('Conditional')
                            ->default(false),
                    ]),
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
                        Forms\Components\Toggle::make('is_required')
                            ->label('Required'),
                        Forms\Components\Toggle::make('is_conditional')
                            ->label('Conditional'),
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

