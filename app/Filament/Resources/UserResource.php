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
use Illuminate\Validation\Rules\Password as PasswordRule;

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
                Forms\Components\Section::make('Account')
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
                        Forms\Components\Select::make('pricing_tier_id')
                            ->label('Pricing Tier')
                            ->relationship('pricingTier', 'name', fn ($query) => $query->where('is_active', true))
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->select_label)
                            ->placeholder('Use default (Tier C)')
                            ->helperText('Applies to new quotations created by this customer. Leave empty to use default.')
                            ->searchable()
                            ->preload()
                            ->visible(fn ($get, $record) => ($record?->role ?? $get('role')) === 'customer'),
                        Forms\Components\TextInput::make('password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->required(fn ($get) => ! $get('id'))
                            ->dehydrated(fn ($state) => filled($state))
                            ->rules([PasswordRule::defaults()])
                            ->confirmed()
                            ->maxLength(255)
                            ->helperText('Leave blank when editing to keep current password.')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('password_confirmation')
                            ->label('Confirm Password')
                            ->password()
                            ->revealable()
                            ->required(fn ($get) => ! $get('id'))
                            ->dehydrated(false)
                            ->same('password')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                // Shown for customer accounts with a confirmed portal link + cached data
                Forms\Components\Section::make('Robaws Company')
                    ->description('Read-only data synced from Robaws CRM.')
                    ->collapsible()
                    ->schema([
                        Forms\Components\Placeholder::make('co_name')
                            ->label('Company Name')
                            ->content(fn ($record) => $record?->portalLink?->cachedCustomer?->name ?? '—'),

                        Forms\Components\Placeholder::make('co_type')
                            ->label('Type / Role')
                            ->content(fn ($record) => implode(' · ', array_filter([
                                $record?->portalLink?->cachedCustomer?->client_type,
                                $record?->portalLink?->cachedCustomer?->role,
                            ])) ?: '—'),

                        Forms\Components\Placeholder::make('co_email')
                            ->label('Company Email')
                            ->content(fn ($record) => $record?->portalLink?->cachedCustomer?->email ?? '—'),

                        Forms\Components\Placeholder::make('co_phone')
                            ->label('Phone')
                            ->content(fn ($record) => $record?->portalLink?->cachedCustomer?->phone ?? '—'),

                        Forms\Components\Placeholder::make('co_mobile')
                            ->label('Mobile')
                            ->content(fn ($record) => $record?->portalLink?->cachedCustomer?->mobile ?? '—'),

                        Forms\Components\Placeholder::make('co_website')
                            ->label('Website')
                            ->content(fn ($record) => $record?->portalLink?->cachedCustomer?->website ?? '—'),

                        Forms\Components\Placeholder::make('co_address')
                            ->label('Address')
                            ->columnSpanFull()
                            ->content(function ($record) {
                                $c = $record?->portalLink?->cachedCustomer;
                                if (! $c) return '—';
                                $parts = array_filter([
                                    trim(($c->street ?? '') . ' ' . ($c->street_number ?? '')),
                                    $c->city,
                                    trim(($c->postal_code ?? '') . ' ' . ($c->country ?? '')),
                                ]);
                                return implode(', ', $parts) ?: '—';
                            }),

                        Forms\Components\Placeholder::make('co_vat')
                            ->label('VAT Number')
                            ->content(fn ($record) => $record?->portalLink?->cachedCustomer?->vat_number ?? '—'),

                        Forms\Components\Placeholder::make('co_language')
                            ->label('Language / Currency')
                            ->content(function ($record) {
                                $c = $record?->portalLink?->cachedCustomer;
                                return implode(' · ', array_filter([
                                    $c?->language ? strtoupper($c->language) : null,
                                    $c?->currency,
                                ])) ?: '—';
                            }),

                        Forms\Components\Placeholder::make('co_active')
                            ->label('Active in Robaws')
                            ->content(fn ($record) => match($record?->portalLink?->cachedCustomer?->is_active) {
                                true  => 'Yes',
                                false => 'No',
                                null  => '—',
                            }),

                        Forms\Components\Placeholder::make('co_pricing')
                            ->label('Pricing (from Robaws)')
                            ->content(fn ($record) => ($c = $record?->portalLink?->cachedCustomer?->pricing_code)
                                ? 'Tier ' . strtoupper($c)
                                : '—'),

                        Forms\Components\Placeholder::make('co_synced')
                            ->label('Last Synced')
                            ->content(fn ($record) => $record?->portalLink?->cachedCustomer?->last_synced_at
                                ?->diffForHumans() ?? '—'),

                        Forms\Components\Placeholder::make('co_robaws_id')
                            ->label('Robaws Client ID')
                            ->content(fn ($record) => $record?->portalLink?->robaws_client_id ?? '—'),
                    ])
                    ->columns(2)
                    ->visible(fn ($record) => $record?->portalLink !== null),

                // Shown for customer accounts not yet linked to Robaws
                Forms\Components\Section::make('Robaws Company')
                    ->schema([
                        Forms\Components\Placeholder::make('no_link_notice')
                            ->label('')
                            ->content('This customer is not yet linked to a Robaws company. Use the "Resolve Link" or "Set Link Manually" actions on the Users list to establish a link.'),
                    ])
                    ->visible(fn ($record) => $record?->role === 'customer' && $record?->portalLink === null),
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
                Tables\Columns\TextColumn::make('pricingTier.name')
                    ->label('Pricing Tier')
                    ->badge()
                    ->formatStateUsing(fn ($state, $record) => $state ?? '—')
                    ->color(fn ($record) => $record?->pricingTier?->color ?? 'gray')
                    ->visible(fn ($record) => ($record?->role ?? null) === 'customer')
                    ->toggleable(),
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
                                ->body('No Robaws company could be matched to this email address. If the customer exists under a different email, use "Set Link Manually" to assign the correct company.')
                                ->warning()
                                ->persistent()
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
