<?php

namespace App\Filament\Resources\PrivacyRequests;

use App\Filament\Resources\PrivacyRequests\Pages\ListPrivacyRequests;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Support\AdminLabel;
use App\Models\PrivacyRequest;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class PrivacyRequestResource extends ReadOnlyResource
{
    protected static ?string $model = PrivacyRequest::class;

    protected static ?string $translationKey = 'privacy_requests';

    protected static ?string $navigationGroupKey = 'operations';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query->with([
                    'subject:id,name,email',
                    'residenceCountry:code,name',
                    'currentEvent.actor:id,email',
                ]),
            )
            ->columns([
                TextColumn::make('requested_at')
                    ->label(__('admin.columns.requested_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('subject.email')
                    ->label(__('admin.columns.subject'))
                    ->placeholder(__('admin.placeholders.deleted_subject'))
                    ->searchable(),
                TextColumn::make('type')
                    ->label(__('admin.columns.type'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'privacy_request_type',
                            $state,
                        ),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.columns.status'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'privacy_request_status',
                            $state,
                        ),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('response_target_at')
                    ->label(__('admin.columns.response_target_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('residenceCountry.name')
                    ->label(__('admin.columns.country'))
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->formatStateUsing(
                        fn (
                            string $state,
                            PrivacyRequest $record,
                        ): string => AdminLabel::country(
                            $record->residence_country_code,
                            $state,
                        ),
                    ),
                TextColumn::make('blocking_reason_codes')
                    ->label(__('admin.columns.blockers'))
                    ->formatStateUsing(
                        fn (array $state): string => collect($state)
                            ->map(
                                fn (string $code): string => (
                                    AdminLabel::value(
                                        'privacy_request_blocker',
                                        $code,
                                    )
                                ),
                            )
                            ->join(', '),
                    )
                    ->placeholder(__('admin.placeholders.none'))
                    ->wrap(),
                TextColumn::make('reason')
                    ->label(__('admin.columns.reason'))
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('event_sequence')
                    ->label(__('admin.columns.sequence'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('current_event_id')
                    ->label(__('admin.columns.current_event'))
                    ->copyable()
                    ->limit(16),
                TextColumn::make('currentEvent.reason_code')
                    ->label(__('admin.columns.event_reason'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'privacy_request_reason',
                            $state,
                        ),
                    )
                    ->badge(),
                TextColumn::make('currentEvent.evidence_reference')
                    ->label(__('admin.columns.evidence_reference'))
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->copyable()
                    ->limit(32),
                TextColumn::make('currentEvent.actor.email')
                    ->label(__('admin.columns.actor'))
                    ->placeholder(__('admin.placeholders.system')),
                TextColumn::make('workflow_version')
                    ->label(__('admin.columns.version'))
                    ->badge(),
                TextColumn::make('resolved_at')
                    ->label(__('admin.columns.resolved_at'))
                    ->dateTime()
                    ->placeholder(__('admin.placeholders.open'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.columns.type'))
                    ->options([
                        'data_export' => AdminLabel::value(
                            'privacy_request_type',
                            'data_export',
                        ),
                        'account_deletion' => AdminLabel::value(
                            'privacy_request_type',
                            'account_deletion',
                        ),
                    ]),
                SelectFilter::make('status')
                    ->label(__('admin.columns.status'))
                    ->options(collect([
                        'requested',
                        'in_review',
                        'action_required',
                        'approved',
                        'fulfilled',
                        'rejected',
                        'cancelled',
                    ])->mapWithKeys(
                        fn (string $status): array => [
                            $status => AdminLabel::value(
                                'privacy_request_status',
                                $status,
                            ),
                        ],
                    )->all()),
            ])
            ->defaultSort('requested_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListPrivacyRequests::route('/')];
    }
}
