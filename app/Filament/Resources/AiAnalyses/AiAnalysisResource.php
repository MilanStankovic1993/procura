<?php

namespace App\Filament\Resources\AiAnalyses;

use App\Enums\Analyses\AiAnalysisStatus;
use App\Enums\Analyses\AiValidationStatus;
use App\Filament\Resources\AiAnalyses\Pages\ListAiAnalyses;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Support\AdminLabel;
use App\Models\AiAnalysis;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class AiAnalysisResource extends ReadOnlyResource
{
    protected static ?string $model = AiAnalysis::class;

    protected static ?string $translationKey = 'ai_analyses';

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
                        'analysis:id,listing_id',
                        'analysis.listing:id,title',
                    ])
                    ->withCount('productMatches'),
            )
            ->columns([
                TextColumn::make('started_at')
                    ->label(__('admin.columns.started_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('organization.name')
                    ->label(__('admin.columns.organization'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('analysis_id')
                    ->label(__('admin.columns.analysis'))
                    ->copyable()
                    ->limit(18)
                    ->tooltip(fn (AiAnalysis $record): string => $record->analysis_id)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('analysis.listing.title')
                    ->label(__('admin.columns.listing'))
                    ->searchable()
                    ->limit(40)
                    ->placeholder(__('admin.placeholders.not_provided')),
                TextColumn::make('attempt_number')
                    ->label(__('admin.columns.attempt'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.columns.status'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'ai_analysis_status',
                            $state,
                        ),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('validation_status')
                    ->label(__('admin.columns.validation_status'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'ai_validation_status',
                            $state,
                        ),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('provider')
                    ->label(__('admin.columns.provider'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('model')
                    ->label(__('admin.columns.provider_model'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('prompt_version')
                    ->label(__('admin.columns.prompt_version'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('confidence_basis_points')
                    ->label(__('admin.columns.confidence'))
                    ->formatStateUsing(
                        fn (int $state): string => number_format(
                            $state / 100,
                            2,
                        ).'%',
                    )
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->sortable(),
                TextColumn::make('duration')
                    ->label(__('admin.columns.duration'))
                    ->state(fn (AiAnalysis $record): ?string => self::duration($record))
                    ->placeholder(__('admin.placeholders.not_provided')),
                TextColumn::make('product_matches_count')
                    ->label(__('admin.columns.product_matches'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->label(__('admin.columns.completed_at'))
                    ->dateTime()
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('id')
                    ->label(__('admin.columns.id'))
                    ->copyable()
                    ->limit(18)
                    ->tooltip(fn (AiAnalysis $record): string => $record->getKey())
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.columns.status'))
                    ->options(self::statusOptions()),
                SelectFilter::make('validation_status')
                    ->label(__('admin.columns.validation_status'))
                    ->options(self::validationStatusOptions()),
                SelectFilter::make('provider')
                    ->label(__('admin.columns.provider'))
                    ->options(fn (): array => self::providerOptions()),
            ])
            ->defaultSort('started_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListAiAnalyses::route('/')];
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return collect(AiAnalysisStatus::cases())
            ->mapWithKeys(fn (AiAnalysisStatus $status): array => [
                $status->value => AdminLabel::value(
                    'ai_analysis_status',
                    $status,
                ),
            ])
            ->all();
    }

    /** @return array<string, string> */
    private static function validationStatusOptions(): array
    {
        return collect(AiValidationStatus::cases())
            ->mapWithKeys(fn (AiValidationStatus $status): array => [
                $status->value => AdminLabel::value(
                    'ai_validation_status',
                    $status,
                ),
            ])
            ->all();
    }

    /** @return array<string, string> */
    private static function providerOptions(): array
    {
        return AiAnalysis::query()
            ->distinct()
            ->orderBy('provider')
            ->pluck('provider', 'provider')
            ->all();
    }

    private static function duration(AiAnalysis $record): ?string
    {
        if ($record->completed_at === null) {
            return null;
        }

        return $record->started_at
            ->diffForHumans($record->completed_at, true);
    }
}
