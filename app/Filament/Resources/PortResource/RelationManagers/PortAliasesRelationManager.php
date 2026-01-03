<?php

namespace App\Filament\Resources\PortResource\RelationManagers;

use App\Models\PortAlias;
use App\Support\PortAliasNormalizer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\ValidationException;

class PortAliasesRelationManager extends RelationManager
{
    protected static string $relationship = 'aliases';

    protected static ?string $title = 'Port Aliases';

    protected static ?string $modelLabel = 'Alias';

    protected static ?string $pluralModelLabel = 'Aliases';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('alias')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Alias variant (name, code, typo, etc.)'),

                Forms\Components\Select::make('alias_type')
                    ->options([
                        'name_variant' => 'Name Variant',
                        'code_variant' => 'Code Variant',
                        'typo' => 'Typo',
                        'unlocode' => 'UN/LOCODE',
                        'combined' => 'Combined (for reference only)',
                    ])
                    ->default('name_variant'),

                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('alias')
            ->columns([
                Tables\Columns\TextColumn::make('alias')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('alias_normalized')
                    ->label('Normalized')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('alias_type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'name_variant' => 'info',
                        'code_variant' => 'success',
                        'typo' => 'warning',
                        'unlocode' => 'primary',
                        'combined' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active')
                    ->placeholder('All')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),

                Tables\Filters\SelectFilter::make('alias_type')
                    ->options([
                        'name_variant' => 'Name Variant',
                        'code_variant' => 'Code Variant',
                        'typo' => 'Typo',
                        'unlocode' => 'UN/LOCODE',
                        'combined' => 'Combined',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['alias_normalized'] = PortAliasNormalizer::normalize($data['alias']);
                        return $data;
                    })
                    ->using(function (array $data, $livewire): PortAlias {
                        $data['port_id'] = $livewire->ownerRecord->id;
                        $data['alias_normalized'] = PortAliasNormalizer::normalize($data['alias']);

                        // Check for existing alias with same normalized value
                        $existing = PortAlias::where('alias_normalized', $data['alias_normalized'])->first();
                        if ($existing) {
                            throw ValidationException::withMessages([
                                'alias' => "This alias conflicts with an existing alias '{$existing->alias}' for port '{$existing->port->name} ({$existing->port->code})'."
                            ]);
                        }

                        return PortAlias::create($data);
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['alias_normalized'] = PortAliasNormalizer::normalize($data['alias']);
                        return $data;
                    })
                    ->using(function (PortAlias $record, array $data): PortAlias {
                        $data['alias_normalized'] = PortAliasNormalizer::normalize($data['alias']);

                        // Check for existing alias with same normalized value (excluding current record)
                        $existing = PortAlias::where('alias_normalized', $data['alias_normalized'])
                            ->where('id', '!=', $record->id)
                            ->first();
                        if ($existing) {
                            throw ValidationException::withMessages([
                                'alias' => "This alias conflicts with an existing alias '{$existing->alias}' for port '{$existing->port->name} ({$existing->port->code})'."
                            ]);
                        }

                        $record->update($data);
                        return $record;
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}

