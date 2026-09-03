<?php

namespace App\Filament\Resources\Analyses;

use App\Enums\Analyses\AnalysisStatus;
use App\Enums\Analyses\AnalysisType;
use App\Filament\Resources\Analyses\Pages\ListAnalyses;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Support\AdminLabel;
use App\Filament\Support\AdminMoney;
use App\Models\Analysis;
use App\Models\Country;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class AnalysisResource extends ReadOnlyResource
{
    protected static ?string $model = Analysis::class;

    protected static ?string $translationKey = 'analyses';

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
                    'listing:id,title',
                    'requestedBy:id,email',
                    'currentProductMatch.productModel.brand:id,name',
                    'currentProductMatch.productModel:id,brand_id,name,model_number',
                    'currentProductMatch.productVariant:id,product_model_id,name',
                    'currentPriceEstimate.targetCurrency:code,minor_unit',
                    'currentRiskAssessment',
                    'currentDealScore',
                ]),
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
                    ->label(__('admin.columns.analysis'))
                    ->copyable()
                    ->limit(18)
                    ->tooltip(fn (Analysis $record): string => $record->getKey()),
                TextColumn::make('listing.title')
                    ->label(__('admin.columns.listing'))
                    ->searchable()
                    ->limit(40),
                TextColumn::make('analysis_type')
                    ->label(__('admin.columns.analysis_type'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value('analysis_type', $state),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('market_route')
                    ->label(__('admin.columns.market_route'))
                    ->state(
                        fn (Analysis $record): string => sprintf(
                            '%s -> %s',
                            $record->source_country_code,
                            $record->target_country_code,
                        ),
                    ),
                TextColumn::make('status')
                    ->label(__('admin.columns.status'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value('analysis_status', $state),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('currentProductMatch.status')
                    ->label(__('admin.columns.match_status'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value('product_match_status', $state),
                    )
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->badge(),
                TextColumn::make('canonical_product')
                    ->label(__('admin.columns.canonical_product'))
                    ->state(fn (Analysis $record): ?string => self::canonicalProduct($record))
                    ->placeholder(__('admin.placeholders.not_selected'))
                    ->wrap(),
                TextColumn::make('price_estimate')
                    ->label(__('admin.columns.price_estimate'))
                    ->state(fn (Analysis $record): ?string => self::priceEstimate($record))
                    ->placeholder(__('admin.placeholders.not_provided')),
                TextColumn::make('risk_assessment')
                    ->label(__('admin.columns.risk_assessment'))
                    ->state(fn (Analysis $record): ?string => self::riskAssessment($record))
                    ->placeholder(__('admin.placeholders.not_provided')),
                TextColumn::make('deal_score')
                    ->label(__('admin.columns.deal_score'))
                    ->state(fn (Analysis $record): ?string => self::dealScore($record))
                    ->placeholder(__('admin.placeholders.not_provided')),
                TextColumn::make('requestedBy.email')
                    ->label(__('admin.columns.requested_by'))
                    ->placeholder(__('admin.placeholders.deleted_subject'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('pipeline_version')
                    ->label(__('admin.columns.version'))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('finished_at')
                    ->label(__('admin.columns.finished_at'))
                    ->dateTime()
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.columns.status'))
                    ->options(self::enumOptions(AnalysisStatus::cases(), 'analysis_status')),
                SelectFilter::make('analysis_type')
                    ->label(__('admin.columns.analysis_type'))
                    ->options(self::enumOptions(AnalysisType::cases(), 'analysis_type')),
                SelectFilter::make('source_country_code')
                    ->label(__('admin.columns.source_country'))
                    ->options(fn (): array => self::countryOptions())
                    ->searchable(),
                SelectFilter::make('target_country_code')
                    ->label(__('admin.columns.target_country'))
                    ->options(fn (): array => self::countryOptions())
                    ->searchable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListAnalyses::route('/')];
    }

    private static function canonicalProduct(Analysis $analysis): ?string
    {
        $match = $analysis->currentProductMatch;
        $model = $match?->productModel;

        if ($model === null) {
            return null;
        }

        $identity = trim(sprintf(
            '%s %s',
            $model->brand?->name ?? '',
            $model->model_number,
        ));

        return $match->productVariant === null
            ? $identity
            : sprintf('%s - %s', $identity, $match->productVariant->name);
    }

    private static function priceEstimate(Analysis $analysis): ?string
    {
        $estimate = $analysis->currentPriceEstimate;

        if ($estimate === null) {
            return null;
        }

        if ($estimate->estimate_minor === null) {
            return AdminLabel::value('price_estimate_status', $estimate->status);
        }

        return AdminMoney::minor(
            $estimate->estimate_minor,
            $estimate->target_currency_code,
            $estimate->targetCurrency?->minor_unit,
        );
    }

    private static function riskAssessment(Analysis $analysis): ?string
    {
        $assessment = $analysis->currentRiskAssessment;

        if ($assessment === null) {
            return null;
        }

        return sprintf(
            '%d / 100 - %s',
            $assessment->score,
            AdminLabel::value('risk_level', $assessment->level),
        );
    }

    private static function dealScore(Analysis $analysis): ?string
    {
        $score = $analysis->currentDealScore;

        if ($score === null) {
            return null;
        }

        $recommendation = AdminLabel::value(
            'deal_recommendation',
            $score->recommendation,
        );

        if ($score->score === null) {
            return $recommendation;
        }

        return sprintf(
            '%d / 100 - %s',
            $score->score,
            $recommendation,
        );
    }

    /**
     * @param  array<int, \BackedEnum>  $cases
     * @return array<string, string>
     */
    private static function enumOptions(array $cases, string $translationGroup): array
    {
        return collect($cases)
            ->mapWithKeys(fn (\BackedEnum $case): array => [
                (string) $case->value => AdminLabel::value($translationGroup, $case),
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
}
