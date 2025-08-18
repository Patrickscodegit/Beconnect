<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IntakeResource\Pages;
use App\Models\Intake;
use App\Models\Document;
use App\Models\Extraction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Filament\Infolists\Infolist;

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
                        Infolists\Components\TextEntry::make('extraction.verified_at')
                            ->label('Verified At')
                            ->dateTime()
                            ->placeholder('Not verified'),
                        Infolists\Components\KeyValueEntry::make('extraction.raw_json')
                            ->label('Extracted Data')
                            ->keyLabel('Field')
                            ->valueLabel('Value'),
                    ])
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
}
