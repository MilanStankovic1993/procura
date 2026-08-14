<?php

namespace App\Filament\Resources\RiskAssessments;

use App\Enums\Risk\RiskAssessmentStatus;
use App\Enums\Risk\RiskConfidenceLevel;
use App\Enums\Risk\RiskLevel;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Resources\RiskAssessments\Pages\ListRiskAssessments;
use App\Filament\Support\AdminLabel;
use App\Models\RiskAssessment;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class RiskAssessmentResource extends ReadOnlyResource
{
    protected static ?string $model = RiskAssessment::class;

    protected static ?string $translationKey = 'risk_assessments';

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
                    'analysis:id,listing_id,source_country_code,target_country_code',
                    'analysis.listing:id,title',
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
                TextColumn::make('market_route')
                    ->label(__('admin.columns.market_route'))
                    ->state(
                        fn (RiskAssessment $record): string => sprintf(
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
                            'risk_assessment_status',
                            $state,
                        ),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('score')
                    ->label(__('admin.columns.risk_score'))
                    ->formatStateUsing(fn (int $state): string => "{$state} / 100")
                    ->sortable(),
                TextColumn::make('level')
                    ->label(__('admin.columns.risk_level'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'risk_level',
                            $state,
                        ),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('confidence')
                    ->label(__('admin.columns.confidence'))
                    ->state(fn (RiskAssessment $record): string => self::confidence($record)),
                TextColumn::make('signal_count')
                    ->label(__('admin.columns.signal_count'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('unknown_count')
                    ->label(__('admin.columns.unknown_count'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('evaluator_version')
                    ->label(__('admin.columns.evaluator_version'))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('analysis_id')
                    ->label(__('admin.columns.analysis'))
                    ->copyable()
                    ->limit(18)
                    ->tooltip(fn (RiskAssessment $record): string => $record->analysis_id)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('id')
                    ->label(__('admin.columns.id'))
                    ->copyable()
                    ->limit(18)
                    ->tooltip(fn (RiskAssessment $record): string => $record->getKey())
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.columns.status'))
                    ->options(self::statusOptions()),
                SelectFilter::make('level')
                    ->label(__('admin.columns.risk_level'))
                    ->options(self::levelOptions()),
                SelectFilter::make('confidence_level')
                    ->label(__('admin.columns.confidence_level'))
                    ->options(self::confidenceLevelOptions()),
            ])
            ->defaultSort('score', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListRiskAssessments::route('/')];
    }

    private static function confidence(RiskAssessment $assessment): string
    {
        return sprintf(
            '%s - %s',
            number_format($assessment->confidence_basis_points / 100, 2).'%',
            AdminLabel::value(
                'risk_confidence_level',
                $assessment->confidence_level,
            ),
        );
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return collect(RiskAssessmentStatus::cases())
            ->mapWithKeys(fn (RiskAssessmentStatus $status): array => [
                $status->value => AdminLabel::value(
                    'risk_assessment_status',
                    $status,
                ),
            ])
            ->all();
    }

    /** @return array<string, string> */
    private static function levelOptions(): array
    {
        return collect(RiskLevel::cases())
            ->mapWithKeys(fn (RiskLevel $level): array => [
                $level->value => AdminLabel::value('risk_level', $level),
            ])
            ->all();
    }

    /** @return array<string, string> */
    private static function confidenceLevelOptions(): array
    {
        return collect(RiskConfidenceLevel::cases())
            ->mapWithKeys(fn (RiskConfidenceLevel $level): array => [
                $level->value => AdminLabel::value(
                    'risk_confidence_level',
                    $level,
                ),
            ])
            ->all();
    }
}
