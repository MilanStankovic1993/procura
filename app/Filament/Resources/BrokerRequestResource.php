<?php

namespace App\Filament\Resources;

use App\Enums\BrokerRequests\BrokerRequestStatus;
use App\Filament\Support\AdminLabel;
use App\Models\BrokerRequest;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Symfony\Component\Intl\Currencies;
use Throwable;

final class BrokerRequestResource extends ReadOnlyResource
{
    protected static ?string $model = BrokerRequest::class;

    protected static ?string $translationKey = 'broker_requests';

    protected static ?string $navigationGroupKey = 'operations';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return
            $user instanceof User
            && $user->is_super_admin
            && $user->hasVerifiedEmail();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn ($query) => $query->with([
                    'organization:id,name',
                    'requester:id,name,email',
                    'currentEvent.actor:id,email',
                ]),
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('admin.columns.created_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('organization.name')
                    ->label(__('admin.columns.organization'))
                    ->searchable(),
                TextColumn::make('requester.email')
                    ->label(__('admin.columns.requester'))
                    ->searchable(),
                TextColumn::make('title')
                    ->label(__('admin.columns.title'))
                    ->searchable()
                    ->limit(50)
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__('admin.columns.status'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'broker_request_status',
                            $state,
                        ),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('condition_preference')
                    ->label(__('admin.columns.condition'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'broker_product_condition',
                            $state,
                        ),
                    )
                    ->badge(),
                TextColumn::make('quantity')
                    ->label(__('admin.columns.quantity'))
                    ->numeric(),
                TextColumn::make('budget_max_minor')
                    ->label(__('admin.columns.budget'))
                    ->formatStateUsing(
                        fn (
                            ?int $state,
                            BrokerRequest $record,
                        ): string => self::money(
                            $state,
                            $record->budget_currency_code,
                        ),
                    )
                    ->placeholder(__('admin.placeholders.not_provided')),
                TextColumn::make('target_country_codes')
                    ->label(__('admin.columns.target_markets'))
                    ->getStateUsing(
                        fn (BrokerRequest $record): string => implode(
                            ', ',
                            $record->target_country_codes,
                        ),
                    ),
                TextColumn::make('needed_by')
                    ->label(__('admin.columns.needed_by'))
                    ->date()
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->sortable(),
                TextColumn::make('currentEvent.reason_code')
                    ->label(__('admin.columns.event_reason'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'broker_request_reason',
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
                TextColumn::make('event_sequence')
                    ->label(__('admin.columns.sequence'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('submitted_at')
                    ->label(__('admin.columns.submitted_at'))
                    ->dateTime()
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->sortable(),
                TextColumn::make('resolved_at')
                    ->label(__('admin.columns.resolved_at'))
                    ->dateTime()
                    ->placeholder(__('admin.placeholders.open'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.columns.status'))
                    ->options(collect(BrokerRequestStatus::cases())
                        ->mapWithKeys(
                            fn (BrokerRequestStatus $status): array => [
                                $status->value => AdminLabel::value(
                                    'broker_request_status',
                                    $status,
                                ),
                            ],
                        )
                        ->all()),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => BrokerRequestListPage::route('/')];
    }

    private static function money(?int $minor, ?string $currency): string
    {
        if ($minor === null || $currency === null) {
            return '';
        }

        try {
            $digits = Currencies::getFractionDigits($currency);
        } catch (Throwable) {
            $digits = 2;
        }

        if ($digits === 0) {
            return $minor.' '.$currency;
        }

        $value = str_pad((string) $minor, $digits + 1, '0', STR_PAD_LEFT);

        return substr($value, 0, -$digits)
            .'.'
            .substr($value, -$digits)
            .' '
            .$currency;
    }
}
