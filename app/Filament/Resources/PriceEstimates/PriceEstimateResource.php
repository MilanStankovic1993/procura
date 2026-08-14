<?php

namespace App\Filament\Resources\PriceEstimates;

use App\Enums\Pricing\PriceConfidenceLevel;
use App\Enums\Pricing\PriceEstimateStatus;
use App\Filament\Resources\PriceEstimates\Pages\ListPriceEstimates;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Support\AdminLabel;
use App\Filament\Support\AdminMoney;
use App\Models\Country;
use App\Models\Currency;
use App\Models\PriceEstimate;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class PriceEstimateResource extends ReadOnlyResource
{
    protected static ?string $model = PriceEstimate::class;

    protected static ?string $translationKey = 'price_estimates';

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
                fn (Builder $query): Builder => $query->with([
                    'organization:id,name',
                    'analysis:id,listing_id',
                    'analysis.listing:id,title',
                    'targetCountry:code,name',
                    'targetCurrency:code,name,minor_unit',
                ]),
            )
            ->columns([
                TextColumn::make('calculation_at')
                    ->label(__('admin.columns.calculation_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('organization.name')
                    ->label(__('admin.columns.organization'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('analysis.listing.title')
                    ->label(__('admin.columns.listing'))
                    ->searchable()
                    ->limit(40)
                    ->placeholder(__('admin.placeholders.not_provided')),
                TextColumn::make('run_number')
                    ->label(__('admin.columns.run_number'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.columns.status'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'price_estimate_status',
                            $state,
                        ),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('target_market')
                    ->label(__('admin.columns.target_market'))
                    ->state(fn (PriceEstimate $record): string => self::targetMarket($record)),
                TextColumn::make('estimate_minor')
                    ->label(__('admin.columns.estimate'))
                    ->formatStateUsing(
                        fn (?int $state, PriceEstimate $record): ?string => AdminMoney::minor(
                            $state,
                            $record->target_currency_code,
                            $record->targetCurrency?->minor_unit,
                        ),
                    )
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->sortable(),
                TextColumn::make('estimate_range')
                    ->label(__('admin.columns.estimate_range'))
                    ->state(fn (PriceEstimate $record): ?string => self::estimateRange($record))
                    ->placeholder(__('admin.placeholders.not_provided')),
                TextColumn::make('confidence')
                    ->label(__('admin.columns.confidence'))
                    ->state(fn (PriceEstimate $record): ?string => self::confidence($record))
                    ->placeholder(__('admin.placeholders.not_provided')),
                TextColumn::make('included_count')
                    ->label(__('admin.columns.included_count'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('outlier_count')
                    ->label(__('admin.columns.outlier_count'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('unresolved_count')
                    ->label(__('admin.columns.unresolved_count'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('input_count')
                    ->label(__('admin.columns.input_count'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('dispersion_basis_points')
                    ->label(__('admin.columns.dispersion'))
                    ->formatStateUsing(
                        fn (int $state): string => number_format(
                            $state / 100,
                            2,
                        ).'%',
                    )
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('algorithm_version')
                    ->label(__('admin.columns.algorithm_version'))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('rate_resolver_version')
                    ->label(__('admin.columns.rate_resolver_version'))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('analysis_id')
                    ->label(__('admin.columns.analysis'))
                    ->copyable()
                    ->limit(18)
                    ->tooltip(fn (PriceEstimate $record): string => $record->analysis_id)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('id')
                    ->label(__('admin.columns.id'))
                    ->copyable()
                    ->limit(18)
                    ->tooltip(fn (PriceEstimate $record): string => $record->getKey())
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.columns.status'))
                    ->options(self::statusOptions()),
                SelectFilter::make('confidence_level')
                    ->label(__('admin.columns.confidence_level'))
                    ->options(self::confidenceLevelOptions()),
                SelectFilter::make('target_country_code')
                    ->label(__('admin.columns.target_country'))
                    ->options(fn (): array => self::countryOptions())
                    ->searchable(),
                SelectFilter::make('target_currency_code')
                    ->label(__('admin.columns.currency'))
                    ->options(fn (): array => self::currencyOptions())
                    ->searchable(),
            ])
            ->defaultSort('calculation_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListPriceEstimates::route('/')];
    }

    private static function targetMarket(PriceEstimate $estimate): string
    {
        $country = AdminLabel::country(
            $estimate->target_country_code,
            $estimate->targetCountry?->name ?? $estimate->target_country_code,
        );

        return sprintf(
            '%s (%s) - %s',
            $country,
            $estimate->target_country_code,
            $estimate->target_currency_code,
        );
    }

    private static function estimateRange(PriceEstimate $estimate): ?string
    {
        $minorUnit = $estimate->targetCurrency?->minor_unit;
        $low = AdminMoney::minor(
            $estimate->estimate_low_minor,
            $estimate->target_currency_code,
            $minorUnit,
        );
        $high = AdminMoney::minor(
            $estimate->estimate_high_minor,
            $estimate->target_currency_code,
            $minorUnit,
        );

        if ($low !== null && $high !== null) {
            return "{$low} - {$high}";
        }

        return $low ?? $high;
    }

    private static function confidence(PriceEstimate $estimate): ?string
    {
        $score = $estimate->confidence_basis_points === null
            ? null
            : number_format($estimate->confidence_basis_points / 100, 2).'%';
        $level = $estimate->confidence_level === null
            ? null
            : AdminLabel::value(
                'price_confidence_level',
                $estimate->confidence_level,
            );

        if ($score !== null && $level !== null) {
            return "{$score} - {$level}";
        }

        return $score ?? $level;
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return collect(PriceEstimateStatus::cases())
            ->mapWithKeys(fn (PriceEstimateStatus $status): array => [
                $status->value => AdminLabel::value(
                    'price_estimate_status',
                    $status,
                ),
            ])
            ->all();
    }

    /** @return array<string, string> */
    private static function confidenceLevelOptions(): array
    {
        return collect(PriceConfidenceLevel::cases())
            ->mapWithKeys(fn (PriceConfidenceLevel $level): array => [
                $level->value => AdminLabel::value(
                    'price_confidence_level',
                    $level,
                ),
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
}
