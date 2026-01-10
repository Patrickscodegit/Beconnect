<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RobawsSupplierCacheResource\Pages;
use App\Filament\Resources\RobawsSupplierCacheResource\RelationManagers;
use App\Models\RobawsSupplierCache;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;
use Filament\Notifications\Notification;

class RobawsSupplierCacheResource extends Resource
{
    protected static ?string $model = RobawsSupplierCache::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';
    
    protected static ?string $navigationLabel = 'Robaws Suppliers';
    
    protected static ?string $navigationGroup = 'Robaws Data';

    protected static ?string $pollingInterval = '30s'; // Auto-refresh table

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Supplier Information')
            ->schema([
                Forms\Components\TextInput::make('robaws_supplier_id')
                            ->label('Robaws Supplier ID')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->disabledOn('edit'), // Disable editing ID
                Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('code')
                            ->label('Supplier Code')
                            ->maxLength(255),
                        Forms\Components\Select::make('supplier_type')
                            ->options([
                                'shipping_line' => 'Shipping Line',
                                'vendor' => 'Vendor',
                                'carrier' => 'Carrier',
                                'forwarder' => 'Forwarder',
                                'broker' => 'Broker',
                            ])
                            ->searchable(),
                    ])->columns(2),
                    
                Forms\Components\Section::make('Contact Information')
                    ->schema([
                Forms\Components\TextInput::make('email')
                            ->email()
                            ->maxLength(255),
                Forms\Components\TextInput::make('phone')
                            ->tel()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('mobile')
                            ->tel()
                            ->maxLength(255),
                Forms\Components\Textarea::make('address')
                            ->maxLength(65535),
                        Forms\Components\TextInput::make('street')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('street_number')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('city')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('postal_code')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('country')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('country_code')
                            ->maxLength(2),
                        Forms\Components\TextInput::make('vat_number')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('website')
                            ->url()
                            ->maxLength(255),
                    ])->columns(2),
                    
                Forms\Components\Section::make('Settings')
                    ->schema([
                        Forms\Components\TextInput::make('language')
                            ->maxLength(10),
                Forms\Components\TextInput::make('currency')
                            ->maxLength(3)
                            ->default('EUR'),
                        Forms\Components\Select::make('supplier_category')
                            ->options([
                                'company' => 'Company',
                                'individual' => 'Individual',
                            ])
                            ->default('company'),
                Forms\Components\Toggle::make('is_active')
                            ->default(true),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('robaws_supplier_id')
                    ->label('Supplier ID')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('supplier_type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'shipping_line' => 'primary',
                        'vendor' => 'success',
                        'carrier' => 'info',
                        'forwarder' => 'warning',
                        'broker' => 'secondary',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('phone')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('city')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('country')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('shipping_carriers_count')
                    ->counts('shippingCarriers')
                    ->label('Linked Carriers')
                    ->sortable(),
                Tables\Columns\TextColumn::make('contacts_count')
                    ->counts('contacts')
                    ->label('Contacts')
                    ->sortable(),
                Tables\Columns\TextColumn::make('primary_contact')
                    ->label('Primary Contact')
                    ->getStateUsing(fn (RobawsSupplierCache $record) => $record->primaryContact?->full_name ?? 'N/A')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_synced_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_pushed_to_robaws_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('supplier_type')
                    ->options([
                        'shipping_line' => 'Shipping Line',
                        'vendor' => 'Vendor',
                        'carrier' => 'Carrier',
                        'forwarder' => 'Forwarder',
                        'broker' => 'Broker',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active')
                    ->placeholder('All suppliers')
                    ->trueLabel('Active suppliers')
                    ->falseLabel('Inactive suppliers'),
                Tables\Filters\Filter::make('country')
                    ->form([
                        Forms\Components\TextInput::make('country')
                            ->placeholder('Enter country name'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query->when(
                            $data['country'],
                            fn ($query, $country) => $query->where('country', 'like', "%{$country}%")
                        );
                    }),
                Tables\Filters\Filter::make('has_email')
                    ->query(fn ($query) => $query->whereNotNull('email'))
                    ->label('Has Email'),
                Tables\Filters\Filter::make('has_code')
                    ->query(fn ($query) => $query->whereNotNull('code'))
                    ->label('Has Code'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('sync')
                    ->label('Sync from Robaws')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->action(function (RobawsSupplierCache $record) {
                        try {
                            // Sync supplier with contacts
                            app(\App\Services\Robaws\RobawsSupplierSyncService::class)
                                ->syncSingleSupplier($record->robaws_supplier_id, true);
                            
                            Notification::make()
                                ->title('Supplier synced successfully')
                                ->body("Synced {$record->name} and contacts from Robaws")
                                ->success()
                                ->send();
                                
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Sync failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('push')
                    ->label('Push to Robaws')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Push changes to Robaws?')
                    ->modalDescription('This will push local changes for this supplier to Robaws.')
                    ->action(function (RobawsSupplierCache $record) {
                        try {
                            app(\App\Services\Robaws\RobawsSupplierSyncService::class)->pushSupplierToRobaws($record);
                            
                            Notification::make()
                                ->title('Supplier pushed successfully')
                                ->body("Pushed {$record->name} to Robaws")
                                ->success()
                                ->send();
                                
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Push failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->headerActions([
                Tables\Actions\Action::make('syncAll')
                    ->label('Sync All Suppliers')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Sync All Suppliers from Robaws?')
                    ->modalDescription('This will sync all suppliers from Robaws. This may take several minutes and consume API quota.')
                    ->action(function () {
                        try {
                            Artisan::queue('robaws:sync-suppliers', ['--full' => true]);
                            
                            Notification::make()
                                ->title('Supplier sync queued')
                                ->body('Syncing all suppliers in the background. This will take 5-10 minutes.')
                                ->success()
                                ->duration(10000)
                                ->send();
                                
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Failed to queue sync')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('pushAll')
                    ->label('Push All Pending')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Push all pending local changes to Robaws?')
                    ->modalDescription('This will push all locally modified suppliers that have not been pushed to Robaws yet.')
                    ->action(function () {
                        try {
                            Artisan::queue('robaws:sync-suppliers', ['--push' => true]);
                            
                            Notification::make()
                                ->title('Supplier push queued')
                                ->body('Pushing all pending supplier changes to Robaws in the background.')
                                ->success()
                                ->duration(10000)
                                ->send();
                                
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Failed to queue push')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->modalHeading('Delete Suppliers')
                        ->modalDescription('Are you sure you want to delete the selected suppliers? This action cannot be undone.')
                        ->successNotificationTitle('Suppliers deleted successfully'),
                ]),
            ])
            ->defaultSort('last_synced_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ContactsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRobawsSupplierCaches::route('/'),
            'create' => Pages\CreateRobawsSupplierCache::route('/create'),
            'edit' => Pages\EditRobawsSupplierCache::route('/{record}/edit'),
        ];
    }
}
