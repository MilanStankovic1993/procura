<?php

namespace App\Filament\Resources;

use App\Enums\BrokerRequests\BrokerRequestOfferStatus;
use App\Filament\Support\AdminLabel;
use App\Models\BrokerRequestOffer;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Symfony\Component\Intl\Currencies;
use Throwable;

final class BrokerRequestOfferResource extends ReadOnlyResource
{
    protected static ?string $model = BrokerRequestOffer::class;

    protected static ?string $translationKey = 'broker_request_offers';

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
                    'presentedBy:id,email',
                    'currentEvent.actor:id,email',
                ]),
            )
            ->columns([
                TextColumn::make('presented_at')
                    ->label(__('admin.columns.presented_at'))
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
                TextColumn::make('supplier_display_name')
                    ->label(__('admin.columns.supplier'))
                    ->searchable(),
                TextColumn::make('supplier_reference')
                    ->label(__('admin.columns.supplier_reference'))
                    ->searchable()
                    ->copyable()
                    ->limit(30),
                TextColumn::make('status')
                    ->label(__('admin.columns.status'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'broker_offer_status',
                            $state,
                        ),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('condition')
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
                TextColumn::make('total_minor')
                    ->label(__('admin.columns.total'))
                    ->formatStateUsing(
                        fn (int $state, BrokerRequestOffer $record): string => (
                            self::money($state, $record->currency_code)
                        ),
                    )
                    ->sortable(),
                TextColumn::make('commission_amount_minor')
                    ->label(__('admin.columns.commission'))
                    ->formatStateUsing(
                        fn (int $state, BrokerRequestOffer $record): string => (
                            self::money($state, $record->currency_code)
                        ),
                    ),
                TextColumn::make('payable_total_minor')
                    ->label(__('admin.columns.payable_total'))
                    ->formatStateUsing(
                        fn (int $state, BrokerRequestOffer $record): string => (
                            self::money($state, $record->currency_code)
                        ),
                    )
                    ->sortable(),
                TextColumn::make('valid_until')
                    ->label(__('admin.columns.valid_until'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('estimated_delivery_date')
                    ->label(__('admin.columns.estimated_delivery'))
                    ->date()
                    ->placeholder(__('admin.placeholders.not_provided')),
                TextColumn::make('currentEvent.reason_code')
                    ->label(__('admin.columns.event_reason'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'broker_offer_reason',
                            $state,
                        ),
                    )
                    ->badge(),
                TextColumn::make('currentEvent.evidence_reference')
                    ->label(__('admin.columns.evidence_reference'))
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->copyable()
                    ->limit(30),
                TextColumn::make('presentedBy.email')
                    ->label(__('admin.columns.presented_by'))
                    ->placeholder(__('admin.placeholders.system')),
                TextColumn::make('event_sequence')
                    ->label(__('admin.columns.sequence'))
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.columns.status'))
                    ->options(collect(BrokerRequestOfferStatus::cases())
                        ->mapWithKeys(
                            fn (BrokerRequestOfferStatus $status): array => [
                                $status->value => AdminLabel::value(
                                    'broker_offer_status',
                                    $status,
                                ),
                            ],
                        )
                        ->all()),
            ])
            ->defaultSort('presented_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => BrokerRequestOfferListPage::route('/')];
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
