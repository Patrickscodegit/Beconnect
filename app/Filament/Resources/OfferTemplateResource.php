<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OfferTemplateResource\Pages;
use App\Models\OfferTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OfferTemplateResource extends Resource
{
    protected static ?string $model = OfferTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    
    protected static ?string $navigationLabel = 'Offer Templates';
    
    protected static ?string $modelLabel = 'Template';
    
    protected static ?string $pluralModelLabel = 'Offer Templates';
    
    protected static ?string $navigationGroup = 'Quotation System';
    
    protected static ?int $navigationSort = 13;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Template Information')
                    ->schema([
                        Forms\Components\TextInput::make('template_name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),
                            
                        Forms\Components\Select::make('template_type')
                            ->options([
                                'introduction' => 'Introduction Text',
                                'end_text' => 'End Text',
                            ])
                            ->required()
                            ->columnSpan(1),
                            
                        Forms\Components\CheckboxList::make('service_types')
                            ->options(function () {
                                $serviceTypes = config('quotation.service_types', []);
                                $options = [];
                                foreach ($serviceTypes as $key => $value) {
                                    if (is_array($value)) {
                                        $options[$key] = $value['name'] ?? $key;
                                    } else {
                                        $options[$key] = $value;
                                    }
                                }
                                return $options;
                            })
                            ->columns(3)
                            ->columnSpanFull()
                            ->helperText('Select which service types can use this template (leave empty for all)'),
                            
                        Forms\Components\Toggle::make('is_active')
                            ->default(true)
                            ->columnSpan(1),
                    ])
                    ->columns(2),
                    
                Forms\Components\Section::make('Template Content')
                    ->schema([
                        Forms\Components\RichEditor::make('content')
                            ->required()
                            ->columnSpanFull()
                            ->toolbarButtons([
                                'bold',
                                'italic',
                                'underline',
                                'bulletList',
                                'orderedList',
                                'link',
                                'undo',
                                'redo',
                            ])
                            ->helperText('Use variables like {customerName}, {serviceType}, {pol}, {pod}, {totalAmount}, etc.'),
                    ]),
                    
                Forms\Components\Section::make('Available Variables')
                    ->schema([
                        Forms\Components\Placeholder::make('variables_help')
                            ->label('')
                            ->content('
                                **Customer Variables:**  
                                {customerName}, {contactPersonName}, {customerEmail}, {customerPhone}, {customerCompany}
                                
                                **Route Variables:**  
                                {por}, {pol}, {pod}, {fdest}, {route}
                                
                                **Service Variables:**  
                                {serviceType}, {commodity}
                                
                                **Pricing Variables:**  
                                {subtotalAmount}, {totalAmount}, {vatRate}, {discount}
                                
                                **Date Variables:**  
                                {today}, {validUntil}, {quotationDate}
                            ')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('template_name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                    
                Tables\Columns\TextColumn::make('template_type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'introduction' => 'info',
                        'end_text' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', ucfirst($state)))
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('service_types')
                    ->badge()
                    ->formatStateUsing(fn (?array $state): string => 
                        $state && count($state) > 0 ? count($state) . ' services' : 'All services'
                    )
                    ->tooltip(function (?array $state): ?string {
                        if ($state && count($state) > 0) {
                            return implode(', ', array_map(fn($s) => str_replace('_', ' ', $s), $state));
                        }
                        return 'Available for all service types';
                    }),
                    
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('template_type')
                    ->options([
                        'introduction' => 'Introduction',
                        'end_text' => 'End Text',
                    ]),
                    
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status')
                    ->placeholder('All templates')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),
            ])
            ->actions([
                Tables\Actions\Action::make('preview')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn ($record) => 'Preview: ' . $record->template_name)
                    ->modalContent(fn ($record) => view('filament.modals.template-preview', [
                        'template' => $record,
                        'rendered' => \App\Services\Quotation\OfferTemplateService::renderTemplate(
                            $record->template_content,
                            [
                                'customerName' => 'John Doe Company BV',
                                'contactPersonName' => 'John Doe',
                                'customerEmail' => 'john.doe@example.com',
                                'customerPhone' => '+32 123 456 789',
                                'customerCompany' => 'John Doe Company BV',
                                'serviceType' => 'RORO Export',
                                'por' => 'Antwerp',
                                'pol' => 'Port of Antwerp',
                                'pod' => 'Port of Lagos',
                                'fdest' => 'Lagos',
                                'route' => 'Antwerp → Port of Antwerp → Port of Lagos → Lagos',
                                'commodity' => 'Vehicles',
                                'subtotalAmount' => '€1,320.00',
                                'totalAmount' => '€1,597.20',
                                'vatRate' => '21%',
                                'discount' => '5%',
                                'today' => now()->format('d F Y'),
                                'validUntil' => now()->addDays(30)->format('d F Y'),
                                'quotationDate' => now()->format('d F Y'),
                            ]
                        )
                    ]))
                    ->modalWidth('3xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\Action::make('duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->action(function (OfferTemplate $record) {
                        $newTemplate = $record->replicate();
                        $newTemplate->name = $record->name . ' (Copy)';
                        $newTemplate->is_active = false;
                        $newTemplate->save();
                        
                        return redirect()->route('filament.admin.resources.offer-templates.edit', ['record' => $newTemplate]);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
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
            'index' => Pages\ListOfferTemplates::route('/'),
            'create' => Pages\CreateOfferTemplate::route('/create'),
            'edit' => Pages\EditOfferTemplate::route('/{record}/edit'),
        ];
    }
}

