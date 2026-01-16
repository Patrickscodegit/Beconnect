<?php

namespace App\Filament\Resources\QuotationRequestResource\Pages;

use App\Filament\Resources\QuotationRequestResource;
use App\Notifications\QuotationQuotedNotification;
use App\Notifications\QuotationStatusChangedNotification;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Illuminate\Support\Facades\Notification as EmailNotification;

class ViewQuotationRequest extends ViewRecord
{
    protected static string $resource = QuotationRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            Actions\DeleteAction::make(),
            Actions\Action::make('duplicate')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->action(function () {
                    // Create new quotation with proper field handling
                    $newQuotation = $this->record->replicate();
                    
                    // Set required fields with proper values
                    $newQuotation->status = 'pending'; // Valid enum value
                    $newQuotation->customer_reference = ($this->record->customer_reference ?? '') . ' (Copy)';
                    $newQuotation->request_number = null; // Will be auto-generated
                    $newQuotation->robaws_offer_id = null; // Reset Robaws references
                    $newQuotation->robaws_offer_number = null;
                    $newQuotation->robaws_sync_status = 'pending';
                    $newQuotation->robaws_synced_at = null;
                    $newQuotation->quoted_at = null;
                    $newQuotation->expires_at = null;
                    
                    // Reset pricing to defaults
                    $newQuotation->subtotal = 0;
                    $newQuotation->discount_amount = 0;
                    $newQuotation->discount_percentage = 0;
                    $newQuotation->total_excl_vat = 0;
                    $newQuotation->vat_amount = 0;
                    $newQuotation->total_incl_vat = 0;
                    
                    $newQuotation->save();
                    
                    // Copy articles if any exist
                    if ($this->record->articles->count() > 0) {
                        foreach ($this->record->articles as $article) {
                            $newQuotation->articles()->attach($article->id, [
                                'quantity' => $article->pivot->quantity ?? 1,
                                'unit_price' => $article->pivot->unit_price ?? 0,
                                'discount_percentage' => $article->pivot->discount_percentage ?? 0,
                                'subtotal' => $article->pivot->subtotal ?? 0,
                            ]);
                        }
                    }
                    
                    \Filament\Notifications\Notification::make()
                        ->title('Quotation duplicated successfully')
                        ->success()
                        ->send();
                    
                    $this->redirect($this->getResource()::getUrl('edit', ['record' => $newQuotation]));
                }),
                
            // Workflow Actions
            Actions\Action::make('markAsQuoted')
                ->label('Mark as Quoted')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->record->status === 'processing')
                ->requiresConfirmation()
                ->modalHeading('Mark as Quoted')
                ->modalDescription('This will mark the quotation as quoted and set an expiry date.')
                ->action(function () {
                    $this->record->update([
                        'status' => 'quoted',
                        'quoted_at' => now(),
                        'expires_at' => now()->addDays(30),
                    ]);
                    
                    // Send email notification to customer
                    if ($this->record->contact_email) {
                        try {
                            EmailNotification::route('mail', $this->record->contact_email)
                                ->notify(new QuotationQuotedNotification($this->record));
                        } catch (\Exception $e) {
                            \Log::warning('Failed to send quotation quoted notification', [
                                'quotation_id' => $this->record->id,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                    
                    \Filament\Notifications\Notification::make()
                        ->success()
                        ->title('Quotation Marked as Quoted')
                        ->body('Valid until: ' . $this->record->expires_at->format('M d, Y'))
                        ->send();
                }),
                
            Actions\Action::make('markAsAccepted')
                ->label('Mark as Accepted')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn () => $this->record->status === 'quoted')
                ->requiresConfirmation()
                ->modalHeading('Mark as Accepted')
                ->modalDescription('This will mark the quotation as accepted by the customer.')
                ->action(function () {
                    $oldStatus = $this->record->status;
                    $this->record->update(['status' => 'accepted']);
                    
                    // Send email notification to customer
                    if ($this->record->contact_email) {
                        try {
                            EmailNotification::route('mail', $this->record->contact_email)
                                ->notify(new QuotationStatusChangedNotification($this->record, $oldStatus));
                        } catch (\Exception $e) {
                            \Log::warning('Failed to send quotation status changed notification', [
                                'quotation_id' => $this->record->id,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                    
                    \Filament\Notifications\Notification::make()
                        ->success()
                        ->title('Quotation Accepted')
                        ->body('The quotation has been marked as accepted.')
                        ->send();
                }),
                
            Actions\Action::make('markAsRejected')
                ->label('Mark as Rejected')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => in_array($this->record->status, ['quoted', 'processing']))
                ->requiresConfirmation()
                ->modalHeading('Mark as Rejected')
                ->modalDescription('This will mark the quotation as rejected by the customer.')
                ->action(function () {
                    $oldStatus = $this->record->status;
                    $this->record->update(['status' => 'rejected']);
                    
                    // Send email notification to customer
                    if ($this->record->contact_email) {
                        try {
                            EmailNotification::route('mail', $this->record->contact_email)
                                ->notify(new QuotationStatusChangedNotification($this->record, $oldStatus));
                        } catch (\Exception $e) {
                            \Log::warning('Failed to send quotation status changed notification', [
                                'quotation_id' => $this->record->id,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                    
                    \Filament\Notifications\Notification::make()
                        ->warning()
                        ->title('Quotation Rejected')
                        ->body('The quotation has been marked as rejected.')
                        ->send();
                }),
                
            Actions\Action::make('convertToOffer')
                ->label('Convert to Robaws Offer')
                ->icon('heroicon-o-arrow-right-circle')
                ->color('warning')
                ->visible(fn () => 
                    $this->record->status === 'quoted' && 
                    $this->record->robaws_offer_id === null &&
                    $this->record->articles()->exists()
                )
                ->requiresConfirmation()
                ->modalHeading('Convert to Robaws Offer')
                ->modalDescription('This will create an offer in Robaws with all articles and pricing. The client and contact will be synced automatically.')
                ->modalSubmitActionLabel('Create Offer')
                ->action(function () {
                    try {
                        // Use RobawsApiClient to create client, contact, and offer
                        $apiClient = app(\App\Services\Export\Clients\RobawsApiClient::class);
                        
                        // 1. Find or create client
                        $clientData = [
                            'name' => $this->record->client_name ?? $this->record->contact_name,
                            'email' => $this->record->client_email ?? $this->record->contact_email,
                            'tel' => $this->record->client_tel ?? $this->record->contact_phone,
                        ];
                        $client = $apiClient->findOrCreateClient($clientData);
                        
                        // 2. Create contact person if needed
                        if ($client && $this->record->contact_name) {
                            $contactData = [
                                'first_name' => $this->record->contact_name,
                                'email' => $this->record->contact_email,
                                'tel' => $this->record->contact_phone,
                                'function' => $this->record->contact_function,
                            ];
                            $apiClient->findOrCreateClientContact($client['id'], $contactData);
                        }
                        
                        // 3. Update the quotation with client ID
                        $this->record->update([
                            'robaws_client_id' => $client['id'] ?? null,
                            'robaws_sync_status' => 'synced',
                            'robaws_synced_at' => now(),
                        ]);
                        
                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Client Synced to Robaws')
                            ->body('Client and contact created in Robaws. Offer creation coming soon.')
                            ->send();
                            
                    } catch (\Exception $e) {
                        \Filament\Notifications\Notification::make()
                            ->danger()
                            ->title('Sync Failed')
                            ->body($e->getMessage())
                            ->send();
                            
                        \Log::error('Robaws sync failed', [
                            'quotation_id' => $this->record->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }),
        ];
    }
    
    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Customer Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('customer_name')
                            ->label('Name'),
                        Infolists\Components\TextEntry::make('customer_email')
                            ->label('Email')
                            ->icon('heroicon-m-envelope'),
                        Infolists\Components\TextEntry::make('customer_phone')
                            ->label('Phone')
                            ->icon('heroicon-m-phone'),
                        Infolists\Components\TextEntry::make('customer_company')
                            ->label('Company'),
                        Infolists\Components\TextEntry::make('customer_reference')
                            ->label('Reference'),
                        Infolists\Components\TextEntry::make('customer_type')
                            ->badge()
                            ->label('Customer Type'),
                        Infolists\Components\TextEntry::make('customer_role')
                            ->badge()
                            ->label('Customer Role'),
                    ])
                    ->columns(2),
                    
                Infolists\Components\Section::make('Route & Service')
                    ->schema([
                        Infolists\Components\TextEntry::make('service_type')
                            ->badge()
                            ->formatStateUsing(function (string $state): string {
                                $serviceTypes = config('quotation.service_types', []);
                                if (isset($serviceTypes[$state]) && is_array($serviceTypes[$state])) {
                                    return $serviceTypes[$state]['name'] ?? str_replace('_', ' ', $state);
                                }
                                return str_replace('_', ' ', $state);
                            }),
                        Infolists\Components\TextEntry::make('por')
                            ->label('Place of Receipt (POR)'),
                        Infolists\Components\TextEntry::make('pol')
                            ->label('Port of Loading (POL)'),
                        Infolists\Components\TextEntry::make('pod')
                            ->label('Port of Discharge (POD)'),
                        Infolists\Components\TextEntry::make('fdest')
                            ->label('Final Destination (FDEST)'),
                        Infolists\Components\TextEntry::make('in_transit_to')
                            ->label('In Transit To'),
                        Infolists\Components\TextEntry::make('commodity_type')
                            ->formatStateUsing(fn (?string $state): string => $state ? str_replace('_', ' ', ucfirst($state)) : 'N/A'),
                        Infolists\Components\TextEntry::make('cargo_description')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                    
                Infolists\Components\Section::make('Selected Sailing')
                    ->schema([
                        Infolists\Components\TextEntry::make('selectedSchedule.carrier.name')
                            ->label('Carrier')
                            ->icon('heroicon-m-truck')
                            ->default('No carrier selected'),
                        Infolists\Components\TextEntry::make('selectedSchedule.service_name')
                            ->label('Service')
                            ->default('N/A'),
                        Infolists\Components\TextEntry::make('selectedSchedule.transit_days')
                            ->label('Transit Time')
                            ->suffix(' days')
                            ->default('N/A'),
                        Infolists\Components\TextEntry::make('selectedSchedule.next_sailing_date')
                            ->label('Next Sailing')
                            ->date('l, F j, Y')
                            ->default('N/A'),
                        Infolists\Components\TextEntry::make('selectedSchedule.vessel_name')
                            ->label('Vessel')
                            ->default('N/A'),
                        Infolists\Components\TextEntry::make('preferred_carrier')
                            ->label('Carrier Code')
                            ->badge()
                            ->default('N/A'),
                    ])
                    ->columns(3)
                    ->visible(fn ($record) => $record->selected_schedule_id || $record->preferred_carrier)
                    ->collapsible(),
                    
                Infolists\Components\Section::make('Uploaded Files')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('files')
                            ->label('')
                            ->schema([
                                Infolists\Components\TextEntry::make('original_filename')
                                    ->label('File Name')
                                    ->icon('heroicon-m-document')
                                    ->weight('bold'),
                                Infolists\Components\TextEntry::make('file_size')
                                    ->label('Size')
                                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 1024, 2) . ' KB' : 'N/A'),
                                Infolists\Components\TextEntry::make('mime_type')
                                    ->label('Type')
                                    ->badge(),
                                Infolists\Components\TextEntry::make('created_at')
                                    ->label('Uploaded')
                                    ->dateTime('M d, Y H:i'),
                                Infolists\Components\TextEntry::make('file_path')
                                    ->label('Download')
                                    ->formatStateUsing(fn ($state, $record) => 
                                        '<a href="' . \Storage::disk('public')->url($state) . '" 
                                            target="_blank" 
                                            class="text-primary-600 hover:underline">
                                            <i class="fas fa-download"></i> Download
                                        </a>'
                                    )
                                    ->html(),
                            ])
                            ->columns(5)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(fn ($record) => $record->files->isEmpty())
                    ->visible(fn ($record) => $record->files->isNotEmpty()),
                    
                Infolists\Components\Section::make('Articles')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('articles')
                            ->label('')
                            ->schema([
                                Infolists\Components\TextEntry::make('article_name')
                                    ->label('Article'),
                                Infolists\Components\TextEntry::make('pivot.quantity')
                                    ->label('Qty'),
                                Infolists\Components\TextEntry::make('pivot.unit_price')
                                    ->money('EUR')
                                    ->label('Unit Price'),
                                Infolists\Components\TextEntry::make('pivot.discount_percentage')
                                    ->suffix('%')
                                    ->label('Discount'),
                                Infolists\Components\TextEntry::make('pivot.subtotal')
                                    ->money('EUR')
                                    ->weight('bold')
                                    ->label('Subtotal'),
                            ])
                            ->columns(5)
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),

                Infolists\Components\Section::make('Carrier Clauses')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('carrier_clauses')
                            ->label('')
                            ->schema([
                                Infolists\Components\TextEntry::make('clause_type')
                                    ->label('Type')
                                    ->badge(),
                                Infolists\Components\TextEntry::make('text')
                                    ->label('Clause')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                    ])
                    ->collapsed()
                    ->visible(fn ($record) => !empty($record->carrier_clauses)),
                    
                Infolists\Components\Section::make('Pricing')
                    ->schema([
                        Infolists\Components\TextEntry::make('subtotal_amount')
                            ->money('EUR')
                            ->label('Subtotal'),
                        Infolists\Components\TextEntry::make('discount_percentage')
                            ->suffix('%')
                            ->label('Discount'),
                        Infolists\Components\TextEntry::make('discount_amount')
                            ->money('EUR')
                            ->label('Discount Amount'),
                        Infolists\Components\TextEntry::make('vat_rate')
                            ->suffix('%')
                            ->label('VAT Rate'),
                        Infolists\Components\TextEntry::make('vat_amount')
                            ->money('EUR')
                            ->label('VAT Amount'),
                        Infolists\Components\TextEntry::make('total_amount')
                            ->money('EUR')
                            ->weight('bold')
                            ->size(Infolists\Components\TextEntry\TextEntrySize::Large)
                            ->label('Total Amount'),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('General Conditions')
                    ->schema([
                        Infolists\Components\TextEntry::make('general_conditions_note')
                            ->label('')
                            ->state(function () {
                                return implode('<br><br>', [
                                    'All services and operations performed by Belgaco BV are subject to the terms outlined in the most recent version of the following conditions, as applicable:',
                                    '<strong>Maritime services:</strong> The General Conditions of the Belgian Forwarders for all our maritime related services. Full details can be found at: <a href=\"https://www.belgaco-shipping.com/terms-and-conditions\" target=\"_blank\" rel=\"noopener\">https://www.belgaco-shipping.com/terms-and-conditions</a>',
                                    '<strong>Road transport services:</strong> The Convention on the Contract for the International Carriage of Goods by Road (CMR), governing international road transport agreements.',
                                    '<strong>Standard conditions:</strong> Belgian Freight Forwarders - Standard Trading Conditions (Free translation)-11.pdf',
                                ]);
                            })
                            ->html()
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
                    
                Infolists\Components\Section::make('Status & Dates')
                    ->schema([
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'draft' => 'gray',
                                'pending_review' => 'warning',
                                'sent' => 'info',
                                'approved' => 'success',
                                'rejected' => 'danger',
                                'expired' => 'gray',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('valid_until')
                            ->date(),
                        Infolists\Components\TextEntry::make('created_at')
                            ->dateTime(),
                        Infolists\Components\TextEntry::make('updated_at')
                            ->dateTime(),
                    ])
                    ->columns(4),
                    
                Infolists\Components\Section::make('Templates')
                    ->schema([
                        Infolists\Components\TextEntry::make('introTemplate.template_name')
                            ->label('Introduction Template')
                            ->default('None'),
                        Infolists\Components\TextEntry::make('endTemplate.template_name')
                            ->label('End Text Template')
                            ->default('None'),
                    ])
                    ->columns(2)
                    ->collapsible(),
                    
                Infolists\Components\Section::make('Internal Notes')
                    ->schema([
                        Infolists\Components\TextEntry::make('internal_notes')
                            ->label('')
                            ->default('No notes')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }
}

