<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PricingTierResource\Pages;
use App\Models\PricingTier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PricingTierResource extends Resource
{
    protected static ?string $model = PricingTier::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    
    protected static ?string $navigationLabel = 'Pricing Tiers';
    
    protected static ?string $modelLabel = 'Pricing Tier';
    
    protected static ?string $pluralModelLabel = 'Pricing Tiers';
    
    protected static ?string $navigationGroup = 'Quotation System';
    
    protected static ?int $navigationSort = 15;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Tier Configuration')
                    ->description('Configure pricing tier settings - margins can be negative (discount) or positive (markup)')
                    ->schema([
                        Forms\Components\Select::make('code')
                            ->label('Tier Code')
                            ->options([
                                'A' => 'Tier A',
                                'B' => 'Tier B',
                                'C' => 'Tier C',
                                'D' => 'Tier D',
                                'E' => 'Tier E',
                            ])
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->helperText('Single letter code for the tier')
                            ->columnSpan(1),
                            
                        Forms\Components\TextInput::make('name')
                            ->label('Tier Name')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('e.g., Best Price, Premium Price')
                            ->helperText('Display name shown to users')
                            ->columnSpan(1),
                            
                        Forms\Components\Textarea::make('description')
                            ->label('Description & Usage Guidelines')
                            ->rows(3)
                            ->placeholder('Who should get this pricing tier? When to use it?')
                            ->helperText('Internal guidelines for when to apply this tier')
                            ->columnSpanFull(),
                            
                        Forms\Components\TextInput::make('margin_percentage')
                            ->label('Margin Percentage')
                            ->required()
                            ->numeric()
                            ->suffix('%')
                            ->minValue(-50)
                            ->maxValue(100)
                            ->step(0.5)
                            ->default(15)
                            ->helperText('Positive % = markup (add to base price), Negative % = discount (subtract from base price). Example: -5% = 5% discount, +15% = 15% markup')
                            ->placeholder('e.g., -5.00 for 5% discount, 15.00 for 15% markup')
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                // Auto-update description based on margin type
                                if ($state < 0) {
                                    $set('_margin_type_indicator', 'This tier applies a DISCOUNT');
                                } elseif ($state > 0) {
                                    $set('_margin_type_indicator', 'This tier applies a MARKUP');
                                } else {
                                    $set('_margin_type_indicator', 'This tier is PASS-THROUGH (no margin)');
                                }
                            })
                            ->columnSpan(1),
                            
                        Forms\Components\Placeholder::make('_margin_type_indicator')
                            ->label('Margin Type')
                            ->content(function ($get) {
                                $margin = $get('margin_percentage');
                                if ($margin === null) return 'Enter margin percentage';
                                if ($margin < 0) return '💚 DISCOUNT - Selling price will be LOWER than Robaws base price';
                                if ($margin > 0) return '📈 MARKUP - Selling price will be HIGHER than Robaws base price';
                                return '➖ PASS-THROUGH - Selling price equals Robaws base price';
                            })
                            ->columnSpan(1),
                            
                        Forms\Components\Placeholder::make('_pricing_example')
                            ->label('Pricing Example')
                            ->content(function ($get) {
                                $margin = $get('margin_percentage');
                                if ($margin === null) return 'Enter margin percentage to see example';
                                
                                $basePrice = 1000; // Example base price
                                $multiplier = 1 + ($margin / 100);
                                $sellingPrice = $basePrice * $multiplier;
                                
                                return sprintf(
                                    'Base Price: €%s → Selling Price: €%s (margin: %s%s)',
                                    number_format($basePrice, 2),
                                    number_format($sellingPrice, 2),
                                    $margin > 0 ? '+' : '',
                                    number_format($margin, 2) . '%'
                                );
                            })
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                    
                Forms\Components\Section::make('Display Settings')
                    ->description('Visual appearance in admin panel and customer portal')
                    ->schema([
                        Forms\Components\Select::make('color')
                            ->label('Badge Color')
                            ->options([
                                'green' => '🟢 Green (Best / Discount)',
                                'yellow' => '🟡 Yellow (Medium)',
                                'orange' => '🟠 Orange',
                                'red' => '🔴 Red (Expensive / High Margin)',
                                'blue' => '🔵 Blue',
                                'gray' => '⚫ Gray',
                            ])
                            ->required()
                            ->default('gray')
                            ->helperText('Color used for badges and visual indicators')
                            ->columnSpan(1),
                            
                        Forms\Components\TextInput::make('icon')
                            ->label('Icon / Emoji')
                            ->maxLength(10)
                            ->placeholder('🟢')
                            ->helperText('Emoji shown in dropdowns and tables')
                            ->columnSpan(1),
                            
                        Forms\Components\TextInput::make('sort_order')
                            ->label('Sort Order')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Lower numbers appear first in dropdowns (0 = first)')
                            ->columnSpan(1),
                            
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Only active tiers can be selected for new quotations')
                            ->inline(false)
                            ->columnSpan(1),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->sortable()
                    ->searchable()
                    ->badge()
                    ->color(fn ($record) => match($record->color) {
                        'green' => 'success',
                        'yellow' => 'warning',
                        'orange' => 'warning',
                        'red' => 'danger',
                        'blue' => 'info',
                        default => 'gray'
                    })
                    ->formatStateUsing(fn ($state, $record) => ($record->icon ?? '') . ' ' . $state)
                    ->weight('bold'),
                    
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->description(fn ($record) => $record->description ? 
                        \Illuminate\Support\Str::limit($record->description, 60) : null
                    )
                    ->wrap(),
                    
                Tables\Columns\TextColumn::make('margin_percentage')
                    ->label('Margin')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => 
                        ($state > 0 ? '+' : '') . number_format($state, 2) . '%'
                    )
                    ->badge()
                    ->color(fn ($state) => match(true) {
                        $state < 0 => 'success',  // Discount = green
                        $state == 0 => 'gray',    // Pass-through = gray
                        $state <= 15 => 'warning', // Low markup = yellow
                        default => 'danger'       // High markup = red
                    })
                    ->weight('bold')
                    ->description(fn ($record) => 
                        $record->is_discount ? '💚 Discount' : 
                        ($record->is_markup ? '📈 Markup' : '➖ Pass-through')
                    ),
                    
                Tables\Columns\TextColumn::make('_example_pricing')
                    ->label('Example (€1000 base)')
                    ->getStateUsing(function ($record) {
                        $basePrice = 1000;
                        $sellingPrice = $record->calculateSellingPrice($basePrice);
                        return '€' . number_format($sellingPrice, 2);
                    })
                    ->description(fn ($record) => 
                        $record->is_discount ? '↓ Lower than base' : 
                        ($record->is_markup ? '↑ Higher than base' : '= Same as base')
                    )
                    ->color(fn ($record) => 
                        $record->is_discount ? 'success' : 
                        ($record->is_markup ? 'warning' : 'gray')
                    ),
                    
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Order')
                    ->sortable()
                    ->toggleable(),
                    
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                    
                Tables\Columns\TextColumn::make('quotation_requests_count')
                    ->label('Quotations')
                    ->counts('quotationRequests')
                    ->sortable()
                    ->toggleable()
                    ->description('Number of quotations using this tier'),
                    
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->toggleable(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status')
                    ->placeholder('All tiers')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),
                    
                Tables\Filters\Filter::make('margin_type')
                    ->label('Margin Type')
                    ->form([
                        Forms\Components\Select::make('margin_type')
                            ->label('Type')
                            ->options([
                                'discount' => 'Discounts only (negative %)',
                                'markup' => 'Markups only (positive %)',
                                'passthrough' => 'Pass-through only (0%)',
                            ]),
                    ])
                    ->query(function ($query, array $data) {
                        return $query->when(
                            $data['margin_type'] === 'discount',
                            fn ($q) => $q->where('margin_percentage', '<', 0)
                        )->when(
                            $data['margin_type'] === 'markup',
                            fn ($q) => $q->where('margin_percentage', '>', 0)
                        )->when(
                            $data['margin_type'] === 'passthrough',
                            fn ($q) => $q->where('margin_percentage', '=', 0)
                        );
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('preview_pricing')
                    ->label('Preview Pricing')
                    ->icon('heroicon-o-calculator')
                    ->color('info')
                    ->modalHeading(fn ($record) => 'Pricing Preview for ' . $record->name)
                    ->modalContent(function ($record) {
                        $examples = [
                            ['base' => 100, 'label' => 'Small fee'],
                            ['base' => 500, 'label' => 'Medium service'],
                            ['base' => 1000, 'label' => 'Large service'],
                            ['base' => 5000, 'label' => 'Premium service'],
                        ];
                        
                        $html = '<div class="space-y-3">';
                        foreach ($examples as $example) {
                            $selling = $record->calculateSellingPrice($example['base']);
                            $diff = $selling - $example['base'];
                            $html .= sprintf(
                                '<div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                                    <span class="text-sm text-gray-700 dark:text-gray-300">%s</span>
                                    <div class="text-right">
                                        <div class="text-sm font-medium">€%s → €%s</div>
                                        <div class="text-xs text-gray-500">%s €%s</div>
                                    </div>
                                </div>',
                                $example['label'],
                                number_format($example['base'], 2),
                                number_format($selling, 2),
                                $diff < 0 ? 'Save' : 'Add',
                                number_format(abs($diff), 2)
                            );
                        }
                        $html .= '</div>';
                        
                        return new \Illuminate\Support\HtmlString($html);
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('toggle_active')
                        ->label('Toggle Active Status')
                        ->icon('heroicon-o-arrow-path')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $records->each(function ($tier) {
                                $tier->update(['is_active' => !$tier->is_active]);
                            });
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation(),
                        
                    Tables\Actions\DeleteBulkAction::make()
                        ->requiresConfirmation()
                        ->modalDescription('Warning: Deleting tiers will affect quotations using them. Consider marking as inactive instead.'),
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
            'index' => Pages\ListPricingTiers::route('/'),
            'create' => Pages\CreatePricingTier::route('/create'),
            'edit' => Pages\EditPricingTier::route('/{record}/edit'),
        ];
    }
    
    public static function getNavigationBadge(): ?string
    {
        $activeCount = PricingTier::where('is_active', true)->count();
        return $activeCount > 0 ? (string) $activeCount : null;
    }
    
    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }
}

