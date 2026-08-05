<?php

namespace App\Filament\Resources;

use App\Enums\BrokerRequests\BrokerTransactionStatus;
use App\Filament\Support\AdminLabel;
use App\Models\BrokerTransaction;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Symfony\Component\Intl\Currencies;
use Throwable;

final class BrokerTransactionResource extends ReadOnlyResource
{
    protected static ?string $model = BrokerTransaction::class;

    protected static ?string $translationKey = 'broker_transactions';

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
                    'commission:id,broker_transaction_id,status',
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
                TextColumn::make('status')
                    ->label(__('admin.columns.status'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'broker_transaction_status',
                            $state,
                        ),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('payable_total_minor')
                    ->label(__('admin.columns.payable_total'))
                    ->formatStateUsing(
                        fn (int $state, BrokerTransaction $record): string => (
                            self::money($state, $record->currency_code)
                        ),
                    )
                    ->sortable(),
                TextColumn::make('commission_amount_minor')
                    ->label(__('admin.columns.commission'))
                    ->formatStateUsing(
                        fn (int $state, BrokerTransaction $record): string => (
                            self::money($state, $record->currency_code)
                        ),
                    ),
                TextColumn::make('commission.status')
                    ->label(__('admin.columns.commission_status'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'broker_commission_status',
                            $state,
                        ),
                    )
                    ->badge(),
                TextColumn::make('currentEvent.reason_code')
                    ->label(__('admin.columns.event_reason'))
                    ->badge(),
                TextColumn::make('currentEvent.evidence_reference')
                    ->label(__('admin.columns.evidence_reference'))
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->copyable()
                    ->limit(30),
                TextColumn::make('openedBy.email')
                    ->label(__('admin.columns.opened_by'))
                    ->placeholder(__('admin.placeholders.system')),
                TextColumn::make('event_sequence')
                    ->label(__('admin.columns.sequence'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->label(__('admin.columns.completed_at'))
                    ->dateTime()
                    ->placeholder(__('admin.placeholders.not_provided')),
                TextColumn::make('cancelled_at')
                    ->label(__('admin.columns.cancelled_at'))
                    ->dateTime()
                    ->placeholder(__('admin.placeholders.not_provided')),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.columns.status'))
                    ->options(collect(BrokerTransactionStatus::cases())
                        ->mapWithKeys(
                            fn (BrokerTransactionStatus $status): array => [
                                $status->value => AdminLabel::value(
                                    'broker_transaction_status',
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
        return ['index' => BrokerTransactionListPage::route('/')];
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
