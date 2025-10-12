<?php

namespace App\Filament\Resources\IntakeResource\Pages;

use App\Filament\Resources\IntakeResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewIntake extends ViewRecord
{
    protected static string $resource = IntakeResource::class;

    // Enable automatic polling every 5 seconds for real-time status updates
    protected ?string $pollingInterval = '5s';

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('createQuotation')
                ->label('Create Quotation')
                ->icon('heroicon-o-document-plus')
                ->visible(fn () => 
                    $this->record->quotationRequest === null && 
                    in_array($this->record->status, ['completed', 'processing_complete'])
                )
                ->action(function () {
                    $intake = $this->record;
                    
                    // Extract client and contact info
                    $clientName = $intake->customer_name ?? null;
                    $contactName = $intake->contact_name ?? $intake->customer_name ?? 'Unknown Contact';
                    $contactEmail = $intake->contact_email ?? null;
                    $contactPhone = $intake->contact_phone ?? null;
                    
                    // Determine trade direction from service type
                    $serviceType = $intake->service_type ?? 'RORO_EXPORT';
                    $tradeDirection = str_contains($serviceType, 'EXPORT') ? 'export' : 'import';
                    
                    $quotation = \App\Models\QuotationRequest::create([
                        'intake_id' => $intake->id,
                        'source' => 'intake',
                        'requester_type' => 'customer',
                        
                        // Client fields (company)
                        'client_name' => $clientName,
                        'client_email' => $contactEmail, // Often same for small businesses
                        'client_tel' => $contactPhone,
                        
                        // Contact fields (person)
                        'contact_name' => $contactName,
                        'contact_email' => $contactEmail,
                        'contact_phone' => $contactPhone,
                        
                        // Service and routing
                        'service_type' => $serviceType,
                        'trade_direction' => $tradeDirection,
                        'por' => $intake->extracted_data['por'] ?? null,
                        'pol' => $intake->extracted_data['pol'] ?? null,
                        'pod' => $intake->extracted_data['pod'] ?? null,
                        'fdest' => $intake->extracted_data['fdest'] ?? null,
                        'routing' => [
                            'por' => $intake->extracted_data['por'] ?? null,
                            'pol' => $intake->extracted_data['pol'] ?? null,
                            'pod' => $intake->extracted_data['pod'] ?? null,
                            'fdest' => $intake->extracted_data['fdest'] ?? null,
                        ],
                        
                        'cargo_description' => $intake->extracted_data['cargo'] ?? 'See intake documents',
                        'cargo_details' => [],
                        'commodity_type' => 'general',
                        'pricing_currency' => 'EUR',
                        'robaws_sync_status' => 'pending',
                        'status' => 'pending',
                    ]);
                    
                    // Sync intake files to quotation
                    $filesSynced = 0;
                    foreach ($intake->files as $intakeFile) {
                        try {
                            // Copy file to quotation directory
                            $sourceDisk = $intakeFile->storage_disk ?? 'documents';
                            $sourcePath = $intakeFile->storage_path;
                            
                            // Generate new filename
                            $extension = pathinfo($intakeFile->filename, PATHINFO_EXTENSION);
                            $newFilename = time() . '_' . \Illuminate\Support\Str::random(10) . '.' . $extension;
                            $destinationPath = 'quotation_files/' . $quotation->id . '/' . $newFilename;
                            
                            // Copy the file
                            $fileContent = \Storage::disk($sourceDisk)->get($sourcePath);
                            \Storage::disk('public')->put($destinationPath, $fileContent);
                            
                            // Create quotation file record
                            $quotation->files()->create([
                                'original_filename' => $intakeFile->original_filename ?? $intakeFile->filename,
                                'filename' => $newFilename,
                                'file_path' => $destinationPath,
                                'file_size' => $intakeFile->file_size,
                                'mime_type' => $intakeFile->mime_type,
                                'file_type' => 'cargo_info',
                                'uploaded_by' => auth()->id(),
                                'description' => 'From intake #' . $intake->id,
                            ]);
                            
                            $filesSynced++;
                        } catch (\Exception $e) {
                            \Log::error('Failed to sync intake file to quotation', [
                                'intake_id' => $intake->id,
                                'quotation_id' => $quotation->id,
                                'file_id' => $intakeFile->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                    
                    \Filament\Notifications\Notification::make()
                        ->title('Quotation Created')
                        ->body("Quotation {$quotation->request_number} created successfully" . 
                               ($filesSynced > 0 ? " with {$filesSynced} file(s)" : ""))
                        ->success()
                        ->send();
                    
                    return redirect()->route('filament.admin.resources.quotation-requests.edit', $quotation);
                }),
                
            Actions\EditAction::make(),
            
            Actions\Action::make('toggle_polling')
                ->label(fn () => $this->pollingInterval ? 'Disable Auto-refresh' : 'Enable Auto-refresh')
                ->icon(fn () => $this->pollingInterval ? 'heroicon-o-pause' : 'heroicon-o-play')
                ->color(fn () => $this->pollingInterval ? 'warning' : 'success')
                ->action(function () {
                    $this->pollingInterval = $this->pollingInterval ? null : '5s';
                })
                ->tooltip('Toggle automatic status refresh'),
        ];
    }
}
