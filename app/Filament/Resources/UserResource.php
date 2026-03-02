<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers\RobawsPortalLinkRelationManager;
use App\Models\RobawsCustomerCache;
use App\Models\RobawsCustomerPortalLink;
use App\Models\User;
use App\Services\Robaws\RobawsPortalLinkResolver;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Password;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Users';

    protected static ?string $modelLabel = 'User';

    protected static ?string $pluralModelLabel = 'Users';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Forms\Components\Select::make('role')
                    ->options([
                        'admin' => 'Admin',
                        'customer' => 'Customer',
                    ])
                    ->required(),
                Forms\Components\Select::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'active' => 'Active',
                        'blocked' => 'Blocked',
                    ])
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('role')
                    ->colors([
                        'primary' => 'admin',
                        'gray' => 'customer',
                    ])
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'active',
                        'danger' => 'blocked',
                    ])
                    ->sortable(),
                Tables\Columns\TextColumn::make('portalLink.cachedCustomer.name')
                    ->label('Robaws Company')
                    ->placeholder('—')
                    ->searchable(query: function ($query, string $search) {
                        $query->whereHas('portalLink.cachedCustomer', fn ($q) =>
                            $q->where('name', 'like', "%{$search}%")
                        );
                    })
                    ->toggleable(),
                Tables\Columns\BadgeColumn::make('portalLink.source')
                    ->label('Link Status')
                    ->formatStateUsing(fn ($state) => $state ? ucfirst($state) : 'Not linked')
                    ->colors([
                        'success' => fn ($state) => in_array($state, ['email', 'domain', 'manual']),
                        'warning' => fn ($state) => $state === null,
                    ])
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\Action::make('activateAndSendPasswordLink')
                    ->label('Activate & Send Password Link')
                    ->icon('heroicon-o-paper-airplane')
                    ->requiresConfirmation()
                    ->visible(fn (User $record) => $record->status !== 'active')
                    ->action(function (User $record) {
                        $record->update(['status' => 'active']);

                        $token = Password::broker()->createToken($record);
                        $record->sendPasswordResetNotification($token);

                        Notification::make()
                            ->title('Activation email sent')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('resolveLink')
                    ->label('Resolve Link')
                    ->icon('heroicon-o-link')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Resolve Robaws Link')
                    ->modalDescription('This will search Robaws CRM for a matching company using this user\'s email address and create the link automatically.')
                    ->visible(fn (User $record) => $record->role === 'customer' && ! $record->portalLink)
                    ->action(function (User $record) {
                        try {
                            $link = app(RobawsPortalLinkResolver::class)->resolveForUser($record);
                        } catch (\Illuminate\Http\Client\ConnectionException $e) {
                            Notification::make()
                                ->title('Robaws connection timeout')
                                ->body('Could not reach Robaws CRM. Please try again in a moment.')
                                ->danger()
                                ->send();
                            return;
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Error resolving link')
                                ->body('An unexpected error occurred: ' . $e->getMessage())
                                ->danger()
                                ->send();
                            return;
                        }

                        if ($link) {
                            $companyName = RobawsCustomerCache::where('robaws_client_id', $link->robaws_client_id)
                                ->value('name') ?? $link->robaws_client_id;

                            Notification::make()
                                ->title('Link established')
                                ->body("Linked to: {$companyName} (via {$link->source})")
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('No match found')
                                ->body('No Robaws company could be matched to this email address.')
                                ->warning()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('clearLink')
                    ->label('Clear Link')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Clear Robaws Link')
                    ->modalDescription('This will remove the current Robaws company link. The link will be re-resolved automatically next time the user visits the portal.')
                    ->visible(fn (User $record) => $record->role === 'customer' && (bool) $record->portalLink)
                    ->action(function (User $record) {
                        $record->portalLink?->delete();

                        Notification::make()
                            ->title('Link cleared')
                            ->body('The Robaws company link has been removed.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('setLinkManually')
                    ->label('Set Link Manually')
                    ->icon('heroicon-o-building-office')
                    ->color('warning')
                    ->visible(fn (User $record) => $record->role === 'customer')
                    ->form([
                        Forms\Components\Select::make('robaws_client_id')
                            ->label('Robaws Company')
                            ->placeholder('Search by name, email or city…')
                            ->searchable()
                            ->required()
                            ->getSearchResultsUsing(function (string $search) {
                                return RobawsCustomerCache::where(function ($q) use ($search) {
                                    $q->where('name', 'like', "%{$search}%")
                                      ->orWhere('email', 'like', "%{$search}%")
                                      ->orWhere('city', 'like', "%{$search}%")
                                      ->orWhere('vat_number', 'like', "%{$search}%");
                                })
                                ->limit(30)
                                ->get()
                                ->mapWithKeys(fn ($c) => [
                                    $c->robaws_client_id => $c->name_with_details,
                                ]);
                            })
                            ->getOptionLabelUsing(function ($value) {
                                $cache = RobawsCustomerCache::where('robaws_client_id', $value)->first();
                                return $cache?->name_with_details ?? $value;
                            }),
                    ])
                    ->action(function (User $record, array $data) {
                        RobawsCustomerPortalLink::updateOrCreate(
                            ['user_id' => $record->id],
                            [
                                'robaws_client_id' => $data['robaws_client_id'],
                                'source' => 'manual',
                            ]
                        );

                        $companyName = RobawsCustomerCache::where('robaws_client_id', $data['robaws_client_id'])
                            ->value('name') ?? $data['robaws_client_id'];

                        Notification::make()
                            ->title('Link saved')
                            ->body("Manually linked to: {$companyName}")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelationManagers(): array
    {
        return [
            RobawsPortalLinkRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
