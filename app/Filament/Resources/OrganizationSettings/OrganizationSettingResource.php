<?php

namespace App\Filament\Resources\OrganizationSettings;

use App\Enums\Markets\MeasurementSystem;
use App\Filament\Resources\OrganizationSettings\Pages\ListOrganizationSettings;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Support\AdminLabel;
use App\Models\Country;
use App\Models\Currency;
use App\Models\OrganizationMarketPreference;
use App\Models\User;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class OrganizationSettingResource extends ReadOnlyResource
{
    protected static ?string $model = OrganizationMarketPreference::class;

    protected static ?string $translationKey = 'organization_settings';

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
                        'homeCountry:code,name',
                        'reportingCurrency:code,name',
                    ])
                    ->withCount('countries'),
            )
            ->columns([
                TextColumn::make('updated_at')
                    ->label(__('admin.columns.updated_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('organization.name')
                    ->label(__('admin.columns.organization'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('home_country_code')
                    ->label(__('admin.columns.home_country'))
                    ->formatStateUsing(
                        fn (?string $state, OrganizationMarketPreference $record): ?string => self::country(
                            $state,
                            $record,
                        ),
                    )
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->sortable(),
                TextColumn::make('reporting_currency_code')
                    ->label(__('admin.columns.reporting_currency'))
                    ->formatStateUsing(
                        fn (?string $state, OrganizationMarketPreference $record): ?string => self::currency(
                            $state,
                            $record,
                        ),
                    )
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->sortable(),
                TextColumn::make('locale')
                    ->label(__('admin.columns.locale'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('timezone')
                    ->label(__('admin.columns.timezone'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('measurement_system')
                    ->label(__('admin.columns.measurement_system'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'measurement',
                            $state,
                        ),
                    )
                    ->badge()
                    ->sortable(),
                IconColumn::make('include_cross_border')
                    ->label(__('admin.columns.include_cross_border'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('countries_count')
                    ->label(__('admin.columns.market_countries'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('organization_id')
                    ->label(__('admin.columns.id'))
                    ->copyable()
                    ->limit(18)
                    ->tooltip(
                        fn (OrganizationMarketPreference $record): string => $record->organization_id,
                    )
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('home_country_code')
                    ->label(__('admin.columns.home_country'))
                    ->options(fn (): array => self::countryOptions())
                    ->searchable(),
                SelectFilter::make('reporting_currency_code')
                    ->label(__('admin.columns.reporting_currency'))
                    ->options(fn (): array => self::currencyOptions())
                    ->searchable(),
                SelectFilter::make('locale')
                    ->label(__('admin.columns.locale'))
                    ->options(fn (): array => self::localeOptions())
                    ->searchable(),
                SelectFilter::make('measurement_system')
                    ->label(__('admin.columns.measurement_system'))
                    ->options(self::measurementOptions()),
                TernaryFilter::make('include_cross_border')
                    ->label(__('admin.columns.include_cross_border')),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListOrganizationSettings::route('/')];
    }

    private static function country(
        ?string $code,
        OrganizationMarketPreference $preference,
    ): ?string {
        if ($code === null) {
            return null;
        }

        return sprintf(
            '%s (%s)',
            AdminLabel::country(
                $code,
                $preference->homeCountry?->name ?? $code,
            ),
            $code,
        );
    }

    private static function currency(
        ?string $code,
        OrganizationMarketPreference $preference,
    ): ?string {
        if ($code === null) {
            return null;
        }

        return sprintf(
            '%s - %s',
            $code,
            AdminLabel::currency(
                $code,
                $preference->reportingCurrency?->name ?? $code,
            ),
        );
    }

    /** @return array<string, string> */
    private static function countryOptions(): array
    {
        return Country::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['code', 'name'])
            ->mapWithKeys(fn (Country $country): array => [
                $country->code => AdminLabel::country(
                    $country->code,
                    $country->name,
                ),
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
                    AdminLabel::currency(
                        $currency->code,
                        $currency->name,
                    ),
                ),
            ])
            ->all();
    }

    /** @return array<string, string> */
    private static function localeOptions(): array
    {
        return OrganizationMarketPreference::query()
            ->select('locale')
            ->distinct()
            ->orderBy('locale')
            ->pluck('locale', 'locale')
            ->all();
    }

    /** @return array<string, string> */
    private static function measurementOptions(): array
    {
        return collect(MeasurementSystem::cases())
            ->mapWithKeys(fn (MeasurementSystem $system): array => [
                $system->value => AdminLabel::value('measurement', $system),
            ])
            ->all();
    }
}
