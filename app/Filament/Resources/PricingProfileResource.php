<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PricingProfileResource\Pages;
use App\Models\PricingProfile;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PricingProfileResource extends Resource
{
    protected static ?string $model = PricingProfile::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    
    protected static ?string $navigationLabel = 'Margin Profiles';
    
    protected static ?string $modelLabel = 'Margin Profile';
    
    protected static ?string $pluralModelLabel = 'Margin Profiles';
    
    protected static ?string $navigationGroup = 'Quotation System';
    
    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Profile Configuration')
                    ->description('Configure margin profile settings')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Profile Name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g., Standard Margins, Premium Client Profile')
                            ->columnSpan(1),

                        Forms\Components\Select::make('currency')
                            ->label('Currency')
                            ->options([
                                'EUR' => 'EUR',
                                'USD' => 'USD',
                            ])
                            ->default('EUR')
                            ->required()
                            ->columnSpan(1),

                        Forms\Components\Select::make('carrier_id')
                            ->label('Carrier (Optional)')
                            ->relationship('carrier', 'name')
                            ->searchable()
                            ->preload()
                            ->helperText('Leave empty for global profile')
                            ->columnSpan(1),

                        Forms\Components\Select::make('robaws_client_id')
                            ->label('Customer (Optional)')
                            ->relationship('customer', 'name', fn ($query) => $query->active())
                            ->searchable()
                            ->preload()
                            ->getOptionLabelUsing(function ($value) {
                                if (!$value) return null;
                                try {
                                    $customer = \App\Models\RobawsCustomerCache::where('robaws_client_id', $value)->first();
                                    return $customer ? ($customer->name . ' (' . $customer->robaws_client_id . ')') : null;
                                } catch (\Throwable $e) {
                                    return null;
                                }
                            })
                            ->helperText('Leave empty for carrier-level or global profile')
                            ->columnSpan(1),

                        Forms\Components\DatePicker::make('effective_from')
                            ->label('Effective From')
                            ->helperText('Profile becomes active on this date')
                            ->columnSpan(1),

                        Forms\Components\DatePicker::make('effective_to')
                            ->label('Effective To')
                            ->helperText('Profile expires on this date')
                            ->columnSpan(1),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Only active profiles can be used')
                            ->columnSpan(1),

                        Forms\Components\Textarea::make('notes')
                            ->label('Notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Margin Rules')
                    ->description('Configure margin calculation rules. Rules are matched in priority order: exact match > category-only > basis-only > global.')
                    ->schema([
                        Forms\Components\Repeater::make('rules')
                            ->relationship('rules')
                            ->reorderable('priority')
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                Forms\Components\Select::make('vehicle_category')
                                    ->label('Vehicle Category')
                                    ->options([
                                        'CAR' => 'CAR',
                                        'SMALL_VAN' => 'SMALL_VAN',
                                        'BIG_VAN' => 'BIG_VAN',
                                        'VBV' => 'VBV',
                                        'LM' => 'LM',
                                    ])
                                    ->placeholder('All categories')
                                    ->helperText('Leave empty for global rule')
                                    ->columnSpan(1),

                                Forms\Components\Select::make('unit_basis')
                                    ->label('Unit Basis')
                                    ->options([
                                        'UNIT' => 'UNIT',
                                        'LM' => 'LM',
                                    ])
                                    ->default('UNIT')
                                    ->required()
                                    ->columnSpan(1),

                                Forms\Components\Select::make('margin_type')
                                    ->label('Margin Type')
                                    ->options([
                                        'FIXED' => 'FIXED (Fixed Amount)',
                                        'PERCENT' => 'PERCENT (Percentage)',
                                    ])
                                    ->default('FIXED')
                                    ->required()
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('margin_value')
                                    ->label('Margin Value')
                                    ->numeric()
                                    ->required()
                                    ->helperText('For FIXED: amount in currency. For PERCENT: percentage (e.g., 15 for 15%)')
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('priority')
                                    ->label('Priority')
                                    ->numeric()
                                    ->default(0)
                                    ->helperText('Higher priority = checked first (0 = first)')
                                    ->columnSpan(1),

                                Forms\Components\Toggle::make('is_active')
                                    ->label('Active')
                                    ->default(true)
                                    ->columnSpan(1),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->itemLabel(function (array $state): ?string {
                                $category = $state['vehicle_category'] ?? 'All';
                                $basis = $state['unit_basis'] ?? 'UNIT';
                                $type = $state['margin_type'] ?? 'FIXED';
                                $value = $state['margin_value'] ?? '0';
                                
                                return sprintf('%s / %s: %s %s', $category, $basis, $type, $value);
                            }),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('currency')
                    ->label('Currency')
                    ->sortable()
                    ->badge(),

                Tables\Columns\TextColumn::make('carrier.name')
                    ->label('Carrier')
                    ->sortable()
                    ->searchable()
                    ->placeholder('Global')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Customer')
                    ->sortable()
                    ->searchable()
                    ->placeholder('N/A')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('effective_from')
                    ->label('From')
                    ->date()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('effective_to')
                    ->label('To')
                    ->date()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('rules_count')
                    ->label('Rules')
                    ->counts('rules')
                    ->sortable()
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status')
                    ->placeholder('All profiles')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),

                Tables\Filters\SelectFilter::make('carrier_id')
                    ->label('Carrier')
                    ->relationship('carrier', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('currency')
                    ->label('Currency')
                    ->options([
                        'EUR' => 'EUR',
                        'USD' => 'USD',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalDescription('Warning: This will affect pricing calculations using this profile.'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ])
            ->defaultSort('name', 'asc');
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
            'index' => Pages\ListPricingProfiles::route('/'),
            'create' => Pages\CreatePricingProfile::route('/create'),
            'edit' => Pages\EditPricingProfile::route('/{record}/edit'),
        ];
    }
}
