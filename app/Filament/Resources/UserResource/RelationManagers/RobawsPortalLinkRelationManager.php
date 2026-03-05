<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\RobawsCustomerCache;
use App\Models\RobawsCustomerPortalLink;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class RobawsPortalLinkRelationManager extends RelationManager
{
    protected static string $relationship = 'portalLink';

    protected static ?string $title = 'Belgaco Company Link';

    protected static ?string $modelLabel = 'portal link';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('robaws_client_id')
                    ->label('Belgaco Company')
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

                Forms\Components\Select::make('source')
                    ->label('Link Source')
                    ->options([
                        'email'  => 'Email match',
                        'domain' => 'Domain mapping',
                        'manual' => 'Manual',
                    ])
                    ->default('manual')
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('robaws_client_id')
            ->columns([
                Tables\Columns\TextColumn::make('robaws_client_id')
                    ->label('Belgaco ID')
                    ->copyable(),

                Tables\Columns\TextColumn::make('cachedCustomer.name')
                    ->label('Company Name')
                    ->placeholder('Cache not synced yet')
                    ->searchable(),

                Tables\Columns\TextColumn::make('cachedCustomer.email')
                    ->label('Company Email')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('cachedCustomer.city')
                    ->label('City')
                    ->placeholder('—'),

                Tables\Columns\BadgeColumn::make('source')
                    ->label('Source')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'email'  => 'Email',
                        'domain' => 'Domain',
                        'manual' => 'Manual',
                        default  => ucfirst((string) $state),
                    })
                    ->colors([
                        'success' => 'email',
                        'info'    => 'domain',
                        'warning' => 'manual',
                    ]),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Linked At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Set Link')
                    ->mutateFormDataUsing(function (array $data) {
                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->label('Remove Link'),
            ])
            ->bulkActions([]);
    }
}
