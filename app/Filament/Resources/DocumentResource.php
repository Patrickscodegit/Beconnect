<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentResource\Pages;
use App\Models\Document;
use App\Models\Intake;
use App\Services\DocumentService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Support\Enums\FontWeight;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    
    protected static ?string $navigationLabel = 'Documents';
    
    protected static ?string $modelLabel = 'Document';
    
    protected static ?string $pluralModelLabel = 'Documents';
    
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Document Upload')
                    ->schema([
                        Forms\Components\Select::make('intake_id')
                            ->label('Intake')
                            ->relationship('intake', 'id')
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                Forms\Components\Select::make('status')
                                    ->options([
                                        'pending' => 'Pending',
                                        'processing' => 'Processing',
                                        'completed' => 'Completed',
                                        'failed' => 'Failed',
                                    ])
                                    ->default('pending')
                                    ->required(),
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
                                    ->rows(3)
                                    ->placeholder('Additional notes about this intake...'),
                            ])
                            ->required()
                            ->columnSpanFull(),
                            
                        Forms\Components\FileUpload::make('file_upload')
                            ->label('Document File')
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/tiff'])
                            ->maxSize(10240) // 10MB
                            ->disk('minio')
                            ->directory('documents')
                            ->visibility('private')
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state instanceof TemporaryUploadedFile) {
                                    $set('filename', $state->getClientOriginalName());
                                    $set('mime_type', $state->getMimeType());
                                    $set('file_size', $state->getSize());
                                }
                            })
                            ->hiddenOn(['edit', 'view'])
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                    
                Forms\Components\Section::make('Document Information')
                    ->schema([
                        Forms\Components\TextInput::make('filename')
                            ->required()
                            ->maxLength(255)
                            ->readonly(),
                            
                        Forms\Components\TextInput::make('file_path')
                            ->label('File Path')
                            ->required()
                            ->maxLength(255)
                            ->readonly(),
                            
                        Forms\Components\TextInput::make('mime_type')
                            ->label('MIME Type')
                            ->required()
                            ->maxLength(255)
                            ->readonly(),
                            
                        Forms\Components\TextInput::make('file_size')
                            ->label('File Size (bytes)')
                            ->required()
                            ->numeric()
                            ->readonly(),
                            
                        Forms\Components\TextInput::make('page_count')
                            ->label('Page Count')
                            ->numeric()
                            ->readonly(),
                            
                        Forms\Components\Toggle::make('has_text_layer')
                            ->label('Has Text Layer')
                            ->disabled(),
                            
                        Forms\Components\Select::make('document_type')
                            ->label('Document Type')
                            ->options([
                                'invoice' => 'Invoice',
                                'bill_of_lading' => 'Bill of Lading',
                                'customs_declaration' => 'Customs Declaration',
                                'shipping_manifest' => 'Shipping Manifest',
                                'packing_list' => 'Packing List',
                                'other' => 'Other',
                            ])
                            ->searchable(),
                    ])
                    ->columns(2)
                    ->visibleOn(['edit', 'view']),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('intake.id')
                    ->label('Intake ID')
                    ->sortable()
                    ->searchable(),
                    
                Tables\Columns\TextColumn::make('filename')
                    ->searchable()
                    ->limit(30)
                    ->tooltip(function (Tables\Columns\TextColumn $column): ?string {
                        $state = $column->getState();
                        if (strlen($state) <= 30) {
                            return null;
                        }
                        return $state;
                    }),
                    
                Tables\Columns\TextColumn::make('document_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'invoice' => 'success',
                        'bill_of_lading' => 'info',
                        'customs_declaration' => 'warning',
                        'shipping_manifest' => 'primary',
                        'packing_list' => 'secondary',
                        default => 'gray',
                    }),
                    
                Tables\Columns\TextColumn::make('file_size')
                    ->label('Size')
                    ->formatStateUsing(fn (int $state): string => number_format($state / 1024, 1) . ' KB')
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('page_count')
                    ->label('Pages')
                    ->numeric()
                    ->sortable(),
                    
                Tables\Columns\IconColumn::make('has_text_layer')
                    ->label('OCR Ready')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),
                    
                Tables\Columns\TextColumn::make('intake.status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'processing' => 'info',
                        'completed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                    
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Uploaded')
                    ->dateTime()
                    ->sortable()
                    ->since(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('document_type')
                    ->options([
                        'invoice' => 'Invoice',
                        'bill_of_lading' => 'Bill of Lading',
                        'customs_declaration' => 'Customs Declaration',
                        'shipping_manifest' => 'Shipping Manifest',
                        'packing_list' => 'Packing List',
                        'other' => 'Other',
                    ]),
                    
                Tables\Filters\SelectFilter::make('intake.status')
                    ->label('Status')
                    ->relationship('intake', 'status')
                    ->options([
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ]),
                    
                Tables\Filters\Filter::make('has_text_layer')
                    ->toggle()
                    ->query(fn ($query) => $query->where('has_text_layer', true)),
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function (Document $record) {
                        if (Storage::disk('minio')->exists($record->file_path)) {
                            return Storage::disk('minio')->download($record->file_path, $record->filename);
                        }
                        
                        Notification::make()
                            ->title('File not found')
                            ->danger()
                            ->send();
                    }),
                    
                Tables\Actions\Action::make('reprocess')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (Document $record) {
                        try {
                            $documentService = app(DocumentService::class);
                            $documentService->processDocument($record->file_path, $record->intake_id);
                            
                            Notification::make()
                                ->title('Document reprocessing started')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Reprocessing failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                    
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    
                    Tables\Actions\BulkAction::make('reprocess_selected')
                        ->label('Reprocess Selected')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $documentService = app(DocumentService::class);
                            $processed = 0;
                            
                            foreach ($records as $record) {
                                try {
                                    $documentService->processDocument($record->file_path, $record->intake_id);
                                    $processed++;
                                } catch (\Exception $e) {
                                    // Continue with other documents
                                }
                            }
                            
                            Notification::make()
                                ->title("Reprocessing started for {$processed} documents")
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
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
            'index' => Pages\ListDocuments::route('/'),
            'create' => Pages\CreateDocument::route('/create'),
            'view' => Pages\ViewDocument::route('/{record}'),
            'edit' => Pages\EditDocument::route('/{record}/edit'),
        ];
    }
}
