<?php

namespace App\Filament\Resources;

use App\Enums\BrokerRequests\BrokerCommissionStatus;
use App\Filament\Support\AdminLabel;
use App\Models\BrokerCommission;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Symfony\Component\Intl\Currencies;
use Throwable;

final class BrokerCommissionResource extends ReadOnlyResource
{
    protected static ?string $model = BrokerCommission::class;

    protected static ?string $translationKey = 'broker_commissions';

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
                    'currentEvent.actor:id,email',
                ]),
            )
            ->columns([
                TextColumn::make('recorded_at')
                    ->label(__('admin.columns.recorded_at'))
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
                            'broker_commission_status',
                            $state,
                        ),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('amount_minor')
                    ->label(__('admin.columns.commission'))
                    ->formatStateUsing(
                        fn (int $state, BrokerCommission $record): string => (
                            self::money($state, $record->currency_code)
                        ),
                    )
                    ->sortable(),
                TextColumn::make('base_minor')
                    ->label(__('admin.columns.commission_base'))
                    ->formatStateUsing(
                        fn (int $state, BrokerCommission $record): string => (
                            self::money($state, $record->currency_code)
                        ),
                    ),
                TextColumn::make('rate_basis_points')
                    ->label(__('admin.columns.commission_rate'))
                    ->suffix(' bp')
                    ->numeric(),
                TextColumn::make('rule_version')
                    ->label(__('admin.columns.rule_version'))
                    ->badge(),
                TextColumn::make('currentEvent.reason_code')
                    ->label(__('admin.columns.event_reason'))
                    ->badge(),
                TextColumn::make('currentEvent.evidence_reference')
                    ->label(__('admin.columns.evidence_reference'))
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->copyable()
                    ->limit(30),
                TextColumn::make('event_sequence')
                    ->label(__('admin.columns.sequence'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('settled_at')
                    ->label(__('admin.columns.settled_at'))
                    ->dateTime()
                    ->placeholder(__('admin.placeholders.not_provided')),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.columns.status'))
                    ->options(collect(BrokerCommissionStatus::cases())
                        ->mapWithKeys(
                            fn (BrokerCommissionStatus $status): array => [
                                $status->value => AdminLabel::value(
                                    'broker_commission_status',
                                    $status,
                                ),
                            ],
                        )
                        ->all()),
            ])
            ->defaultSort('recorded_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => BrokerCommissionListPage::route('/')];
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
