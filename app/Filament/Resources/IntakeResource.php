<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IntakeResource\Pages;
use App\Models\Intake;
use App\Models\Document;
use App\Models\Extraction;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;
use App\Services\RobawsExportService;
use App\Jobs\ProcessIntake;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

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
                                'processed' => 'Processed',
                                'needs_contact' => 'Needs Contact',
                                'export_failed' => 'Export Failed',
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
                    
                Forms\Components\Section::make('Contact Information (Optional)')
                    ->description('Pre-seed contact information if known. This will be merged with extracted data.')
                    ->schema([
                        Forms\Components\TextInput::make('customer_name')
                            ->label('Customer Name')
                            ->placeholder('Customer or company name'),
                            
                        Forms\Components\TextInput::make('contact_email')
                            ->label('Contact Email')
                            ->email()
                            ->placeholder('customer@example.com'),
                            
                        Forms\Components\TextInput::make('contact_phone')
                            ->label('Contact Phone')
                            ->tel()
                            ->placeholder('+1 (555) 123-4567'),
                    ])
                    ->columns(3)
                    ->collapsible(),
                    
                Forms\Components\Section::make('File Upload')
                    ->schema([
                        Forms\Components\FileUpload::make('intake_files')
                            ->label('Upload Files')
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
                            ->storeFiles(false) // Let our service handle storage
                            ->helperText('Upload freight documents (PDF, images, email files). Maximum 20MB per file. Files will be processed for contact info and freight data.')
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
                        'processed' => 'success',
                        'needs_contact' => 'warning',
                        'export_failed' => 'danger',
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
                    
                Tables\Columns\TextColumn::make('files_count')
                    ->label('Files')
                    ->counts('files')
                    ->badge()
                    ->color('success'),
                    
                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Customer')
                    ->limit(30)
                    ->placeholder('—')
                    ->searchable(),
                    
                Tables\Columns\TextColumn::make('contact_email')
                    ->label('Email')
                    ->placeholder('—')
                    ->copyable()
                    ->searchable(),
                    
                Tables\Columns\TextColumn::make('contact_phone')
                    ->label('Phone')
                    ->placeholder('—')
                    ->copyable()
                    ->searchable(),
                    
                Tables\Columns\TextColumn::make('last_export_error')
                    ->label('Export Error')
                    ->limit(60)
                    ->wrap()
                    ->placeholder('—')
                    ->tooltip(fn (?Intake $record): ?string => $record?->last_export_error)
                    ->color('danger')
                    ->toggleable()
                    ->visible(fn (?Intake $record): bool => !empty($record?->last_export_error)),
                    
                Tables\Columns\ViewColumn::make('extraction_summary')
                    ->label('Extraction')
                    ->view('filament.tables.extraction-summary')
                    ->state(function (?Intake $record) {
                        if (!$record || !$record->extraction) {
                            return null;
                        }
                        
                        $extraction = $record->extraction;
                        $extractedData = $extraction->extracted_data ?? [];
                        
                        // Use our ContactFieldExtractor to get formatted contact data
                        try {
                            $contactExtractor = new \App\Services\Extraction\Strategies\Fields\ContactFieldExtractor();
                            $contactInfo = $contactExtractor->extract($extractedData, $extraction->raw_content ?? '');
                            
                            // Format the data for the summary view
                            return [
                                'document_data' => [
                                    'vehicle' => $extractedData['vehicle'] ?? [],
                                    'shipping' => $extractedData['shipment'] ?? [],
                                    'contact' => [
                                        'name' => $contactInfo->name,
                                        'email' => $contactInfo->email,
                                        'phone' => $contactInfo->phone,
                                        'company' => $contactInfo->company,
                                    ]
                                ],
                                'ai_enhanced_data' => [],
                                'data_attribution' => [
                                    'document_fields' => $contactInfo->sources ? $contactInfo->sources->where('source', '!=', 'messages')->pluck('source')->toArray() : [],
                                    'ai_enhanced_fields' => $contactInfo->sources ? $contactInfo->sources->where('source', 'messages')->pluck('source')->toArray() : []
                                ],
                                'metadata' => [
                                    'overall_confidence' => $contactInfo->confidence ?? 0,
                                    'extraction_timestamp' => $extraction->created_at->toISOString()
                                ]
                            ];
                        } catch (\Exception $e) {
                            \Log::warning('Extraction summary error: ' . $e->getMessage());
                            return null;
                        }
                    })
                    ->visible(fn (?Intake $record): bool => $record && $record->extraction()->exists()),
                    
                Tables\Columns\IconColumn::make('has_extraction')
                    ->label('Extracted')
                    ->getStateUsing(fn (?Intake $record): bool => $record ? $record->extraction()->exists() : false)
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->visible(fn (?Intake $record): bool => $record && !$record->extraction()->exists()),
                    
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
                Tables\Actions\Action::make('process_extraction')
                    ->label('Extract Data')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Extract Document Data')
                    ->modalDescription('This will process all documents in this intake and extract their data using AI.')
                    ->action(function (Intake $record) {
                        // Ensure database transaction is committed before dispatching job
                        \Illuminate\Support\Facades\DB::afterCommit(function () use ($record) {
                            // Dispatch to a separate queue connection to avoid transaction context issues
                            ProcessIntake::dispatchSync($record);
                        });
                        
                        Notification::make()
                            ->title('Extraction Started')
                            ->body('Document extraction has been queued for processing.')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (?Intake $record): bool => $record && $record->documents()->exists()),
                    
                Tables\Actions\Action::make('export_to_robaws')
                    ->label('Export to Robaws')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Export to Robaws')
                    ->modalDescription('This will create a new quotation in Robaws using the extracted data from this intake.')
                    ->form([
                        Forms\Components\Toggle::make('force')
                            ->label('Force Re-export')
                            ->helperText('Export even if recently exported')
                            ->default(false)
                            ->visible(fn (Intake $record): bool => $record->exported_at !== null),
                        
                        Forms\Components\Toggle::make('create_new')
                            ->label('Create New Quotation')
                            ->helperText('Create new quotation instead of updating existing')
                            ->default(false)
                            ->visible(fn (Intake $record): bool => $record->robaws_quotation_id !== null),
                    ])
                    ->action(function (Intake $record, array $data) {
                        try {
                            $exportService = app(\App\Services\Robaws\RobawsExportService::class);
                            
                            // Add progress notification
                            Notification::make()
                                ->title('Starting Robaws Export')
                                ->body('Processing intake data and mapping to Robaws format...')
                                ->info()
                                ->send();
                            
                            $result = $exportService->exportIntake($record, [
                                'force' => $data['force'] ?? false,
                                'create_new' => $data['create_new'] ?? false,
                            ]);
                            
                            if ($result['success']) {
                                // Update status for successful export
                                $record->update(['status' => 'exported']);
                                
                                $title = $result['action'] === 'updated' ? 
                                    'Robaws Quotation Updated' : 
                                    'Robaws Export Successful';
                                
                                $body = sprintf(
                                    'Quotation ID: %s | Duration: %sms | Action: %s',
                                    $result['quotation_id'],
                                    $result['duration_ms'],
                                    ucfirst($result['action'])
                                );
                                
                                Notification::make()
                                    ->title($title)
                                    ->body($body)
                                    ->success()
                                    ->persistent()
                                    ->actions([
                                        \Filament\Notifications\Actions\Action::make('view_details')
                                            ->label('View Export Details')
                                            ->action(function () use ($record, $result) {
                                                Notification::make()
                                                    ->title('Export Success Details')
                                                    ->body('Quotation ID: ' . ($result['quotation_id'] ?? 'N/A') . 
                                                          ', Duration: ' . ($result['duration_ms'] ?? 'N/A') . 'ms' .
                                                          ', Idempotency Key: ' . ($result['idempotency_key'] ?? 'N/A'))
                                                    ->success()
                                                    ->persistent()
                                                    ->send();
                                            }),
                                    ])
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Robaws Export Failed')
                                    ->body($result['error'] . (isset($result['status']) ? " (HTTP {$result['status']})" : ''))
                                    ->danger()
                                    ->persistent()
                                    ->actions([
                                        \Filament\Notifications\Actions\Action::make('view_details')
                                            ->label('View Details')
                                            ->action(function () use ($record, $result) {
                                                Notification::make()
                                                    ->title('Export Details')
                                                    ->body('Status: ' . ($result['status'] ?? 'N/A') . 
                                                          ', Error: ' . $result['error'] . 
                                                          (isset($result['data']) ? ', Response: ' . json_encode($result['data']) : ''))
                                                    ->info()
                                                    ->persistent()
                                                    ->send();
                                            }),
                                    ])
                                    ->send();
                            }
                            
                        } catch (\Exception $e) {
                            Log::error('Filament Robaws export action failed', [
                                'intake_id' => $record->id,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString(),
                            ]);
                            
                            Notification::make()
                                ->title('Unexpected Export Error')
                                ->body('An unexpected error occurred. Check logs for details.')
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    })
                    ->visible(fn (?Intake $record): bool => $record && $record->extraction !== null),
                    
                Tables\Actions\Action::make('view_extraction')
                    ->label('Extraction')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->modalHeading('Extraction Results')
                    ->modalWidth('7xl')
                    ->modalContent(function (Intake $record) {
                        $extraction = $record->extraction;
                        
                        if (!$extraction) {
                            return view('filament.modals.no-extraction');
                        }
                        
                        // Use our new professional display component
                        return view('filament.components.extraction-results-display', [
                            'extraction' => $extraction,
                            'showMetadata' => true,
                            'showRawData' => true,
                        ]);
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->visible(fn (?Intake $record): bool => $record && $record->extraction()->exists()),
                    
                Tables\Actions\Action::make('fix_contact_and_retry')
                    ->label('Fix Contact & Retry')
                    ->icon('heroicon-o-phone')
                    ->color('warning')
                    ->form([
                        Forms\Components\TextInput::make('customer_name')
                            ->label('Customer Name')
                            ->default(fn (Intake $record) => $record->customer_name),
                            
                        Forms\Components\TextInput::make('contact_email')
                            ->label('Contact Email')
                            ->email()
                            ->default(fn (Intake $record) => $record->contact_email),
                            
                        Forms\Components\TextInput::make('contact_phone')
                            ->label('Contact Phone')
                            ->tel()
                            ->default(fn (Intake $record) => $record->contact_phone),
                    ])
                    ->action(function (Intake $record, array $data) {
                        // Update extraction data contact section
                        $extractionData = (array) ($record->extraction_data ?? []);
                        $extractionData['contact'] = array_merge(
                            (array) data_get($extractionData, 'contact', []),
                            array_filter([
                                'name' => $data['customer_name'],
                                'email' => $data['contact_email'],
                                'phone' => $data['contact_phone'],
                            ])
                        );
                        
                        // Update contact information and reset export status
                        $record->update([
                            'customer_name' => $data['customer_name'],
                            'contact_email' => $data['contact_email'],
                            'contact_phone' => $data['contact_phone'],
                            'extraction_data' => $extractionData,
                            'status' => 'processed',
                            'export_attempt_count' => 0,
                            'last_export_error' => null,
                            'last_export_error_at' => null,
                        ]);
                        
                        // Retry export if we now have contact info
                        if ($data['contact_email'] || $data['contact_phone']) {
                            dispatch(new \App\Jobs\ExportIntakeToRobawsJob($record->id));
                            
                            Notification::make()
                                ->title('Contact Updated & Export Queued')
                                ->body('Contact information updated successfully. Export to Robaws has been queued for retry.')
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Contact Updated')
                                ->body('Contact information updated, but email or phone is still required for export.')
                                ->warning()
                                ->send();
                        }
                        
                        // IMPORTANT: Don't return anything - prevents [object Object] issues
                    })
                    ->visible(fn (?Intake $record): bool => 
                        $record && in_array($record->status, ['needs_contact', 'export_failed'])
                    ),
                    
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    
                    Tables\Actions\BulkAction::make('bulk_extract')
                        ->label('Extract All')
                        ->icon('heroicon-o-document-magnifying-glass')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalHeading('Bulk Extract Document Data')
                        ->modalDescription('This will process all documents in the selected intakes and extract their data using AI.')
                        ->action(function (Collection $records) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->documents()->exists()) {
                                    // Ensure database transaction is committed before dispatching job
                                    \Illuminate\Support\Facades\DB::afterCommit(function () use ($record) {
                                        // Dispatch to a separate queue connection to avoid transaction context issues
                                        ProcessIntake::dispatchSync($record);
                                    });
                                    $count++;
                                }
                            }
                            
                            Notification::make()
                                ->title('Bulk Extraction Started')
                                ->body("{$count} intakes queued for extraction processing.")
                                ->success()
                                ->send();
                        }),
                        
                    Tables\Actions\BulkAction::make('bulk_export_robaws')
                        ->label('Export All to Robaws')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Bulk Export to Robaws')
                        ->modalDescription('This will create quotations in Robaws for all extracted data in the selected intakes.')
                        ->action(function (Collection $records) {
                            $overall = [
                                'success'  => [],
                                'failed'   => [],
                                'uploaded' => [],
                                'exists'   => [],
                                'skipped'  => [],
                            ];
                            
                            foreach ($records as $record) {
                                if ($record->extraction()->exists()) {
                                    $res = app(\App\Services\Robaws\RobawsExportService::class)->exportIntake($record);

                                    $overall['success']  = array_merge($overall['success'],  $res['success']  ?? []);
                                    $overall['failed']   = array_merge($overall['failed'],   $res['failed']   ?? []);
                                    $overall['uploaded'] = array_merge($overall['uploaded'], $res['uploaded'] ?? []);
                                    $overall['exists']   = array_merge($overall['exists'],   $res['exists']   ?? []);
                                    $overall['skipped']  = array_merge($overall['skipped'],  $res['skipped']  ?? []);
                                    
                                    // Update record status if any success
                                    $stats = $res['stats'] ?? [];
                                    if (($stats['success'] ?? 0) > 0) {
                                        $record->update(['status' => 'exported']);
                                    }
                                }
                            }

                            $stats = [
                                'success'  => count($overall['success']),
                                'failed'   => count($overall['failed']),
                                'uploaded' => count($overall['uploaded']),
                                'exists'   => count($overall['exists']),
                                'skipped'  => count($overall['skipped']),
                            ];

                            Notification::make()
                                ->title('Robaws Bulk Export')
                                ->body(sprintf(
                                    'Exported: %d • Failed: %d • Uploaded: %d • Exists: %d • Skipped: %d',
                                    $stats['success'], $stats['failed'], $stats['uploaded'], $stats['exists'], $stats['skipped']
                                ))
                                ->success()
                                ->send();

                            if ($overall['failed']) {
                                Notification::make()
                                    ->title('Some items failed')
                                    ->body(collect($overall['failed'])->map(fn ($f) => $f['message'] ?? 'Unknown error')->take(3)->implode("\n"))
                                    ->danger()
                                    ->send();
                            }
                        }),
                    
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
            ->defaultSort('created_at', 'desc')
            ->poll('5s'); // Auto-refresh every 5 seconds for real-time status updates
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
                    ->visible(fn (?Intake $record): bool => $record && !empty($record->notes)),
                    
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
                    ->visible(fn (?Intake $record): bool => $record && $record->documents()->exists()),
                    
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
                    ->visible(fn (?Intake $record): bool => $record && $record->extraction()->exists()),
                    
                Infolists\Components\Section::make('Linked Quotation')
                    ->schema([
                        Infolists\Components\TextEntry::make('quotationRequest.request_number')
                            ->label('Quotation Number')
                            ->url(fn ($record) => $record->quotationRequest 
                                ? route('filament.admin.resources.quotation-requests.view', $record->quotationRequest)
                                : null
                            ),
                        Infolists\Components\TextEntry::make('quotationRequest.customer_name')
                            ->label('Customer'),
                        Infolists\Components\TextEntry::make('quotationRequest.service_type')
                            ->badge()
                            ->formatStateUsing(fn ($state) => str_replace('_', ' ', $state)),
                        Infolists\Components\TextEntry::make('quotationRequest.total_incl_vat')
                            ->label('Total Amount')
                            ->money('EUR'),
                        Infolists\Components\TextEntry::make('quotationRequest.status')
                            ->badge()
                            ->color(fn ($state) => match($state) {
                                'pending' => 'warning',
                                'processing' => 'info',
                                'quoted' => 'success',
                                'accepted' => 'success',
                                'rejected' => 'danger',
                                'expired' => 'gray',
                                default => 'gray',
                            }),
                    ])
                    ->visible(fn ($record) => $record->quotationRequest !== null)
                    ->collapsible()
                    ->collapsed(false),
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
    public static function mapExtractionDataForRobaws(array $extractedData, $extraction = null): array
    {
        // Initialize mapped data structure with enhanced metadata
        $mappedData = [
            'document_type' => 'shipping',
            'extraction_source' => 'intake_ai_extraction',
            'extracted_at' => now()->toISOString(),
            
            // Add extraction metadata for enhanced JSON export
            'metadata' => [
                'confidence_score' => $extractedData['metadata']['confidence_score'] ?? 
                                    $extractedData['confidence_score'] ?? 
                                    $extractedData['metadata']['overall_confidence'] ?? 
                                    ($extraction ? $extraction->confidence : 0),
                'strategy_used' => $extractedData['metadata']['strategy_used'] ?? 'standard',
                'extraction_pipeline_version' => '2.0',
                'timestamp' => $extraction ? $extraction->created_at->toISOString() : now()->toISOString(),
                'extraction_strategies' => $extractedData['metadata']['extraction_strategies'] ?? ['ai_extraction'],
                'extraction_id' => $extraction ? $extraction->id : null,
            ],
            
            // Preserve original extraction data for JSON export
            'original_extraction' => $extractedData,
        ];
        
        // Extract key sections
        $contact = $extractedData['contact'] ?? $extractedData['contact_info'] ?? [];
        $shipment = $extractedData['shipment'] ?? [];
        
        // Handle both nested (EML) and flat (Image) vehicle structures
        $vehicle = $extractedData['vehicle'] ?? $extractedData['vehicle_details'] ?? [];
        if (empty($vehicle) && isset($extractedData['vehicle_make'])) {
            // Convert flat image structure to nested structure for compatibility
            $vehicle = [
                'brand' => $extractedData['vehicle_make'] ?? null,
                'model' => $extractedData['vehicle_model'] ?? null,
                'year' => $extractedData['vehicle_year'] ?? null,
                'condition' => $extractedData['vehicle_condition'] ?? 'used',
                'dimensions' => [
                    'length' => $extractedData['length'] ?? null,
                    'width' => $extractedData['width'] ?? null,
                    'height' => $extractedData['height'] ?? null,
                ]
            ];
        }
        
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
        
        // Add route info - handle both nested (EML) and flat (Image) structures
        $origin = $extractedData['shipment']['origin'] ?? $extractedData['origin'] ?? self::extractOriginFromMessages($extractedData);
        $destination = $extractedData['shipment']['destination'] ?? $extractedData['destination'] ?? self::extractDestinationFromMessages($extractedData);
        
        if ($origin && $destination) {
            // Simplify location names for reference
            $originShort = self::simplifyLocationName($origin);
            $destinationShort = self::simplifyLocationName($destination);
            $parts[] = $originShort . ' - ' . $destinationShort;
        }
        
        // Add vehicle info - handle both nested (EML) and flat (Image) structures
        $vehicle = $extractedData['vehicle'] ?? [];
        $vehicleMake = $vehicle['brand'] ?? $extractedData['vehicle_make'] ?? null;
        $vehicleModel = $vehicle['model'] ?? $extractedData['vehicle_model'] ?? null;
        $vehicleCondition = $vehicle['condition'] ?? $extractedData['vehicle_condition'] ?? 'used';
        
        if ($vehicleMake && $vehicleModel) {
            $vehicleDesc = '1 x ' . ucfirst($vehicleCondition) . ' ' . $vehicleMake . ' ' . $vehicleModel;
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
