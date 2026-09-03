<?php

namespace App\Filament\Resources\Listings;

use App\Enums\Listings\ListingStatus;
use App\Filament\Resources\Listings\Pages\ListListings;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Support\AdminLabel;
use App\Filament\Support\AdminMoney;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Listing;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ListingResource extends ReadOnlyResource
{
    protected static ?string $model = Listing::class;

    protected static ?string $translationKey = 'listings';

    protected static ?string $navigationGroupKey = 'operations';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->is_super_admin
            && $user->hasVerifiedEmail();
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query
                    ->with([
                        'organization:id,name',
                        'marketplaceSource:id,name',
                        'currency:code,minor_unit',
                        'createdBy:id,email',
                    ])
                    ->withCount(['images', 'analyses']),
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('admin.columns.created_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('organization.name')
                    ->label(__('admin.columns.organization'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('id')
                    ->label(__('admin.columns.listing'))
                    ->copyable()
                    ->limit(18)
                    ->tooltip(fn (Listing $record): string => $record->getKey()),
                TextColumn::make('title')
                    ->label(__('admin.columns.name'))
                    ->searchable()
                    ->sortable()
                    ->limit(48)
                    ->wrap(),
                TextColumn::make('marketplace_name')
                    ->label(__('admin.columns.marketplace'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('asking_price')
                    ->label(__('admin.columns.asking_price'))
                    ->state(
                        fn (Listing $record): ?string => AdminMoney::minor(
                            $record->asking_price_minor,
                            $record->currency_code,
                            $record->currency?->minor_unit,
                        ),
                    )
                    ->placeholder(__('admin.placeholders.not_provided')),
                TextColumn::make('market_route')
                    ->label(__('admin.columns.market_route'))
                    ->state(
                        fn (Listing $record): string => sprintf(
                            '%s -> %s',
                            $record->source_country_code,
                            $record->target_country_code,
                        ),
                    ),
                TextColumn::make('status')
                    ->label(__('admin.columns.status'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value('listing_status', $state),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('images_count')
                    ->label(__('admin.columns.images'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('analyses_count')
                    ->label(__('admin.columns.analyses'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('external_id')
                    ->label(__('admin.columns.external_id'))
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('marketplaceSource.name')
                    ->label(__('admin.columns.connector'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('createdBy.email')
                    ->label(__('admin.columns.created_by'))
                    ->placeholder(__('admin.placeholders.deleted_subject'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('admin.columns.updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.columns.status'))
                    ->options(self::statusOptions()),
                SelectFilter::make('marketplace_source_id')
                    ->label(__('admin.columns.connector'))
                    ->relationship('marketplaceSource', 'name')
                    ->searchable(),
                SelectFilter::make('source_country_code')
                    ->label(__('admin.columns.source_country'))
                    ->options(fn (): array => self::countryOptions())
                    ->searchable(),
                SelectFilter::make('target_country_code')
                    ->label(__('admin.columns.target_country'))
                    ->options(fn (): array => self::countryOptions())
                    ->searchable(),
                SelectFilter::make('currency_code')
                    ->label(__('admin.columns.currency'))
                    ->options(fn (): array => self::currencyOptions())
                    ->searchable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListListings::route('/')];
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return collect(ListingStatus::cases())
            ->mapWithKeys(fn (ListingStatus $status): array => [
                $status->value => AdminLabel::value('listing_status', $status),
            ])
            ->all();
    }

    /** @return array<string, string> */
    private static function countryOptions(): array
    {
        return Country::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['code', 'name'])
            ->mapWithKeys(fn (Country $country): array => [
                $country->code => AdminLabel::country($country->code, $country->name),
            ])
            ->all();
    }

    /** @return array<string, string> */
    private static function currencyOptions(): array
    {
        return Currency::query()
            ->where('active', true)
            ->orderBy('code')
            ->get(['code', 'name'])
            ->mapWithKeys(fn (Currency $currency): array => [
                $currency->code => sprintf(
                    '%s - %s',
                    $currency->code,
                    AdminLabel::currency($currency->code, $currency->name),
                ),
            ])
            ->all();
    }
}
