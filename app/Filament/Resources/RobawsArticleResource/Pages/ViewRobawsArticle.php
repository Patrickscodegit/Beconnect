<?php

namespace App\Filament\Resources\RobawsArticleResource\Pages;

use App\Filament\Resources\RobawsArticleResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;

class ViewRobawsArticle extends ViewRecord
{
    protected static string $resource = RobawsArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            Actions\DeleteAction::make(),
        ];
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
                        Infolists\Components\TextEntry::make('customer_type')
                            ->badge()
                            ->placeholder('All Customer Types'),
                        Infolists\Components\TextEntry::make('carriers')
                            ->badge()
                            ->formatStateUsing(function ($state): string {
                                // Handle both array and JSON string cases
                                if (is_string($state)) {
                                    $decoded = json_decode($state, true);
                                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                        if (count($decoded) > 0) {
                                            return implode(', ', $decoded);
                                        }
                                        return 'All Carriers';
                                    }
                                    return 'Invalid data format';
                                }
                                if (is_array($state) && count($state) > 0) {
                                    return implode(', ', $state);
                                }
                                return 'All Carriers';
                            }),
                        Infolists\Components\IconEntry::make('is_parent_article')
                            ->boolean()
                            ->label('Is Parent Article'),
                        Infolists\Components\IconEntry::make('is_surcharge')
                            ->boolean()
                            ->label('Is Surcharge'),
                    ])
                    ->columns(2),
                    
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

