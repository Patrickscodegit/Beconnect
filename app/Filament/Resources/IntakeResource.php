<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IntakeResource\Pages;
use App\Models\Intake;
use App\Models\Document;
use App\Models\Extraction;
use App\Services\RobawsIntegrationService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class IntakeResource extends Resource
{
    protected static ?string $model = Intake::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';
    
    protected static ?string $navigationLabel = 'Intakes';
    
    protected static ?string $modelLabel = 'Intake';
    
    protected static ?string $pluralModelLabel = 'Intakes';
    
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Intake Information')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->options([
                                'pending' => 'Pending',
                                'processing' => 'Processing',
                                'completed' => 'Completed',
                                'failed' => 'Failed',
                            ])
                            ->default('pending')
                            ->required()
                            ->live(),
                            
                        Forms\Components\Select::make('source')
                            ->options([
                                'email' => 'Email',
                                'upload' => 'Manual Upload',
                                'api' => 'API',
                                'ftp' => 'FTP',
                            ])
                            ->default('upload')
                            ->required(),
                            
                        Forms\Components\Select::make('priority')
                            ->options([
                                'low' => 'Low',
                                'normal' => 'Normal',
                                'high' => 'High',
                                'urgent' => 'Urgent',
                            ])
                            ->default('normal')
                            ->required(),
                            
                        Forms\Components\Textarea::make('notes')
                            ->rows(4)
                            ->placeholder('Additional notes about this intake...')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
                    
                Forms\Components\Section::make('Document Upload')
                    ->schema([
                        Forms\Components\FileUpload::make('document_files')
                            ->label('Upload Documents')
                            ->multiple()
                            ->acceptedFileTypes([
                                'application/pdf',
                                'image/jpeg', 
                                'image/jpg',
                                'image/png', 
                                'image/tiff',
                                'image/gif',
                                'message/rfc822', // .eml files
                                'application/vnd.ms-outlook', // .msg files
                            ])
                            ->maxSize(20480) // 20MB max
                            ->disk('local')
                            ->directory('temp-uploads')
                            ->reorderable()
                            ->helperText('Upload freight documents (PDF, images, email files). Maximum 20MB per file.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->searchable(),
                    
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'processing' => 'info',
                        'completed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('source')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'email' => 'blue',
                        'upload' => 'green',
                        'api' => 'purple',
                        'ftp' => 'orange',
                        default => 'gray',
                    })
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('priority')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'low' => 'gray',
                        'normal' => 'blue',
                        'high' => 'orange',
                        'urgent' => 'red',
                        default => 'gray',
                    })
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('documents_count')
                    ->label('Documents')
                    ->counts('documents')
                    ->badge()
                    ->color('success'),
                    
                Tables\Columns\IconColumn::make('has_extraction')
                    ->label('Extracted')
                    ->getStateUsing(fn (Intake $record): bool => $record->extraction()->exists())
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray'),
                    
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->since(),
                    
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ]),
                    
                Tables\Filters\SelectFilter::make('source')
                    ->options([
                        'email' => 'Email',
                        'upload' => 'Manual Upload',
                        'api' => 'API',
                        'ftp' => 'FTP',
                    ]),
                    
                Tables\Filters\SelectFilter::make('priority')
                    ->options([
                        'low' => 'Low',
                        'normal' => 'Normal',
                        'high' => 'High',
                        'urgent' => 'Urgent',
                    ]),
                    
                Tables\Filters\Filter::make('has_extraction')
                    ->label('Has Extraction')
                    ->toggle()
                    ->query(fn ($query) => $query->whereHas('extraction')),
                    
                Tables\Filters\Filter::make('has_documents')
                    ->label('Has Documents')
                    ->toggle()
                    ->query(fn ($query) => $query->whereHas('documents')),
            ])
            ->actions([
                Tables\Actions\Action::make('export_to_robaws')
                    ->label('Export to Robaws')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Export to Robaws')
                    ->modalDescription('This will create a new quotation in Robaws using the extracted data from this intake and attach the uploaded file to the offer.')
                    ->action(function (Intake $record) {
                        try {
                            $extraction = $record->extraction;
                            
                            if (!$extraction || !$extraction->extracted_data) {
                                Notification::make()
                                    ->title('Export Failed')
                                    ->body('No extraction data found for this intake.')
                                    ->danger()
                                    ->send();
                                return;
                            }
                            
                            // Use the RobawsIntegrationService to create the offer
                            $robawsService = app(RobawsIntegrationService::class);
                            
                            // Map the extraction data to a document-like structure for the service
                            $mappedData = self::mapExtractionDataForRobaws($extraction->extracted_data);
                            
                            // Get the first document associated with this intake
                            $originalDocument = $record->documents()->first();
                            
                            if (!$originalDocument) {
                                Notification::make()
                                    ->title('Export Failed')
                                    ->body('No document file found for this intake.')
                                    ->danger()
                                    ->send();
                                return;
                            }
                            
                            // Create a document model with the extraction data for the service
                            $document = new Document([
                                'filename' => $originalDocument->filename,
                                'path' => $originalDocument->path,
                                'file_path' => $originalDocument->file_path,
                                'disk' => $originalDocument->disk ?? config('filesystems.default', 'local'),
                                'mime_type' => $originalDocument->mime_type,
                                'extraction_data' => $mappedData,
                                'user_id' => auth()->id() ?? 1,
                            ]);
                            $document->id = $originalDocument->id; // Use original document ID
                            
                            $offer = $robawsService->createOfferFromDocument($document);
                            
                            if ($offer) {
                                // Update the intake with Robaws reference
                                $record->update([
                                    'robaws_quotation_id' => $offer['id'] ?? null,
                                    'notes' => $record->notes . "\n\nRobaws Quotation ID: " . ($offer['id'] ?? 'Unknown'),
                                ]);
                                
                                $attachmentMessage = $originalDocument ? " The uploaded file has been attached to the offer." : "";
                                
                                Notification::make()
                                    ->title('Export Successful')
                                    ->body("Quotation created in Robaws with ID: " . ($offer['id'] ?? 'Unknown') . $attachmentMessage)
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Export Failed')
                                    ->body('Failed to create quotation in Robaws. Please check the logs for more details.')
                                    ->danger()
                                    ->send();
                            }
                            
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Export Error')
                                ->body('An error occurred: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->visible(fn (Intake $record): bool => $record->extraction()->exists()),
                    
                Tables\Actions\Action::make('view_extraction')
                    ->label('Extraction')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->modalHeading('Extraction Results')
                    ->modalContent(function (Intake $record) {
                        $extraction = $record->extraction;
                        
                        if (!$extraction) {
                            return view('filament.modals.no-extraction');
                        }
                        
                        return view('filament.modals.extraction-results', [
                            'extraction' => $extraction,
                        ]);
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->visible(fn (Intake $record): bool => $record->extraction()->exists()),
                    
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    
                    Tables\Actions\BulkAction::make('mark_as_completed')
                        ->label('Mark as Completed')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                $record->update(['status' => 'completed']);
                            }
                        }),
                        
                    Tables\Actions\BulkAction::make('mark_as_failed')
                        ->label('Mark as Failed')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                $record->update(['status' => 'failed']);
                            }
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
    
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Intake Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('id')
                            ->label('ID'),
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'pending' => 'warning',
                                'processing' => 'info',
                                'completed' => 'success',
                                'failed' => 'danger',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('source')
                            ->badge(),
                        Infolists\Components\TextEntry::make('priority')
                            ->badge(),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Created')
                            ->dateTime(),
                        Infolists\Components\TextEntry::make('updated_at')
                            ->label('Updated')
                            ->dateTime(),
                    ])
                    ->columns(3),
                    
                Infolists\Components\Section::make('Notes')
                    ->schema([
                        Infolists\Components\TextEntry::make('notes')
                            ->placeholder('No notes available'),
                    ])
                    ->visible(fn (Intake $record): bool => !empty($record->notes)),
                    
                Infolists\Components\Section::make('Documents')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('documents')
                            ->schema([
                                Infolists\Components\TextEntry::make('filename'),
                                Infolists\Components\TextEntry::make('document_type')
                                    ->badge(),
                                Infolists\Components\TextEntry::make('file_size')
                                    ->formatStateUsing(fn (int $state): string => number_format($state / 1024, 1) . ' KB'),
                                Infolists\Components\TextEntry::make('page_count')
                                    ->label('Pages'),
                            ])
                            ->columns(4),
                    ])
                    ->visible(fn (Intake $record): bool => $record->documents()->exists()),
                    
                Infolists\Components\Section::make('Extraction Results')
                    ->schema([
                        Infolists\Components\TextEntry::make('extraction.confidence')
                            ->label('Confidence')
                            ->formatStateUsing(fn (?float $state): string => $state ? number_format($state * 100, 1) . '%' : 'N/A'),
                        Infolists\Components\TextEntry::make('extraction.status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'completed' => 'success',
                                'processing' => 'info',
                                'failed' => 'danger',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('extraction.service_used')
                            ->label('Service Used')
                            ->badge(),
                        Infolists\Components\TextEntry::make('extraction.verified_at')
                            ->label('Verified At')
                            ->dateTime()
                            ->placeholder('Not verified'),
                        Infolists\Components\TextEntry::make('dummy_extraction_data')
                            ->label('Extracted Data')
                            ->formatStateUsing(function ($record) {
                                $extraction = $record->extraction;
                                if (!$extraction || !$extraction->extracted_data || !is_array($extraction->extracted_data)) {
                                    return 'No data extracted';
                                }
                                
                                $html = '<dl class="space-y-2">';
                                foreach ($extraction->extracted_data as $key => $value) {
                                    $label = ucwords(str_replace('_', ' ', $key));
                                    
                                    if (is_array($value)) {
                                        if (array_is_list($value)) {
                                            $displayValue = '<ul class="list-disc list-inside ml-2">';
                                            foreach ($value as $item) {
                                                $itemValue = is_scalar($item) ? $item : json_encode($item);
                                                $displayValue .= '<li>' . htmlspecialchars($itemValue) . '</li>';
                                            }
                                            $displayValue .= '</ul>';
                                        } else {
                                            $displayValue = '<div class="ml-2 space-y-1">';
                                            foreach ($value as $subKey => $subValue) {
                                                $subLabel = ucwords(str_replace('_', ' ', $subKey));
                                                $subDisplayValue = is_scalar($subValue) ? $subValue : json_encode($subValue);
                                                $displayValue .= '<div><span class="font-medium text-gray-600 dark:text-gray-300">' . htmlspecialchars($subLabel) . ':</span> ' . htmlspecialchars($subDisplayValue) . '</div>';
                                            }
                                            $displayValue .= '</div>';
                                        }
                                    } else {
                                        $displayValue = htmlspecialchars((string)$value);
                                    }
                                    
                                    $html .= '<div class="border-b border-gray-100 dark:border-gray-700 pb-1 mb-1">';
                                    $html .= '<dt class="text-sm font-medium text-gray-700 dark:text-gray-300">' . htmlspecialchars($label) . '</dt>';
                                    $html .= '<dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">' . $displayValue . '</dd>';
                                    $html .= '</div>';
                                }
                                $html .= '</dl>';
                                
                                return new \Illuminate\Support\HtmlString($html);
                            })
                            ->extraAttributes(['class' => 'prose max-w-none']),
                    ])
                    ->columns(2)
                    ->visible(fn (Intake $record): bool => $record->extraction()->exists()),
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
            'index' => Pages\ListIntakes::route('/'),
            'create' => Pages\CreateIntake::route('/create'),
            'view' => Pages\ViewIntake::route('/{record}'),
            'edit' => Pages\EditIntake::route('/{record}/edit'),
        ];
    }
    
    /**
     * Map extraction data to format expected by Robaws service
     */
    public static function mapExtractionDataForRobaws(array $extractedData): array
    {
        // Initialize mapped data structure
        $mappedData = [
            'document_type' => 'shipping',
            'extraction_source' => 'intake_ai_extraction',
            'extracted_at' => now()->toISOString(),
        ];
        
        // Extract key sections
        $contact = $extractedData['contact'] ?? $extractedData['contact_info'] ?? [];
        $shipment = $extractedData['shipment'] ?? [];
        $vehicle = $extractedData['vehicle'] ?? $extractedData['vehicle_details'] ?? [];
        
        // Map customer information (Quotation Info section)
        if (!empty($contact)) {
            $mappedData['customer'] = $contact['name'] ?? 'Unknown Customer';
            $mappedData['endcustomer'] = $contact['name'] ?? null;
            $mappedData['contact'] = $contact['phone'] ?? $contact['phone_number'] ?? null;
            $mappedData['client_email'] = $contact['email'] ?? null;
            
            // Also keep consignee format for compatibility
            $mappedData['consignee'] = [
                'name' => $contact['name'] ?? 'Unknown Client',
                'contact' => $contact['phone'] ?? $contact['phone_number'] ?? '',
                'email' => $contact['email'] ?? '',
                'address' => $contact['address'] ?? '',
            ];
        }
        
        // Build customer reference in Robaws format: "EXP RORO - BRU - JED - 1 x Used BMW 7"
        $mappedData['customer_reference'] = self::buildCustomerReference($extractedData);
        
        // Map routing information (ROUTING section)
        $origin = $shipment['origin'] ?? self::extractOriginFromMessages($extractedData);
        $destination = $shipment['destination'] ?? self::extractDestinationFromMessages($extractedData);
        
        $mappedData['por'] = $origin; // Port of Receipt
        $mappedData['pol'] = self::mapPortOfLoading($origin); // Port of Loading
        $mappedData['pod'] = $destination; // Port of Discharge
        $mappedData['pot'] = null; // Port of Transhipment
        $mappedData['fdest'] = null; // Final destination
        $mappedData['in_transit_to'] = null;
        
        // Also keep legacy format
        $mappedData['ports'] = [
            'origin' => $origin,
            'destination' => $destination,
        ];
        $mappedData['port_of_loading'] = $origin;
        $mappedData['port_of_discharge'] = $destination;
        
        // Map cargo details (CARGO DETAILS section)
        if (!empty($vehicle)) {
            $mappedData['cargo'] = self::buildCargoDescription($vehicle);
            
            // Calculate and format dimensions
            $dimensions = $vehicle['dimensions'] ?? [];
            if (!empty($dimensions)) {
                $length = floatval($dimensions['length_m'] ?? 0);
                $width = floatval($dimensions['width_m'] ?? 0);
                $height = floatval($dimensions['height_m'] ?? 0);
                
                if ($length > 0 && $width > 0 && $height > 0) {
                    $volume = $length * $width * $height;
                    $mappedData['dim_bef_delivery'] = sprintf('%.3f x %.2f x %.3f m // %.2f Cbm', 
                        $length, $width, $height, $volume);
                    $mappedData['volume_m3'] = $volume;
                }
            }
            
            // Vehicle specifications
            $mappedData['vehicle_brand'] = $vehicle['brand'] ?? $vehicle['make'] ?? null;
            $mappedData['vehicle_model'] = $vehicle['model'] ?? null;
            $mappedData['vehicle_year'] = $vehicle['year'] ?? null;
            $mappedData['vehicle_color'] = $vehicle['color'] ?? null;
            $mappedData['weight_kg'] = $vehicle['weight_kg'] ?? null;
            $mappedData['engine_cc'] = $vehicle['engine_cc'] ?? null;
            $mappedData['fuel_type'] = $vehicle['fuel_type'] ?? null;
            
            // Keep vehicles array for compatibility
            $mappedData['vehicles'] = [
                [
                    'make' => $vehicle['brand'] ?? $vehicle['make'] ?? '',
                    'model' => $vehicle['model'] ?? '',
                    'year' => $vehicle['year'] ?? '',
                    'type' => $vehicle['type'] ?? '',
                    'condition' => $vehicle['condition'] ?? '',
                    'color' => $vehicle['color'] ?? '',
                    'vin' => $vehicle['vin'] ?? '',
                    'specifications' => $vehicle['specifications'] ?? '',
                ]
            ];
        }
        
        // Service details
        $mappedData['freight_type'] = 'RoRo Vehicle Transport';
        $mappedData['shipment_type'] = 'RoRo';
        $mappedData['container_type'] = 'RoRo';
        $mappedData['container_quantity'] = 1;
        $mappedData['container_nr'] = null; // Not applicable for RoRo
        
        // Map dates
        $dates = $extractedData['dates'] ?? [];
        if (!empty($dates)) {
            $mappedData['departure_date'] = $dates['pickup_date'] ?? null;
            $mappedData['arrival_date'] = $dates['delivery_date'] ?? null;
            $mappedData['pickup_date'] = $dates['pickup_date'] ?? null;
            $mappedData['delivery_date'] = $dates['delivery_date'] ?? null;
        }
        
        // Trade terms
        $mappedData['incoterms'] = $extractedData['incoterms'] ?? 'CIF';
        $mappedData['payment_terms'] = $extractedData['payment_terms'] ?? null;
        
        // Email metadata (if from .eml file)
        if (isset($extractedData['email_metadata'])) {
            $mappedData['email_metadata'] = $extractedData['email_metadata'];
            $mappedData['email_subject'] = $extractedData['email_metadata']['subject'] ?? null;
            $mappedData['email_from'] = $extractedData['email_metadata']['from'] ?? null;
            $mappedData['email_to'] = $extractedData['email_metadata']['to'] ?? null;
            $mappedData['email_date'] = $extractedData['email_metadata']['date'] ?? null;
        }
        
        // Special requirements and notes (INTERNAL REMARKS section)
        $mappedData['special_requirements'] = $extractedData['special_requirements'] ?? 
                                            $extractedData['special_instructions'] ?? null;
        $mappedData['reference_number'] = $extractedData['reference_number'] ?? 
                                        $extractedData['invoice_number'] ?? null;
        
        // Build notes from messages
        if (isset($extractedData['messages']) && !empty($extractedData['messages'])) {
            $messageTexts = [];
            foreach ($extractedData['messages'] as $message) {
                if (isset($message['text'])) {
                    $sender = $message['sender'] ?? 'User';
                    $messageTexts[] = "{$sender}: {$message['text']}";
                }
            }
            if (!empty($messageTexts)) {
                $mappedData['internal_remarks'] = implode("\n", $messageTexts);
            }
        }
        
        // Email subject as note if available
        if (isset($extractedData['email_metadata']['subject'])) {
            $mappedData['notes'] = "Email: " . $extractedData['email_metadata']['subject'];
        }
        
        // Vehicle verification
        $mappedData['database_match'] = $vehicle['database_match'] ?? false;
        $mappedData['verified_specs'] = $vehicle['verified_specs'] ?? false;
        $mappedData['spec_id'] = $vehicle['spec_id'] ?? null;
        
        // Metadata
        $mappedData['extraction_confidence'] = $extractedData['metadata']['confidence_score'] ?? 
                                             $extractedData['confidence_score'] ?? null;
        $mappedData['formatted_at'] = now()->toISOString();
        $mappedData['source'] = 'bconnect_ai_extraction';
        $mappedData['original_extraction'] = $extractedData;

        return $mappedData;
    }
    
    /**
     * Build customer reference in Robaws format
     */
    private static function buildCustomerReference(array $extractedData): string
    {
        $parts = [];
        
        // Add export type
        $parts[] = 'EXP RORO';
        
        // Add route info
        $origin = $extractedData['shipment']['origin'] ?? self::extractOriginFromMessages($extractedData);
        $destination = $extractedData['shipment']['destination'] ?? self::extractDestinationFromMessages($extractedData);
        
        if ($origin && $destination) {
            // Simplify location names for reference
            $originShort = self::simplifyLocationName($origin);
            $destinationShort = self::simplifyLocationName($destination);
            $parts[] = $originShort . ' - ' . $destinationShort;
        }
        
        // Add vehicle info
        $vehicle = $extractedData['vehicle'] ?? [];
        if (!empty($vehicle)) {
            $vehicleDesc = '1 x ';
            if (!empty($vehicle['condition'])) {
                $vehicleDesc .= ucfirst($vehicle['condition']) . ' ';
            }
            $vehicleDesc .= ($vehicle['brand'] ?? 'Vehicle') . ' ' . ($vehicle['model'] ?? '');
            $parts[] = $vehicleDesc;
        }
        
        return implode(' - ', array_filter($parts));
    }
    
    /**
     * Build cargo description for Robaws
     */
    private static function buildCargoDescription(array $vehicle): string
    {
        if (empty($vehicle)) {
            return '1 x Vehicle';
        }
        
        $description = '1 x ';
        
        if (!empty($vehicle['condition'])) {
            $description .= ucfirst($vehicle['condition']) . ' ';
        }
        
        $description .= ($vehicle['brand'] ?? 'Vehicle');
        
        if (!empty($vehicle['model'])) {
            $description .= ' ' . $vehicle['model'];
        }
        
        return $description;
    }
    
    /**
     * Map Port of Receipt to appropriate Port of Loading
     */
    private static function mapPortOfLoading(?string $por): ?string
    {
        if (!$por) return null;
        
        // Common mappings from city to actual port
        $portMappings = [
            'Brussels' => 'Antwerp',
            'Bruxelles' => 'Antwerp',
            'Antwerp' => 'Antwerp',
            'Anvers' => 'Antwerp',
            'Rotterdam' => 'Rotterdam',
            'Hamburg' => 'Hamburg',
            'Bremerhaven' => 'Bremerhaven',
        ];
        
        foreach ($portMappings as $city => $port) {
            if (stripos($por, $city) !== false) {
                return $port;
            }
        }
        
        return $por; // Return original if no mapping found
    }
    
    /**
     * Simplify location names for reference
     */
    private static function simplifyLocationName(string $location): string
    {
        $simplifications = [
            'Brussels, Belgium' => 'BRU',
            'Bruxelles, Belgium' => 'BRU', 
            'Djeddah, Saudi Arabia' => 'JED',
            'Jeddah, Saudi Arabia' => 'JED',
            'Antwerp, Belgium' => 'ANR',
            'Rotterdam, Netherlands' => 'RTM',
        ];
        
        return $simplifications[$location] ?? $location;
    }
    
    /**
     * Extract origin from messages if not in shipment
     */
    private static function extractOriginFromMessages(array $extractedData): ?string
    {
        if (!isset($extractedData['messages'])) {
            return null;
        }
        
        foreach ($extractedData['messages'] as $message) {
            if (isset($message['text'])) {
                if (preg_match('/from\s+([^to]+?)\s+to\s+/i', $message['text'], $matches)) {
                    return trim($matches[1]);
                }
            }
        }
        
        return null;
    }
    
    /**
     * Extract destination from messages if not in shipment
     */
    private static function extractDestinationFromMessages(array $extractedData): ?string
    {
        if (!isset($extractedData['messages'])) {
            return null;
        }
        
        foreach ($extractedData['messages'] as $message) {
            if (isset($message['text'])) {
                if (preg_match('/to\s+([^,\.\n]+)/i', $message['text'], $matches)) {
                    return trim($matches[1]);
                }
            }
        }
        
        return null;
    }

    /**
     * Extract numeric value from string
     */
    private static function extractNumericValue(string $value): float
    {
        // Remove currency symbols and extra characters
        $cleaned = preg_replace('/[^\d\.,]/', '', $value);
        
        // Convert comma to dot for decimal
        $cleaned = str_replace(',', '.', $cleaned);
        
        return floatval($cleaned);
    }
}
