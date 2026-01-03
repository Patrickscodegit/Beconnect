<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PortResource\Pages;
use App\Filament\Resources\PortResource\RelationManagers;
use App\Models\Port;
use Filament\Forms;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Filament\Notifications\Notification;

class PortResource extends Resource
{
    protected static ?string $model = Port::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationLabel = 'Ports';

    protected static ?string $modelLabel = 'Port';

    protected static ?string $pluralModelLabel = 'Ports';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 10;

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::query()
            ->where(function ($query) {
                $query->where('port_category', 'UNKNOWN')
                    ->orWhereNull('country_code')
                    ->orWhere('country_code', '');
            })
            ->count();
    }

    /**
     * Truncate command output for notifications
     */
    private static function truncateOutput(string $output, int $max = 1500): string
    {
        $output = trim($output);
        if (strlen($output) > $max) {
            return substr($output, 0, $max) . "\n\n...(truncated)";
        }
        return $output;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Basic Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),

                        Forms\Components\TextInput::make('code')
                            ->required()
                            ->maxLength(10)
                            ->unique(ignoreRecord: true)
                            ->disabled(fn ($record) => $record && $record->isReferenced())
                            ->helperText(fn ($record) => $record && $record->isReferenced() 
                                ? 'Locked because referenced by schedules/mappings (and/or Robaws cache).' 
                                : null)
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('country')
                            ->maxLength(255)
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('country_code')
                            ->label('Country Code')
                            ->maxLength(2)
                            ->helperText('ISO 3166-1 alpha-2 country code (e.g., BE, FR, US)')
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('region')
                            ->maxLength(100)
                            ->columnSpan(1),

                        Forms\Components\Select::make('port_category')
                            ->label('Port Category')
                            ->options([
                                'SEA_PORT' => 'Sea Port',
                                'AIRPORT' => 'Airport',
                                'ICD' => 'Inland Container Depot',
                                'UNKNOWN' => 'Unknown',
                            ])
                            ->default('UNKNOWN')
                            ->required()
                            ->reactive()
                            ->columnSpan(1),

                        Forms\Components\Select::make('type')
                            ->label('Port Type')
                            ->options([
                                'pol' => 'POL (Port of Loading)',
                                'pod' => 'POD (Port of Discharge)',
                                'both' => 'Both',
                            ])
                            ->default('both')
                            ->required()
                            ->columnSpan(1),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->columnSpan(1),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Standardization Codes')
                    ->schema([
                        Forms\Components\TextInput::make('unlocode')
                            ->label('UN/LOCODE')
                            ->maxLength(5)
                            ->helperText('UN/LOCODE recommended for seaports')
                            ->visible(fn (Forms\Get $get) => $get('port_category') === 'SEA_PORT')
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('iata_code')
                            ->label('IATA Code')
                            ->maxLength(3)
                            ->helperText('IATA recommended for airports')
                            ->visible(fn (Forms\Get $get) => $get('port_category') === 'AIRPORT')
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('icao_code')
                            ->label('ICAO Code')
                            ->maxLength(4)
                            ->helperText('ICAO code for airports')
                            ->visible(fn (Forms\Get $get) => $get('port_category') === 'AIRPORT')
                            ->columnSpan(1),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->hidden(fn (Forms\Get $get) => !in_array($get('port_category'), ['SEA_PORT', 'AIRPORT'])),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('country')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('country_code')
                    ->label('Country Code')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('port_category')
                    ->label('Category')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'SEA_PORT' => 'success',
                        'AIRPORT' => 'info',
                        'ICD' => 'warning',
                        'UNKNOWN' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pol' => 'primary',
                        'pod' => 'secondary',
                        'both' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('review_status')
                    ->label('Review')
                    ->badge()
                    ->getStateUsing(function (Port $record): string {
                        if (!$record->is_active) {
                            return 'Inactive';
                        }
                        if ($record->port_category === 'UNKNOWN' || empty($record->country_code)) {
                            return 'Needs review';
                        }
                        return 'OK';
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Needs review' => 'warning',
                        'Inactive' => 'danger',
                        'OK' => 'success',
                        default => 'gray',
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('port_category', $direction)
                            ->orderByRaw('CASE WHEN country_code IS NULL OR country_code = \'\' THEN 0 ELSE 1 END', $direction);
                    }),

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
                Tables\Filters\Filter::make('needs_review')
                    ->label('Needs Review')
                    ->query(fn (Builder $query): Builder => $query->where(function ($q) {
                        $q->where('port_category', 'UNKNOWN')
                            ->orWhereNull('country_code')
                            ->orWhere('country_code', '');
                    })),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active')
                    ->placeholder('All')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),

                Tables\Filters\SelectFilter::make('port_category')
                    ->label('Category')
                    ->options([
                        'SEA_PORT' => 'Sea Port',
                        'AIRPORT' => 'Airport',
                        'ICD' => 'Inland Container Depot',
                        'UNKNOWN' => 'Unknown',
                    ]),

                Tables\Filters\SelectFilter::make('type')
                    ->label('Type')
                    ->options([
                        'pol' => 'POL',
                        'pod' => 'POD',
                        'both' => 'Both',
                    ]),
            ])
            ->headerActions([
                // A) Sync from UN/LOCODE
                Action::make('syncUnlocode')
                    ->label('Sync from UN/LOCODE')
                    ->icon('heroicon-o-globe-alt')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Sync Ports from UN/LOCODE CSV')
                    ->modalDescription('Import/update ports from UN/LOCODE CSV files. Defaults to dry-run mode for safety.')
                    ->form([
                        Textarea::make('paths_raw')
                            ->label('CSV File Paths')
                            ->required()
                            ->helperText('Enter one or more file paths, one per line or comma-separated')
                            ->rows(3)
                            ->columnSpanFull(),
                        Toggle::make('dry_run')
                            ->label('Dry Run (preview only)')
                            ->default(true)
                            ->helperText('When enabled, shows what would be done without making changes'),
                        Toggle::make('create')
                            ->label('Create missing ports')
                            ->default(true),
                        Toggle::make('update_missing')
                            ->label('Update missing fields')
                            ->default(true),
                        Toggle::make('force_update')
                            ->label('Force update (overwrite existing)')
                            ->default(false),
                        Select::make('allowlist')
                            ->label('Allowlist Mode')
                            ->options([
                                'default' => 'Default (Belgaco lanes)',
                                'countries' => 'Countries only',
                                'unlocodes' => 'UN/LOCODEs only',
                                'all' => 'All (no filtering)',
                            ])
                            ->default('default'),
                        TextInput::make('countries_raw')
                            ->label('Countries Filter (optional)')
                            ->helperText('Comma-separated ISO2 codes (e.g., BE,FR,AE). Overrides allowlist countries.')
                            ->columnSpanFull(),
                        TextInput::make('limit')
                            ->label('Limit (optional)')
                            ->numeric()
                            ->helperText('Limit number of rows processed (for testing)'),
                    ])
                    ->action(function (array $data, $livewire) {
                        try {
                            // Parse paths
                            $pathsRaw = $data['paths_raw'] ?? '';
                            $paths = array_filter(array_map('trim', preg_split('/[\n,]+/', $pathsRaw)));
                            if (empty($paths)) {
                                throw new \RuntimeException('No file paths provided');
                            }

                            // Parse countries
                            $countries = [];
                            if (!empty($data['countries_raw'])) {
                                $countries = array_filter(array_map(function($c) {
                                    return strtoupper(trim($c));
                                }, explode(',', $data['countries_raw'])));
                            }

                            // Build params
                            $params = [
                                '--paths' => $paths,
                                '--allowlist' => $data['allowlist'] ?? 'default',
                            ];
                            if ($data['dry_run'] ?? true) {
                                $params['--dry-run'] = true;
                            }
                            if ($data['create'] ?? true) {
                                $params['--create'] = true;
                            }
                            if ($data['update_missing'] ?? true) {
                                $params['--update-missing'] = true;
                            }
                            if ($data['force_update'] ?? false) {
                                $params['--force-update'] = true;
                            }
                            if (!empty($countries)) {
                                $params['--countries'] = $countries;
                            }
                            if (!empty($data['limit'])) {
                                $params['--limit'] = (int)$data['limit'];
                            }

                            Notification::make()
                                ->title('Running…')
                                ->body('Please wait.')
                                ->info()
                                ->send();

                            Artisan::call('ports:sync-unlocode', $params);
                            $output = Artisan::output();

                            $title = ($data['dry_run'] ?? true) 
                                ? 'UN/LOCODE sync dry-run finished' 
                                : 'UN/LOCODE sync finished';

                            Notification::make()
                                ->title($title)
                                ->body(static::truncateOutput($output))
                                ->success()
                                ->send();

                            $livewire->dispatch('$refresh');
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Command failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),

                // B) Enrich Airports
                Action::make('enrichAirports')
                    ->label('Enrich Airports')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Enrich Ports with Airport Codes')
                    ->modalDescription('Enrich existing ports with IATA/ICAO codes from CSV or OpenFlights file.')
                    ->form([
                        TextInput::make('path')
                            ->label('CSV/OpenFlights File Path')
                            ->required()
                            ->helperText('Full path to airport data file (CSV or OpenFlights .dat format)')
                            ->columnSpanFull(),
                        Toggle::make('dry_run')
                            ->label('Dry Run (preview only)')
                            ->default(true),
                        Toggle::make('force_update')
                            ->label('Force update (overwrite existing)')
                            ->default(false),
                        Select::make('allowlist')
                            ->label('Allowlist Mode')
                            ->options([
                                'default' => 'Default (filtered IATA codes)',
                                'all' => 'All airports',
                            ])
                            ->default('default'),
                        TextInput::make('limit')
                            ->label('Limit (optional)')
                            ->numeric()
                            ->helperText('Limit number of rows processed (for testing)'),
                    ])
                    ->action(function (array $data, $livewire) {
                        try {
                            $path = trim($data['path'] ?? '');
                            if (empty($path)) {
                                throw new \RuntimeException('File path is required');
                            }

                            $params = [
                                '--path' => $path,
                                '--allowlist' => $data['allowlist'] ?? 'default',
                            ];
                            if ($data['dry_run'] ?? true) {
                                $params['--dry-run'] = true;
                            }
                            if ($data['force_update'] ?? false) {
                                $params['--force-update'] = true;
                            }
                            if (!empty($data['limit'])) {
                                $params['--limit'] = (int)$data['limit'];
                            }

                            Notification::make()
                                ->title('Running…')
                                ->body('Please wait.')
                                ->info()
                                ->send();

                            Artisan::call('ports:enrich-airports', $params);
                            $output = Artisan::output();

                            Notification::make()
                                ->title('Airport enrichment finished')
                                ->body(static::truncateOutput($output))
                                ->success()
                                ->send();

                            $livewire->dispatch('$refresh');
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Command failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),

                // C) Backfill Country Codes
                Action::make('backfillCountryCodes')
                    ->label('Backfill Country Codes')
                    ->icon('heroicon-o-flag')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Backfill Missing Country Codes')
                    ->modalDescription('Attempt to fill missing country_code fields for legacy ports.')
                    ->form([
                        Toggle::make('dry_run')
                            ->label('Dry Run (preview only)')
                            ->default(true),
                        Toggle::make('force_update')
                            ->label('Force update (overwrite existing)')
                            ->default(false),
                    ])
                    ->action(function (array $data, $livewire) {
                        try {
                            $params = [];
                            if ($data['dry_run'] ?? true) {
                                $params['--dry-run'] = true;
                            }
                            if ($data['force_update'] ?? false) {
                                $params['--force-update'] = true;
                            }

                            Notification::make()
                                ->title('Running…')
                                ->body('Please wait.')
                                ->info()
                                ->send();

                            Artisan::call('ports:backfill-country-codes', $params);
                            $output = Artisan::output();

                            Notification::make()
                                ->title('Country code backfill finished')
                                ->body(static::truncateOutput($output))
                                ->success()
                                ->send();

                            $livewire->dispatch('$refresh');
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Command failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),

                // D) Run Audit
                Action::make('runAudit')
                    ->label('Run Audit')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Run Port Normalization Audit')
                    ->modalDescription('Audit port data for duplicates, orphans, invalid mappings, and unresolved inputs.')
                    ->form([
                        Toggle::make('json')
                            ->label('Output JSON')
                            ->default(false)
                            ->helperText('When enabled, outputs JSON. Use this for Alias Workbench import.'),
                        Toggle::make('show_link_to_workbench')
                            ->label('Show link to Alias Workbench')
                            ->default(true)
                            ->helperText('Show a quick link to open the Port Alias Workbench after audit'),
                    ])
                    ->action(function (array $data, $livewire) {
                        try {
                            $params = [];
                            if ($data['json'] ?? false) {
                                $params['--json'] = true;
                            }

                            Notification::make()
                                ->title('Running…')
                                ->body('Please wait.')
                                ->info()
                                ->send();

                            Artisan::call('ports:audit-normalization', $params);
                            $output = Artisan::output();

                            $title = ($data['json'] ?? false)
                                ? 'Ports audit (JSON) finished'
                                : 'Ports audit finished';

                            $notification = Notification::make()
                                ->title($title)
                                ->body(static::truncateOutput($output))
                                ->success();

                            // Add link to workbench if enabled and class exists
                            if (($data['show_link_to_workbench'] ?? true) && class_exists(\App\Filament\Pages\PortAliasWorkbench::class)) {
                                $notification->actions([
                                    \Filament\Notifications\Actions\Action::make('open_workbench')
                                        ->label('Open Alias Workbench')
                                        ->url(\App\Filament\Pages\PortAliasWorkbench::getUrl())
                                        ->openUrlInNewTab(),
                                ]);
                            }

                            $notification->send();

                            $livewire->dispatch('$refresh');
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Command failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),
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
            RelationManagers\PortAliasesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPorts::route('/'),
            'create' => Pages\CreatePort::route('/create'),
            'edit' => Pages\EditPort::route('/{record}/edit'),
        ];
    }
}

