<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExtractionResource\Pages;
use App\Models\Extraction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Database\Eloquent\Builder;

class ExtractionResource extends Resource
{
    protected static ?string $model = Extraction::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';
    
    protected static ?string $navigationGroup = 'AI Processing';
    
    protected static ?string $modelLabel = 'AI Extraction';
    
    protected static ?string $pluralModelLabel = 'AI Extractions';
    
    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('status')
                    ->required(),
                Forms\Components\Textarea::make('raw_json')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'processing' => 'info',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('confidence')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state * 100, 1) . '%' : '0.0%')
                    ->sortable(),
                Tables\Columns\TextColumn::make('service_used')
                    ->label('Service')
                    ->default('N/A'),
                Tables\Columns\TextColumn::make('intake.id')
                    ->label('Intake')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->since(),
            ])
            ->filters([
                Tables\Filters\Filter::make('verified')
                    ->toggle()
                    ->query(fn ($query) => $query->whereNotNull('verified_at')),
                    
                Tables\Filters\Filter::make('high_confidence')
                    ->label('High Confidence (≥80%)')
                    ->toggle()
                    ->query(fn ($query) => $query->where('confidence', '>=', 0.8)),
                    
                Tables\Filters\Filter::make('low_confidence')
                    ->label('Low Confidence (<60%)')
                    ->toggle()
                    ->query(fn ($query) => $query->where('confidence', '<', 0.6)),
                    
                Tables\Filters\SelectFilter::make('intake.status')
                    ->label('Intake Status')
                    ->relationship('intake', 'status')
                    ->options([
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('verify')
                    ->label('Verify')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Extraction $record) {
                        $record->update([
                            'verified_at' => now(),
                            'verified_by' => auth()->user()->name ?? 'System',
                        ]);
                    })
                    ->visible(fn (Extraction $record): bool => is_null($record->verified_at)),
                    
                Tables\Actions\Action::make('unverify')
                    ->label('Unverify')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (Extraction $record) {
                        $record->update([
                            'verified_at' => null,
                            'verified_by' => null,
                        ]);
                    })
                    ->visible(fn (Extraction $record): bool => !is_null($record->verified_at)),
                    
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
    
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Extraction Details')
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'completed' => 'success',
                                'processing' => 'info',
                                'failed' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('confidence')
                            ->formatStateUsing(fn ($state) => $state ? number_format($state * 100, 1) . '%' : '0.0%'),
                        TextEntry::make('service_used')
                            ->label('Service Used')
                            ->default('N/A'),
                        TextEntry::make('created_at')
                            ->label('Extracted At')
                            ->dateTime(),
                    ])
                    ->columns(2),

                Section::make('Document Information')
                    ->schema([
                        TextEntry::make('document_type')
                            ->label('Document Type')
                            ->getStateUsing(function ($record) {
                                $data = $record->extracted_data ?? [];
                                $hasShippingData = isset($data['messages']) || 
                                                 isset($data['contact']) ||
                                                 isset($data['contact_info']) ||
                                                 isset($data['vehicle_listing']) || 
                                                 isset($data['vehicle_info']) ||
                                                 isset($data['vehicle_details']) ||
                                                 isset($data['vehicle']) ||
                                                 isset($data['shipment']);
                                
                                if ($hasShippingData) {
                                    return 'Shipping Document';
                                }
                                
                                return $data['document_type'] ?? 'Image Document';
                            })
                            ->badge()
                            ->color(function ($state) {
                                return match ($state) {
                                    'Shipping Document' => 'success',
                                    'Image Document' => 'info',
                                    default => 'gray',
                                };
                            }),
                        TextEntry::make('analysis_type')
                            ->label('Analysis Type')
                            ->getStateUsing(function ($record) {
                                $data = $record->extracted_data ?? [];
                                $hasShippingData = isset($data['messages']) || 
                                                 isset($data['contact']) ||
                                                 isset($data['contact_info']) ||
                                                 isset($data['vehicle_listing']) ||
                                                 isset($data['vehicle_info']) ||
                                                 isset($data['vehicle_details']) ||
                                                 isset($data['vehicle']);
                                
                                if ($hasShippingData && ($record->analysis_type === 'basic' || empty($record->analysis_type))) {
                                    return 'shipping (auto-detected)';
                                }
                                
                                return $record->analysis_type ?? 'basic';
                            })
                            ->badge()
                            ->color(function ($state) {
                                return match (true) {
                                    str_contains($state, 'shipping') => 'success',
                                    $state === 'basic' => 'warning',
                                    $state === 'detailed' => 'info',
                                    default => 'gray',
                                };
                            }),
                    ])
                    ->columns(2),

                Section::make('Contact Information')
                    ->schema([
                        TextEntry::make('contact_name')
                            ->label('Contact Name')
                            ->getStateUsing(function ($record) {
                                $data = $record->extracted_data ?? [];
                                return $data['contact_info']['name'] ?? 
                                       $data['contact']['name'] ?? 'N/A';
                            }),
                        TextEntry::make('contact_phone')
                            ->label('Phone Number')
                            ->getStateUsing(function ($record) {
                                $data = $record->extracted_data ?? [];
                                return $data['contact_info']['phone_number'] ?? 
                                       $data['contact']['phone_number'] ?? 
                                       $data['contact']['phone'] ?? 'N/A';
                            }),
                        TextEntry::make('contact_account')
                            ->label('Account Type')
                            ->getStateUsing(function ($record) {
                                $data = $record->extracted_data ?? [];
                                return $data['contact_info']['account_type'] ?? 
                                       $data['contact']['account_type'] ?? 
                                       $data['contact']['company'] ?? 'N/A';
                            }),
                    ])
                    ->columns(3)
                    ->visible(function ($record) {
                        $data = $record->extracted_data ?? [];
                        return isset($data['contact']) || isset($data['contact_info']);
                    }),

                Section::make('Shipping Details')
                    ->schema([
                        TextEntry::make('origin')
                            ->label('Origin')
                            ->getStateUsing(function ($record) {
                                $data = $record->extracted_data ?? [];
                                // Try shipment structure first
                                if (isset($data['shipment']['origin'])) {
                                    return $data['shipment']['origin'];
                                }
                                
                                // Look in messages for "from X to Y" pattern
                                if (isset($data['messages'])) {
                                    foreach ($data['messages'] as $message) {
                                        if (isset($message['text']) && str_contains(strtolower($message['text']), 'from')) {
                                            if (preg_match('/from\s+([^to]+?)(?:\s+to\s+|$)/i', $message['text'], $matches)) {
                                                return trim($matches[1]);
                                            }
                                        }
                                    }
                                }
                                return 'N/A';
                            }),
                        TextEntry::make('destination')
                            ->label('Destination')
                            ->getStateUsing(function ($record) {
                                $data = $record->extracted_data ?? [];
                                // Try shipment structure first
                                if (isset($data['shipment']['destination'])) {
                                    return $data['shipment']['destination'];
                                }
                                
                                // Look in messages for "from X to Y" pattern
                                if (isset($data['messages'])) {
                                    foreach ($data['messages'] as $message) {
                                        if (isset($message['text']) && str_contains(strtolower($message['text']), 'to')) {
                                            if (preg_match('/to\s+([^,\s]*(?:,\s*[^,\s]*)*)/i', $message['text'], $matches)) {
                                                return trim($matches[1]);
                                            }
                                        }
                                    }
                                }
                                return 'N/A';
                            }),
                        TextEntry::make('vehicle_type')
                            ->label('Vehicle Information')
                            ->getStateUsing(function ($record) {
                                $data = $record->extracted_data ?? [];
                                
                                // Check for vehicle structure (email extraction format)
                                if (isset($data['vehicle'])) {
                                    $vehicle = $data['vehicle'];
                                    
                                    // Use full_name if available
                                    if (!empty($vehicle['full_name'])) {
                                        $result = $vehicle['full_name'];
                                        if (!empty($vehicle['specifications'])) {
                                            $result .= ' - ' . $vehicle['specifications'];
                                        }
                                        return $result;
                                    }
                                    
                                    // Build from brand/model
                                    $parts = [];
                                    if (!empty($vehicle['brand'])) {
                                        $parts[] = $vehicle['brand'];
                                    }
                                    if (!empty($vehicle['model'])) {
                                        $parts[] = $vehicle['model'];
                                    }
                                    if (!empty($vehicle['year'])) {
                                        $parts[] = '(' . $vehicle['year'] . ')';
                                    }
                                    
                                    if (!empty($parts)) {
                                        $result = implode(' ', $parts);
                                        if (!empty($vehicle['specifications'])) {
                                            $result .= ' - ' . $vehicle['specifications'];
                                        }
                                        return $result;
                                    }
                                    
                                    // Fallback to type
                                    if (!empty($vehicle['type'])) {
                                        return $vehicle['type'];
                                    }
                                }
                                
                                // Check for vehicle_details structure (newest format)
                                if (isset($data['vehicle_details'])) {
                                    $make = $data['vehicle_details']['make'] ?? '';
                                    $model = $data['vehicle_details']['model'] ?? '';
                                    $specs = $data['vehicle_details']['specifications'] ?? '';
                                    
                                    $vehicle = trim("$make $model");
                                    return $specs ? "$vehicle - $specs" : $vehicle;
                                }
                                
                                // Check for vehicle_info structure (old format)
                                if (isset($data['vehicle_info'])) {
                                    $make = $data['vehicle_info']['make'] ?? '';
                                    $model = $data['vehicle_info']['model'] ?? '';
                                    $specs = $data['vehicle_info']['specifications'] ?? '';
                                    
                                    $vehicle = trim("$make $model");
                                    return $specs ? "$vehicle - $specs" : $vehicle;
                                }
                                
                                // Check for vehicle_listing structure (legacy format)
                                if (isset($data['vehicle_listing']['vehicle'])) {
                                    $vehicle = $data['vehicle_listing']['vehicle'];
                                    $details = $data['vehicle_listing']['model_details'] ?? '';
                                    return $details ? "{$vehicle} - {$details}" : $vehicle;
                                }
                                
                                // Fallback: look for vehicle mentions in messages
                                if (isset($data['messages'])) {
                                    foreach ($data['messages'] as $message) {
                                        if (isset($message['text']) && str_contains(strtolower($message['text']), 'mercedes')) {
                                            return 'Mercedes-Benz Sprinter (from message)';
                                        }
                                    }
                                }
                                
                                return 'N/A';
                            })
                            ->columnSpan(2),
                        TextEntry::make('vehicle_price')
                            ->label('Vehicle Price')
                            ->getStateUsing(function ($record) {
                                $data = $record->extracted_data ?? [];
                                
                                // Check vehicle structure (email extraction format)
                                if (isset($data['vehicle']['price']) && !empty($data['vehicle']['price'])) {
                                    return $data['vehicle']['price'];
                                }
                                
                                // Check vehicle_details structure (newest format)
                                if (isset($data['vehicle_details']['price'])) {
                                    $price = $data['vehicle_details']['price'];
                                    $netPrice = $data['vehicle_details']['net_price'] ?? '';
                                    return $netPrice ? "{$price} (Net: {$netPrice})" : $price;
                                }
                                
                                // Check vehicle_info structure (new format)
                                if (isset($data['vehicle_info']['price'])) {
                                    $price = $data['vehicle_info']['price'];
                                    $netPrice = $data['vehicle_info']['net_price'] ?? '';
                                    return $netPrice ? "{$price} (Net: {$netPrice})" : $price;
                                }
                                
                                // Check vehicle_listing structure (old format)
                                if (isset($data['vehicle_listing']['price'])) {
                                    $price = $data['vehicle_listing']['price'];
                                    $netPrice = $data['vehicle_listing']['net_price'] ?? '';
                                    return $netPrice ? "{$price} (Net: {$netPrice})" : $price;
                                }
                                
                                // Also check pricing section
                                if (isset($data['pricing']['amount'])) {
                                    return $data['pricing']['amount'];
                                }
                                return 'N/A';
                            })
                            ->badge()
                            ->color('success'),
                    ])
                    ->columns(3)
                    ->visible(function ($record) {
                        $data = $record->extracted_data ?? [];
                        return isset($data['messages']) || isset($data['shipment']) || 
                               isset($data['vehicle_listing']) || isset($data['vehicle_info']) ||
                               isset($data['vehicle_details']) || isset($data['vehicle']);
                    }),

                Section::make('Messages Conversation')
                    ->schema([
                        TextEntry::make('messages')
                            ->label('Conversation')
                            ->getStateUsing(function ($record) {
                                $data = $record->extracted_data ?? [];
                                if (!isset($data['messages'])) {
                                    return 'No messages found';
                                }
                                
                                $messages = $data['messages'];
                                $formatted = '';
                                
                                foreach ($messages as $message) {
                                    $sender = $message['sender'] ?? 'User';
                                    $text = $message['text'] ?? $message['message'] ?? '';
                                    $time = isset($message['time']) ? 
                                           " ({$message['time']})" : 
                                           (isset($message['timestamp']) ? " ({$message['timestamp']})" : '');
                                    
                                    $formatted .= "**{$sender}**{$time}:\n{$text}\n\n---\n\n";
                                }
                                
                                return trim($formatted, "\n-");
                            })
                            ->markdown()
                            ->columnSpan(3),
                    ])
                    ->columns(3)
                    ->visible(function ($record) {
                        $data = $record->extracted_data ?? [];
                        return isset($data['messages']) && !empty($data['messages']);
                    }),

                Section::make('Raw JSON Data')
                    ->schema([
                        TextEntry::make('raw_json')
                            ->label('')
                            ->getStateUsing(function ($record) {
                                $json = $record->raw_json ?? json_encode($record->extracted_data ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                                
                                // Ensure it's properly formatted JSON
                                if (!is_string($json)) {
                                    $json = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                                }
                                
                                return $json;
                            })
                            ->formatStateUsing(function ($state) {
                                return new \Illuminate\Support\HtmlString(
                                    '<pre class="bg-gray-900 text-green-400 p-4 rounded-lg overflow-x-auto text-xs font-mono">' . 
                                    htmlspecialchars($state) . 
                                    '</pre>'
                                );
                            }),
                    ])
                    ->collapsible()
                    ->collapsed(),
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
            'index' => Pages\ListExtractions::route('/'),
            'create' => Pages\CreateExtraction::route('/create'),
            'view' => Pages\ViewExtraction::route('/{record}'),
            'edit' => Pages\EditExtraction::route('/{record}/edit'),
        ];
    }
}
