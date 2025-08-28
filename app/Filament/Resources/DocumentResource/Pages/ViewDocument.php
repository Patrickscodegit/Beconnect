<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Services\Display\ExtractionDataFormatter;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\ViewEntry;

class ViewDocument extends ViewRecord
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Document Information')
                    ->schema([
                        TextEntry::make('filename')
                            ->label('File Name')
                            ->icon('heroicon-o-document'),
                        
                        TextEntry::make('file_path')
                            ->label('File Path')
                            ->icon('heroicon-o-folder'),
                        
                        TextEntry::make('mime_type')
                            ->label('MIME Type')
                            ->icon('heroicon-o-document-text'),
                        
                        TextEntry::make('file_size')
                            ->label('File Size')
                            ->formatStateUsing(fn (int $state): string => number_format($state / 1024, 1) . ' KB')
                            ->icon('heroicon-o-scale'),
                        
                        TextEntry::make('page_count')
                            ->label('Page Count')
                            ->icon('heroicon-o-document-duplicate'),
                        
                        TextEntry::make('document_type')
                            ->label('Document Type')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'invoice' => 'success',
                                'bill_of_lading' => 'info',
                                'customs_declaration' => 'warning',
                                'shipping_manifest' => 'primary',
                                'packing_list' => 'secondary',
                                default => 'gray',
                            })
                            ->icon('heroicon-o-tag'),
                        
                        TextEntry::make('has_text_layer')
                            ->label('OCR Ready')
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')
                            ->color(fn (bool $state): string => $state ? 'success' : 'danger')
                            ->icon(fn (bool $state): string => $state ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle'),
                        
                        TextEntry::make('created_at')
                            ->label('Uploaded')
                            ->dateTime()
                            ->since()
                            ->icon('heroicon-o-clock'),
                    ])
                    ->columns(2),

                Section::make('Extracted Data')
                    ->schema([
                        ViewEntry::make('extraction_data')
                            ->label('')
                            ->view('filament.components.extraction-results-display')
                            ->state(function ($record) {
                                // Get the extraction for this document's intake
                                $extraction = $record->intake?->extraction;
                                
                                if (!$extraction || !$extraction->extracted_data) {
                                    return [];
                                }
                                
                                return $extraction->extracted_data;
                            })
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($record) => $record->intake?->extraction?->extracted_data !== null)
                    ->collapsible()
                    ->collapsed(false),

                Section::make('Intake Information')
                    ->schema([
                        TextEntry::make('intake.id')
                            ->label('Intake ID')
                            ->icon('heroicon-o-hashtag'),
                        
                        TextEntry::make('intake.status')
                            ->label('Processing Status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'pending' => 'warning',
                                'processing' => 'info',
                                'completed' => 'success',
                                'failed' => 'danger',
                                default => 'gray',
                            })
                            ->icon('heroicon-o-cog'),
                        
                        TextEntry::make('intake.source')
                            ->label('Source')
                            ->badge()
                            ->icon('heroicon-o-arrow-down-tray'),
                        
                        TextEntry::make('intake.priority')
                            ->label('Priority')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'low' => 'gray',
                                'normal' => 'info',
                                'high' => 'warning',
                                'urgent' => 'danger',
                                default => 'gray',
                            })
                            ->icon('heroicon-o-exclamation-triangle'),
                        
                        TextEntry::make('intake.notes')
                            ->label('Notes')
                            ->placeholder('No notes')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }
}
