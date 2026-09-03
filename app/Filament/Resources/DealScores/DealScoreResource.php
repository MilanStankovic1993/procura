<?php

namespace App\Filament\Resources\DealScores;

use App\Enums\DealScoring\DealRecommendation;
use App\Enums\DealScoring\DealScoreConfidenceLevel;
use App\Enums\DealScoring\DealScoreStatus;
use App\Filament\Resources\DealScores\Pages\ListDealScores;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Support\AdminLabel;
use App\Models\DealScore;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class DealScoreResource extends ReadOnlyResource
{
    protected static ?string $model = DealScore::class;

    protected static ?string $translationKey = 'deal_scores';

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
                        'analysis:id,listing_id,source_country_code,target_country_code',
                        'analysis.listing:id,title',
                    ])
                    ->withCount('items'),
            )
            ->columns([
                TextColumn::make('calculated_at')
                    ->label(__('admin.columns.calculated_at'))
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
                TextColumn::make('market_route')
                    ->label(__('admin.columns.market_route'))
                    ->state(
                        fn (DealScore $record): string => sprintf(
                            '%s -> %s',
                            $record->analysis->source_country_code,
                            $record->analysis->target_country_code,
                        ),
                    ),
                TextColumn::make('run_number')
                    ->label(__('admin.columns.run_number'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.columns.status'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'deal_score_status',
                            $state,
                        ),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('score')
                    ->label(__('admin.columns.deal_score'))
                    ->formatStateUsing(
                        fn (?int $state): ?string => $state === null
                            ? null
                            : "{$state} / 100",
                    )
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->sortable(),
                TextColumn::make('recommendation')
                    ->label(__('admin.columns.recommendation'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'deal_recommendation',
                            $state,
                        ),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('confidence')
                    ->label(__('admin.columns.confidence'))
                    ->state(fn (DealScore $record): string => self::confidence($record)),
                TextColumn::make('applicable_cap')
                    ->label(__('admin.columns.applicable_cap'))
                    ->formatStateUsing(
                        fn (?int $state): ?string => $state === null
                            ? null
                            : "{$state} / 100",
                    )
                    ->placeholder(__('admin.placeholders.none'))
                    ->sortable(),
                TextColumn::make('items_count')
                    ->label(__('admin.columns.component_count'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('unknown_count')
                    ->label(__('admin.columns.unknown_count'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('calculation_version')
                    ->label(__('admin.columns.calculation_version'))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('analysis_id')
                    ->label(__('admin.columns.analysis'))
                    ->copyable()
                    ->limit(18)
                    ->tooltip(fn (DealScore $record): string => $record->analysis_id)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('id')
                    ->label(__('admin.columns.id'))
                    ->copyable()
                    ->limit(18)
                    ->tooltip(fn (DealScore $record): string => $record->getKey())
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.columns.status'))
                    ->options(self::statusOptions()),
                SelectFilter::make('recommendation')
                    ->label(__('admin.columns.recommendation'))
                    ->options(self::recommendationOptions()),
                SelectFilter::make('confidence_level')
                    ->label(__('admin.columns.confidence_level'))
                    ->options(self::confidenceLevelOptions()),
            ])
            ->defaultSort('calculated_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListDealScores::route('/')];
    }

    private static function confidence(DealScore $score): string
    {
        return sprintf(
            '%s - %s',
            number_format($score->confidence_basis_points / 100, 2).'%',
            AdminLabel::value(
                'deal_score_confidence_level',
                $score->confidence_level,
            ),
        );
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return collect(DealScoreStatus::cases())
            ->mapWithKeys(fn (DealScoreStatus $status): array => [
                $status->value => AdminLabel::value(
                    'deal_score_status',
                    $status,
                ),
            ])
            ->all();
    }

    /** @return array<string, string> */
    private static function recommendationOptions(): array
    {
        return collect(DealRecommendation::cases())
            ->mapWithKeys(fn (DealRecommendation $recommendation): array => [
                $recommendation->value => AdminLabel::value(
                    'deal_recommendation',
                    $recommendation,
                ),
            ])
            ->all();
    }

    /** @return array<string, string> */
    private static function confidenceLevelOptions(): array
    {
        return collect(DealScoreConfidenceLevel::cases())
            ->mapWithKeys(fn (DealScoreConfidenceLevel $level): array => [
                $level->value => AdminLabel::value(
                    'deal_score_confidence_level',
                    $level,
                ),
            ])
            ->all();
    }
}
