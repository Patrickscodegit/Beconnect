<?php

namespace App\Filament\Pages;

use App\Models\Port;
use App\Models\PortAlias;
use App\Services\Ports\PortResolutionService;
use App\Support\PortAliasNormalizer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PortAliasWorkbench extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static string $view = 'filament.pages.port-alias-workbench';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 11;

    protected static ?string $title = 'Port Alias Workbench';

    protected static ?string $navigationLabel = 'Port Alias Workbench';

    public string $input = '';

    public bool $splitCombined = true;

    public array $results = [];

    public string $auditJson = '';

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Textarea::make('input')
                    ->label('Port Strings/Codes')
                    ->placeholder('Paste port strings/codes (one per line)')
                    ->rows(10)
                    ->helperText('Enter port names, codes, or combined inputs (e.g., "CAS/TFN") - one per line')
                    ->columnSpanFull(),

                Toggle::make('splitCombined')
                    ->label('Split combined inputs')
                    ->helperText('When enabled, inputs like "CAS/TFN" will be split and resolved separately')
                    ->default(true)
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function analyze(): void
    {
        $this->validate([
            'data.input' => 'required|string',
        ]);

        $input = $this->data['input'] ?? '';
        $splitCombined = $this->data['splitCombined'] ?? true;

        if (empty(trim($input))) {
            Notification::make()
                ->title('No input provided')
                ->body('Please enter port strings to analyze.')
                ->warning()
                ->send();
            return;
        }

        $lines = array_filter(array_map('trim', explode("\n", $input)));
        $results = [];
        $portResolver = app(PortResolutionService::class);

        foreach ($lines as $lineIndex => $line) {
            if (empty($line)) {
                continue;
            }

            $lineResult = [
                'line' => $line,
                'ports' => [],
                'unresolved' => [],
                'mappings' => [],
            ];

            if ($splitCombined) {
                $report = $portResolver->resolveManyWithReport($line);
                $lineResult['ports'] = array_map(function ($port) {
                    return [
                        'id' => $port->id,
                        'label' => $port->formatFull(),
                    ];
                }, $report['ports']);
                $lineResult['unresolved'] = $report['unresolved'];
            } else {
                $port = $portResolver->resolveOne($line);
                if ($port) {
                    $lineResult['ports'] = [[
                        'id' => $port->id,
                        'label' => $port->formatFull(),
                    ]];
                } else {
                    $lineResult['unresolved'] = [$line];
                }
            }

            $results[] = $lineResult;
        }

        $this->results = $results;

        $totalResolved = count(array_filter($results, fn($r) => !empty($r['ports'])));
        $totalUnresolved = count(array_filter($results, fn($r) => !empty($r['unresolved'])));

        Notification::make()
            ->title('Analysis complete')
            ->body("Processed " . count($results) . " line(s). {$totalResolved} resolved, {$totalUnresolved} with unresolved tokens.")
            ->success()
            ->send();
    }

    public function updateMapping(int $lineIndex, string $token, string $field, $value): void
    {
        if (!isset($this->results[$lineIndex]['mappings'][$token])) {
            $this->results[$lineIndex]['mappings'][$token] = [
                'port_id' => null,
                'alias_type' => 'name_variant',
                'is_active' => true,
            ];
        }

        $this->results[$lineIndex]['mappings'][$token][$field] = $value;
    }

    public function createSingleAlias(int $lineIndex, string $token): void
    {
        if (!isset($this->results[$lineIndex]['mappings'][$token])) {
            Notification::make()
                ->title('No mapping configured')
                ->body('Please select a port for this alias.')
                ->warning()
                ->send();
            return;
        }

        $mapping = $this->results[$lineIndex]['mappings'][$token];

        if (empty($mapping['port_id'])) {
            Notification::make()
                ->title('Port required')
                ->body('Please select a port for this alias.')
                ->warning()
                ->send();
            return;
        }

        try {
            $this->createAlias($token, $mapping);

            Notification::make()
                ->title('Alias created')
                ->body("Alias '{$token}' created successfully.")
                ->success()
                ->send();

            // Remove from unresolved
            $this->results[$lineIndex]['unresolved'] = array_values(
                array_filter($this->results[$lineIndex]['unresolved'], fn($t) => $t !== $token)
            );
            unset($this->results[$lineIndex]['mappings'][$token]);
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function createAllAliases(): void
    {
        $created = 0;
        $conflicts = [];

        try {
            DB::transaction(function () use (&$created, &$conflicts) {
                foreach ($this->results as $lineIndex => $lineResult) {
                    foreach ($lineResult['mappings'] ?? [] as $token => $mapping) {
                        if (empty($mapping['port_id'])) {
                            continue;
                        }

                        try {
                            $this->createAlias($token, $mapping);
                            $created++;

                            // Remove from unresolved
                            $this->results[$lineIndex]['unresolved'] = array_values(
                                array_filter($this->results[$lineIndex]['unresolved'], fn($t) => $t !== $token)
                            );
                        } catch (\Exception $e) {
                            $conflicts[] = [
                                'token' => $token,
                                'error' => $e->getMessage(),
                            ];
                            // Continue with other aliases
                        }
                    }
                }
            });

            $message = "Created {$created} alias(es).";
            if (!empty($conflicts)) {
                $message .= " " . count($conflicts) . " conflict(s): " . implode(', ', array_column($conflicts, 'token'));
            }

            Notification::make()
                ->title('Bulk creation complete')
                ->body($message)
                ->success()
                ->send();

            // Clear mappings after successful creation
            foreach ($this->results as $lineIndex => $lineResult) {
                foreach (array_keys($lineResult['mappings'] ?? []) as $token) {
                    if (!in_array($token, $lineResult['unresolved'] ?? [])) {
                        unset($this->results[$lineIndex]['mappings'][$token]);
                    }
                }
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error')
                ->body('Failed to create aliases: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function createAlias(string $token, array $mapping): PortAlias
    {
        $aliasNormalized = PortAliasNormalizer::normalize($token);

        // Check for existing alias with same normalized value
        $existing = PortAlias::where('alias_normalized', $aliasNormalized)->first();
        if ($existing) {
            throw ValidationException::withMessages([
                'alias' => "Alias '{$token}' conflicts with existing alias '{$existing->alias}' for port '{$existing->port->name} ({$existing->port->code})'."
            ]);
        }

        return PortAlias::create([
            'port_id' => $mapping['port_id'],
            'alias' => $token,
            'alias_normalized' => $aliasNormalized,
            'alias_type' => $mapping['alias_type'] ?? 'name_variant',
            'is_active' => $mapping['is_active'] ?? true,
        ]);
    }

    public function loadFromAuditJson(): void
    {
        $this->validate([
            'auditJson' => 'required|string|json',
        ]);

        try {
            $data = json_decode($this->auditJson, true);

            if (!is_array($data)) {
                throw new \InvalidArgumentException('Invalid JSON format');
            }

            $unresolved = [];
            if (isset($data['unresolved_robaws_inputs'])) {
                $unresolved = array_merge($unresolved, $data['unresolved_robaws_inputs']);
            }
            if (isset($data['unresolved'])) {
                $unresolved = array_merge($unresolved, $data['unresolved']);
            }

            // Deduplicate and append to input
            $existingLines = array_filter(array_map('trim', explode("\n", $this->input)));
            $newLines = array_unique(array_merge($existingLines, $unresolved));

            $this->input = implode("\n", $newLines);
            $this->auditJson = '';

            Notification::make()
                ->title('Loaded from audit JSON')
                ->body('Added ' . count($unresolved) . ' unresolved token(s) to input.')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error')
                ->body('Failed to parse audit JSON: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function getPortOptions(string $search = ''): array
    {
        $searchLower = strtolower($search);
        
        if (empty($searchLower)) {
            return Port::orderBy('name')
                ->limit(50)
                ->get()
                ->mapWithKeys(function ($port) {
                    return [$port->id => $port->formatFull()];
                })
                ->toArray();
        }
        
        $ports = Port::where(function($q) use ($searchLower) {
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
        })
        ->toArray();

        return $ports;
    }
}

