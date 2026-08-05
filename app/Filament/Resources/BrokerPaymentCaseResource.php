<?php

namespace App\Filament\Resources;

use App\Enums\BrokerRequests\BrokerPaymentCaseStatus;
use App\Enums\BrokerRequests\BrokerPaymentCaseType;
use App\Filament\Support\AdminLabel;
use App\Models\BrokerPaymentCase;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Symfony\Component\Intl\Currencies;
use Throwable;

final class BrokerPaymentCaseResource extends ReadOnlyResource
{
    protected static ?string $model = BrokerPaymentCase::class;

    protected static ?string $translationKey = 'broker_payment_cases';

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
                    'brokerRequest:id,title',
                    'openedBy:id,email',
                    'currentEvent.actor:id,email',
                ]),
            )
            ->columns([
                TextColumn::make('opened_at')
                    ->label(__('admin.columns.opened_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('organization.name')
                    ->label(__('admin.columns.organization'))
                    ->searchable(),
                TextColumn::make('brokerRequest.title')
                    ->label(__('admin.columns.broker_request'))
                    ->searchable()
                    ->limit(42)
                    ->wrap(),
                TextColumn::make('type')
                    ->label(__('admin.columns.type'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'broker_payment_case_type',
                            $state,
                        ),
                    )
                    ->badge(),
                TextColumn::make('status')
                    ->label(__('admin.columns.status'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'broker_payment_case_status',
                            $state,
                        ),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('requested_amount_minor')
                    ->label(__('admin.columns.requested_amount'))
                    ->formatStateUsing(
                        fn (int $state, BrokerPaymentCase $record): string => (
                            self::money($state, $record->currency_code)
                        ),
                    ),
                TextColumn::make('resolved_amount_minor')
                    ->label(__('admin.columns.resolved_amount'))
                    ->formatStateUsing(
                        fn (?int $state, BrokerPaymentCase $record): string => (
                            $state === null
                                ? __('admin.placeholders.not_provided')
                                : self::money($state, $record->currency_code)
                        ),
                    ),
                TextColumn::make('resolution_outcome')
                    ->label(__('admin.columns.resolution_outcome'))
                    ->formatStateUsing(
                        fn ($state): string => $state === null
                            ? __('admin.placeholders.not_provided')
                            : AdminLabel::value(
                                'broker_payment_case_outcome',
                                $state,
                            ),
                    )
                    ->badge(),
                TextColumn::make('external_case_reference')
                    ->label(__('admin.columns.external_case_reference'))
                    ->copyable()
                    ->limit(30),
                TextColumn::make('currentEvent.evidence_reference')
                    ->label(__('admin.columns.evidence_reference'))
                    ->copyable()
                    ->limit(30),
                TextColumn::make('openedBy.email')
                    ->label(__('admin.columns.opened_by'))
                    ->placeholder(__('admin.placeholders.system')),
                TextColumn::make('event_sequence')
                    ->label(__('admin.columns.sequence'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('resolved_at')
                    ->label(__('admin.columns.resolved_at'))
                    ->dateTime()
                    ->placeholder(__('admin.placeholders.not_provided')),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.columns.type'))
                    ->options(collect(BrokerPaymentCaseType::cases())
                        ->mapWithKeys(
                            fn (BrokerPaymentCaseType $type): array => [
                                $type->value => AdminLabel::value(
                                    'broker_payment_case_type',
                                    $type,
                                ),
                            ],
                        )
                        ->all()),
                SelectFilter::make('status')
                    ->label(__('admin.columns.status'))
                    ->options(collect(BrokerPaymentCaseStatus::cases())
                        ->mapWithKeys(
                            fn (BrokerPaymentCaseStatus $status): array => [
                                $status->value => AdminLabel::value(
                                    'broker_payment_case_status',
                                    $status,
                                ),
                            ],
                        )
                        ->all()),
            ])
            ->defaultSort('opened_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => BrokerPaymentCaseListPage::route('/')];
    }

    private static function money(int $minor, string $currency): string
    {
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
