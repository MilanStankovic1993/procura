<?php

namespace App\Filament\Resources\AnalysisOperations;

use App\Actions\Analyses\RequestManualAnalysisRetry;
use App\Analysis\Operations\AnalysisOperationsQuery;
use App\Filament\Resources\AnalysisOperations\Pages\ListAnalysisOperations;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Support\AdminLabel;
use App\Models\Analysis;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class AnalysisOperationResource extends ReadOnlyResource
{
    protected static ?string $model = Analysis::class;

    protected static ?string $translationKey = 'analysis_operations';

    protected static ?string $navigationGroupKey = 'operations';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return
            $user instanceof User
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
                fn (Builder $query): Builder => app(
                    AnalysisOperationsQuery::class,
                )->apply(
                    $query
                        ->with([
                            'organization:id,name',
                            'listing:id,title',
                            'requestedBy:id,email',
                            'currentDispatch',
                            'currentRetryEvent.actor:id,email',
                        ])
                        ->withCount('retryEvents'),
                ),
            )
            ->columns([
                TextColumn::make('attention_since')
                    ->label(__('admin.columns.attention_since'))
                    ->state(
                        fn (Analysis $record) => (
                            $record->failed_at
                            ?? $record->processing_started_at
                            ?? $record->currentDispatch?->failed_at
                            ?? $record->currentDispatch
                                ?->last_dispatch_attempt_at
                        ),
                    )
                    ->dateTime()
                    ->sortable(query: function (
                        Builder $query,
                        string $direction,
                    ): Builder {
                        return $query->orderBy(
                            'failed_at',
                            $direction,
                        );
                    }),
                TextColumn::make('organization.name')
                    ->label(__('admin.columns.organization'))
                    ->searchable(),
                TextColumn::make('id')
                    ->label(__('admin.columns.analysis'))
                    ->copyable()
                    ->limit(18)
                    ->tooltip(fn (Analysis $record): string => $record->getKey()),
                TextColumn::make('listing.title')
                    ->label(__('admin.columns.listing'))
                    ->searchable()
                    ->limit(40),
                TextColumn::make('status')
                    ->label(__('admin.columns.status'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'analysis_status',
                            $state,
                        ),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('currentDispatch.status')
                    ->label(__('admin.columns.dispatch_status'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'analysis_dispatch_status',
                            $state,
                        ),
                    )
                    ->badge(),
                TextColumn::make('currentDispatch.run_number')
                    ->label(__('admin.columns.run_number'))
                    ->numeric(),
                TextColumn::make('processing_attempts')
                    ->label(__('admin.columns.processing_attempts'))
                    ->state(
                        fn (Analysis $record): string => sprintf(
                            '%d / %d',
                            $record->processing_attempts,
                            $record->currentDispatch
                                ?->max_processing_attempts ?? 0,
                        ),
                    ),
                TextColumn::make('currentDispatch.dispatch_attempts')
                    ->label(__('admin.columns.dispatch_attempts'))
                    ->numeric(),
                TextColumn::make('last_error_code')
                    ->label(__('admin.columns.error'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'analysis_error_code',
                            $state,
                        ),
                    )
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->badge(),
                TextColumn::make('currentDispatch.failed_at')
                    ->label(__('admin.columns.last_attempt'))
                    ->dateTime()
                    ->placeholder(__('admin.placeholders.not_provided')),
                TextColumn::make('next_retry_at')
                    ->label(__('admin.columns.next_retry'))
                    ->dateTime()
                    ->placeholder(__('admin.placeholders.manual_review')),
                TextColumn::make('retry_events_count')
                    ->label(__('admin.columns.manual_retries'))
                    ->numeric(),
                TextColumn::make('currentRetryEvent.actor.email')
                    ->label(__('admin.columns.last_operator'))
                    ->placeholder(__('admin.placeholders.none')),
                TextColumn::make('currentRetryEvent.reason')
                    ->label(__('admin.columns.retry_reason'))
                    ->placeholder(__('admin.placeholders.none'))
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('requestedBy.email')
                    ->label(__('admin.columns.requested_by'))
                    ->placeholder(__('admin.placeholders.deleted_subject')),
                TextColumn::make('pipeline_version')
                    ->label(__('admin.columns.version'))
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.columns.status'))
                    ->options(collect([
                        'failed',
                        'processing',
                        'queued',
                    ])->mapWithKeys(
                        fn (string $status): array => [
                            $status => AdminLabel::value(
                                'analysis_status',
                                $status,
                            ),
                        ],
                    )->all()),
            ])
            ->recordActions([
                Action::make('manualRetry')
                    ->label(__('admin.actions.manual_analysis_retry.label'))
                    ->modalHeading(
                        __('admin.actions.manual_analysis_retry.heading'),
                    )
                    ->modalDescription(
                        __(
                            'admin.actions.manual_analysis_retry.description',
                        ),
                    )
                    ->visible(
                        fn (Analysis $record): bool => app(
                            RequestManualAnalysisRetry::class,
                        )->isEligible($record),
                    )
                    ->schema([
                        Hidden::make('expected_current_dispatch_id')
                            ->default(
                                fn (Analysis $record): string => (
                                    (string) $record->currentDispatch
                                        ?->getKey()
                                ),
                            )
                            ->required(),
                        Hidden::make('idempotency_key')
                            ->default(fn (): string => (string) Str::uuid())
                            ->required(),
                        Textarea::make('reason')
                            ->label(
                                __(
                                    'admin.actions.manual_analysis_retry.reason',
                                ),
                            )
                            ->helperText(
                                __(
                                    'admin.actions.manual_analysis_retry.reason_help',
                                ),
                            )
                            ->required()
                            ->minLength(10)
                            ->maxLength(1000),
                    ])
                    ->action(
                        function (
                            array $data,
                            Analysis $record,
                        ): void {
                            $actor = auth()->user();

                            if (! $actor instanceof User) {
                                return;
                            }

                            $result = app(
                                RequestManualAnalysisRetry::class,
                            )->request(
                                analysis: $record,
                                operator: $actor,
                                expectedCurrentDispatchId: $data[
                                    'expected_current_dispatch_id'
                                ],
                                idempotencyKey: $data['idempotency_key'],
                                reason: $data['reason'],
                                ipAddress: request()->ip(),
                                userAgent: request()->userAgent(),
                            );

                            Notification::make()
                                ->title(
                                    $result['created']
                                        ? __(
                                            'admin.actions.manual_analysis_retry.success',
                                        )
                                        : __(
                                            'admin.actions.manual_analysis_retry.replayed',
                                        ),
                                )
                                ->success()
                                ->send();
                        },
                    ),
            ])
            ->defaultSort('failed_at', 'asc');
    }

    public static function getPages(): array
    {
        return ['index' => ListAnalysisOperations::route('/')];
    }
}
