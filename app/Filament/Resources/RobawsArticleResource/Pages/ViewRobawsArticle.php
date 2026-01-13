<?php

namespace App\Filament\Resources\RobawsArticleResource\Pages;

use App\Filament\Resources\RobawsArticleResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;

class ViewRobawsArticle extends ViewRecord
{
    protected static string $resource = RobawsArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('push_to_robaws')
                ->label('Push to Robaws')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Push article changes to Robaws?')
                ->form(function () {
                    $record = $this->record;
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
                ->action(function (array $data) {
                    $pushService = app(\App\Services\Robaws\RobawsArticlePushService::class);
                    $result = $pushService->pushArticleToRobaws(
                        $this->record,
                        $data['fields_to_push'],
                        0,
                        true,
                        2
                    );
                    
                    if ($result['success']) {
                        $fieldsPushed = !empty($result['fields_pushed']) 
                            ? implode(', ', $result['fields_pushed']) 
                            : 'selected fields';
                        Notification::make()
                            ->title('Article pushed successfully')
                            ->body("Pushed {$fieldsPushed} for: {$this->record->article_name}")
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
                ->visible(fn () => !empty($this->record->robaws_article_id)),
            Actions\EditAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    public function mount(int | string $record): void
    {
        parent::mount($record);
        
        // Eager load port relationships for the single record view
        if ($this->record) {
            $this->record->load(['polPort', 'podPort']);
        }
    }
    
    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Article Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('article_code')
                            ->label('Article Code')
                            ->placeholder('N/A'),
                        Infolists\Components\TextEntry::make('article_name')
                            ->label('Article Name')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('robaws_article_id')
                            ->label('Robaws ID'),
                        Infolists\Components\TextEntry::make('unit_price')
                            ->money('EUR')
                            ->label('Unit Price'),
                        Infolists\Components\TextEntry::make('unit_type')
                            ->badge(),
                        Infolists\Components\TextEntry::make('category')
                            ->badge(),
                    ])
                    ->columns(3),
                    
                Infolists\Components\Section::make('Classification')
                    ->schema([
                        Infolists\Components\TextEntry::make('applicable_services')
                            ->badge()
                            ->formatStateUsing(function ($state): string {
                                // Handle individual string elements (Filament calls this for each array element)
                                if (is_string($state)) {
                                    // Check if it's a JSON string
                                    $decoded = json_decode($state, true);
                                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                        // It's a JSON array string, format it
                                        if (count($decoded) > 0) {
                                            return implode(', ', array_map(fn($s) => str_replace('_', ' ', $s), $decoded));
                                        }
                                        return 'None';
                                    } else {
                                        // It's a simple string element, just format it
                                        return str_replace('_', ' ', $state);
                                    }
                                }
                                // Handle array case (shouldn't happen with Filament, but just in case)
                                if (is_array($state) && count($state) > 0) {
                                    return implode(', ', array_map(fn($s) => str_replace('_', ' ', $s), $state));
                                }
                                return 'None';
                            })
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('shipping_line')
                            ->badge()
                            ->placeholder('N/A'),
                        Infolists\Components\TextEntry::make('transport_mode')
                            ->badge()
                            ->placeholder('N/A'),
                        Infolists\Components\TextEntry::make('service_type')
                            ->badge()
                            ->placeholder('N/A'),
                        Infolists\Components\TextEntry::make('article_type')
                            ->badge()
                            ->placeholder('N/A'),
                        Infolists\Components\TextEntry::make('cost_side')
                            ->badge()
                            ->placeholder('N/A'),
                        Infolists\Components\TextEntry::make('effective_update_date')
                            ->label('Update Date')
                            ->date('d-m-Y')
                            ->placeholder('N/A'),
                        Infolists\Components\TextEntry::make('effective_validity_date')
                            ->label('Validity Date')
                            ->date('d-m-Y')
                            ->placeholder('N/A'),
                        Infolists\Components\IconEntry::make('is_parent_item')
                            ->boolean()
                            ->label('Parent Item'),
                        Infolists\Components\IconEntry::make('is_surcharge')
                            ->boolean()
                            ->label('Is Surcharge'),
                        Infolists\Components\IconEntry::make('is_mandatory')
                            ->boolean()
                            ->label('Is Mandatory'),
                        Infolists\Components\TextEntry::make('mandatory_condition')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Routing & Ports')
                    ->schema([
                        Infolists\Components\TextEntry::make('pol_port_id')
                            ->label('POL Port')
                            ->formatStateUsing(function ($state, $record) {
                                if ($record->polPort) {
                                    return "{$record->polPort->name} ({$record->polPort->code})";
                                }
                                return $record->pol_code ?? $record->pol ?? '—';
                            })
                            ->badge(fn ($state) => $state !== '—')
                            ->color(fn ($state) => $state !== '—' ? 'success' : 'gray')
                            ->placeholder('Not set'),
                        Infolists\Components\TextEntry::make('pol')
                            ->label('POL (Raw)')
                            ->placeholder('N/A')
                            ->visible(fn ($record) => !empty($record->pol)),
                        Infolists\Components\TextEntry::make('pol_code')
                            ->label('POL Code')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('pol_terminal')
                            ->label('POL Terminal')
                            ->placeholder('N/A'),
                        Infolists\Components\TextEntry::make('pod_port_id')
                            ->label('POD Port')
                            ->formatStateUsing(function ($state, $record) {
                                if ($record->podPort) {
                                    return "{$record->podPort->name} ({$record->podPort->code})";
                                }
                                return $record->pod_code ?? $record->pod ?? '—';
                            })
                            ->badge(fn ($state) => $state !== '—')
                            ->color(fn ($state) => $state !== '—' ? 'success' : 'gray')
                            ->placeholder('Not set'),
                        Infolists\Components\TextEntry::make('pod')
                            ->label('POD (Raw)')
                            ->placeholder('N/A')
                            ->visible(fn ($record) => !empty($record->pod)),
                        Infolists\Components\TextEntry::make('pod_code')
                            ->label('POD Code')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('commodity_type')
                            ->label('Commodity Type')
                            ->placeholder('N/A'),
                    ])
                    ->columns(3),
                    
                Infolists\Components\Section::make('Quantity & Pricing')
                    ->schema([
                        Infolists\Components\TextEntry::make('min_quantity')
                            ->label('Min Quantity'),
                        Infolists\Components\TextEntry::make('max_quantity')
                            ->label('Max Quantity'),
                        Infolists\Components\TextEntry::make('tier_label')
                            ->placeholder('N/A'),
                        Infolists\Components\TextEntry::make('pricing_formula')
                            ->formatStateUsing(function ($state): string {
                                // Handle both array and JSON string cases
                                if (is_string($state)) {
                                    $decoded = json_decode($state, true);
                                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                        return json_encode($decoded, JSON_PRETTY_PRINT);
                                    }
                                    return 'Invalid data format';
                                }
                                if (is_array($state)) {
                                    return json_encode($state, JSON_PRETTY_PRINT);
                                }
                                return 'No formula';
                            })
                            ->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->collapsible(),

                Infolists\Components\Section::make('Purchase Price')
                    ->schema([
                        Infolists\Components\TextEntry::make('cost_price')
                            ->label('Total Purchase Cost')
                            ->formatStateUsing(function ($state, $record) {
                                if ($state === null) {
                                    return null;
                                }
                                
                                $currency = is_array($record->purchase_price_breakdown) && isset($record->purchase_price_breakdown['currency']) 
                                    ? $record->purchase_price_breakdown['currency'] 
                                    : ($record->currency ?? 'EUR');
                                
                                // Get unit type from breakdown (LM or LUMPSUM)
                                $unitType = $record->purchase_price_breakdown['total_unit_type'] ?? 'LUMPSUM';
                                
                                // Format money value manually (FilamentMoney facade not available)
                                // Use same format as breakdown display: comma for decimals, no thousands separator
                                $formattedAmount = number_format((float) $state, 2, ',', '');
                                $currencySymbol = match($currency) {
                                    'EUR' => '€',
                                    'USD' => '$',
                                    'GBP' => '£',
                                    default => $currency . ' ',
                                };
                                $formatted = $currencySymbol . $formattedAmount;
                                
                                return $formatted . ' (' . $unitType . ')';
                            })
                            ->placeholder('No purchase price data')
                            ->weight('bold')
                            ->size('lg')
                            ->visible(fn ($record): bool => $record->cost_price !== null || (!empty($record->purchase_price_breakdown) && is_array($record->purchase_price_breakdown))),
                        Infolists\Components\TextEntry::make('purchase_price_breakdown')
                            ->label('Breakdown')
                            ->getStateUsing(function ($record) {
                                $breakdown = $record->purchase_price_breakdown ?? [];
                                if (empty($breakdown) || !is_array($breakdown)) {
                                    return new \Illuminate\Support\HtmlString('<p class="text-gray-500">No breakdown data available</p>');
                                }
                                
                                $currency = $breakdown['currency'] ?? 'EUR';
                                $html = '<div class="space-y-3">';
                                
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
                                $html .= '</div>';
                                
                                $html .= '</div>';
                                return new \Illuminate\Support\HtmlString($html);
                            })
                            ->html()
                            ->columnSpanFull()
                            ->visible(fn ($record): bool => !empty($record->purchase_price_breakdown) && is_array($record->purchase_price_breakdown)),
                    ])
                    ->columns(1)
                    ->collapsible()
                    ->collapsed(false)
                    ->visible(fn ($record): bool => $record->cost_price !== null || (!empty($record->purchase_price_breakdown) && is_array($record->purchase_price_breakdown))),

                Infolists\Components\Section::make('Max Dimensions & Weight')
                    ->schema([
                        Infolists\Components\TextEntry::make('max_dimensions_breakdown')
                            ->label('Max Dimensions & Weight')
                            ->getStateUsing(function ($record) {
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
                            ->html()
                            ->columnSpanFull()
                            ->visible(fn ($record): bool => !empty($record->max_dimensions_breakdown) && is_array($record->max_dimensions_breakdown)),
                    ])
                    ->columns(1)
                    ->collapsible()
                    ->collapsed(false)
                    ->visible(fn ($record): bool => !empty($record->max_dimensions_breakdown) && is_array($record->max_dimensions_breakdown)),

                Infolists\Components\Section::make('Notes & Extra Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('notes')
                            ->placeholder('No notes provided')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('article_info')
                            ->label('Raw Article Info')
                            ->placeholder('N/A')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
                    
                Infolists\Components\Section::make('Parent-Child Relationships')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('children')
                            ->label('Child Articles')
                            ->schema([
                                Infolists\Components\TextEntry::make('article_name')
                                    ->label('Child Article'),
                                Infolists\Components\TextEntry::make('pivot.sort_order')
                                    ->label('Order'),
                                Infolists\Components\IconEntry::make('pivot.is_required')
                                    ->boolean()
                                    ->label('Required'),
                            ])
                            ->columns(3)
                            ->columnSpanFull()
                            ->visible(fn ($record): bool => $record->children()->count() > 0),
                            
                        Infolists\Components\RepeatableEntry::make('parents')
                            ->label('Parent Articles')
                            ->schema([
                                Infolists\Components\TextEntry::make('article_name')
                                    ->label('Parent Article'),
                            ])
                            ->columnSpanFull()
                            ->visible(fn ($record): bool => $record->parents()->count() > 0),
                    ])
                    ->collapsible()
                    ->collapsed(fn ($record): bool => 
                        $record->children()->count() === 0 && $record->parents()->count() === 0
                    ),
                    
                Infolists\Components\Section::make('Metadata')
                    ->schema([
                        Infolists\Components\IconEntry::make('requires_manual_review')
                            ->boolean()
                            ->label('Requires Manual Review'),
                        Infolists\Components\TextEntry::make('metadata_source')
                            ->label('Sync Source')
                            ->formatStateUsing(fn ($record) => str_contains((string) $record->article_info, 'Extracted from description') ? 'Fallback' : 'API')
                            ->badge()
                            ->color(fn ($record) => str_contains((string) $record->article_info, 'Extracted from description') ? 'warning' : 'success'),
                        Infolists\Components\TextEntry::make('last_synced_at')
                            ->dateTime()
                            ->label('Last Synced'),
                        Infolists\Components\TextEntry::make('created_at')
                            ->dateTime(),
                        Infolists\Components\TextEntry::make('updated_at')
                            ->dateTime(),
                    ])
                    ->columns(4)
                    ->collapsible()
                    ->collapsed(),
            ]);
    }
}

