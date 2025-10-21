<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RobawsCustomerCacheResource\Pages;
use App\Models\RobawsCustomerCache;
use App\Models\Intake;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class RobawsCustomerCacheResource extends Resource
{
    protected static ?string $model = RobawsCustomerCache::class;
    
    protected static ?string $navigationIcon = 'heroicon-o-users';
    
    protected static ?string $navigationLabel = 'Robaws Customers';
    
    protected static ?string $navigationGroup = 'Robaws Data';

    protected static ?string $pollingInterval = '30s'; // Auto-refresh table

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Customer Information')
                    ->schema([
                        Forms\Components\TextInput::make('robaws_client_id')
                            ->label('Robaws Client ID')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->disabledOn('edit'), // Disable editing ID
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('role')
                            ->options([
                                'FORWARDER' => 'FORWARDER',
                                'POV' => 'POV',
                                'BROKER' => 'BROKER',
                                'SHIPPING LINE' => 'SHIPPING LINE',
                                'CAR DEALER' => 'CAR DEALER',
                                'LUXURY CAR DEALER' => 'LUXURY CAR DEALER',
                                'EMBASSY' => 'EMBASSY',
                                'TRANSPORT COMPANY' => 'TRANSPORT COMPANY',
                                'OEM' => 'OEM',
                                'RENTAL' => 'RENTAL',
                                'CONSTRUCTION COMPANY' => 'CONSTRUCTION COMPANY',
                                'MINING COMPANY' => 'MINING COMPANY',
                                'TOURIST' => 'TOURIST',
                                'BLACKLISTED' => 'BLACKLISTED',
                                'RORO' => 'RORO',
                                'HOLLANDICO' => 'HOLLANDICO',
                                'UNKNOWN' => 'UNKNOWN',
                            ])
                            ->required()
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
                        Forms\Components\Select::make('client_type')
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
                Tables\Columns\TextColumn::make('robaws_client_id')
                    ->label('Client ID')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'FORWARDER' => 'primary',
                        'POV' => 'success',
                        'BROKER' => 'warning',
                        'SHIPPING LINE' => 'info',
                        'CAR DEALER' => 'secondary',
                        'LUXURY CAR DEALER' => 'success',
                        'TOURIST' => 'danger',
                        'BLACKLISTED' => 'danger',
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
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('intakes_count')
                    ->counts('intakes')
                    ->label('Intakes')
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
                Tables\Filters\SelectFilter::make('role')
                    ->options([
                        'FORWARDER' => 'FORWARDER',
                        'POV' => 'POV',
                        'BROKER' => 'BROKER',
                        'SHIPPING LINE' => 'SHIPPING LINE',
                        'CAR DEALER' => 'CAR DEALER',
                        'LUXURY CAR DEALER' => 'LUXURY CAR DEALER',
                        'EMBASSY' => 'EMBASSY',
                        'TRANSPORT COMPANY' => 'TRANSPORT COMPANY',
                        'OEM' => 'OEM',
                        'RENTAL' => 'RENTAL',
                        'CONSTRUCTION COMPANY' => 'CONSTRUCTION COMPANY',
                        'MINING COMPANY' => 'MINING COMPANY',
                        'TOURIST' => 'TOURIST',
                        'BLACKLISTED' => 'BLACKLISTED',
                        'RORO' => 'RORO',
                        'HOLLANDICO' => 'HOLLANDICO',
                        'UNKNOWN' => 'UNKNOWN',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active')
                    ->placeholder('All customers')
                    ->trueLabel('Active customers')
                    ->falseLabel('Inactive customers'),
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
                Tables\Filters\Filter::make('has_phone')
                    ->query(fn ($query) => $query->whereNotNull('phone'))
                    ->label('Has Phone'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('sync')
                    ->label('Sync from Robaws')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->action(function (RobawsCustomerCache $record) {
                        try {
                            Artisan::call('robaws:sync-customers', ['--client-id' => $record->robaws_client_id]);
                            
                            Notification::make()
                                ->title('Customer synced successfully')
                                ->body("Synced {$record->name} from Robaws")
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
                    ->modalDescription('This will push local changes for this customer to Robaws.')
                    ->action(function (RobawsCustomerCache $record) {
                        try {
                            app(\App\Services\Robaws\RobawsCustomerSyncService::class)->pushCustomerToRobaws($record);
                            
                            Notification::make()
                                ->title('Customer pushed successfully')
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
                    ->label('Sync All Customers')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Sync All Customers from Robaws?')
                    ->modalDescription('This will sync all customers from Robaws. This may take several minutes and consume API quota.')
                    ->action(function () {
                        try {
                            Artisan::queue('robaws:sync-customers', ['--full' => true]);
                            
                            Notification::make()
                                ->title('Customer sync queued')
                                ->body('Syncing all customers in the background. This will take 5-10 minutes.')
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
                    ->modalDescription('This will push all locally modified customers that have not been pushed to Robaws yet.')
                    ->action(function () {
                        try {
                            Artisan::queue('robaws:sync-customers', ['--push' => true]);
                            
                            Notification::make()
                                ->title('Customer push queued')
                                ->body('Pushing all pending customer changes to Robaws in the background.')
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
                Tables\Actions\Action::make('findDuplicates')
                    ->label('Find Duplicates')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('secondary')
                    ->modalHeading('Potential Duplicate Customers')
                    ->modalDescription('Customers with the same name (case-insensitive) are listed below.')
                    ->action(function () {
                        $duplicates = RobawsCustomerCache::select('name', DB::raw('count(*) as count'))
                            ->groupBy('name')
                            ->having('count', '>', 1)
                            ->get();
                        
                        if ($duplicates->isEmpty()) {
                            Notification::make()
                                ->title('No duplicates found')
                                ->body('No customers with duplicate names found.')
                                ->success()
                                ->send();
                            return;
                        }
                        
                        $message = "Found " . $duplicates->count() . " duplicate groups:\n\n";
                        foreach ($duplicates as $duplicate) {
                            $message .= "• " . $duplicate->name . " (" . $duplicate->count . " entries)\n";
                        }
                        
                        Notification::make()
                            ->title('Duplicates Found')
                            ->body($message)
                            ->warning()
                            ->persistent()
                            ->send();
                    }),
                Tables\Actions\Action::make('exportCustomers')
                    ->label('Export to CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->action(function () {
                        return response()->streamDownload(function () {
                            $customers = RobawsCustomerCache::all();
                            $csv = \League\Csv\Writer::fromPath('php://temp', 'r+');
                            $csv->insertOne(array_keys($customers->first()->toArray())); // Headers
                            $csv->insertAll($customers->toArray());
                            echo $csv->getContent();
                        }, 'robaws_customers_export_' . now()->format('Ymd_His') . '.csv');
                    }),
            ])
            ->defaultSort('last_synced_at', 'desc');
    }
    
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRobawsCustomerCaches::route('/'),
            'create' => Pages\CreateRobawsCustomerCache::route('/create'),
            'edit' => Pages\EditRobawsCustomerCache::route('/{record}/edit'),
        ];
    }
}