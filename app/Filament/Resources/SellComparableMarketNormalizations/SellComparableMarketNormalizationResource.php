<?php

namespace App\Filament\Resources\SellComparableMarketNormalizations;

use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Resources\SellComparableMarketNormalizations\Pages\ListSellComparableMarketNormalizations;
use App\Filament\Support\AdminLabel;
use App\Models\SellComparableMarketNormalization;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class SellComparableMarketNormalizationResource extends ReadOnlyResource
{
    protected static ?string $model = SellComparableMarketNormalization::class;

    protected static ?string $translationKey = 'sell_comparable_market_normalizations';

    protected static ?string $navigationGroupKey = 'operations';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query->with([
                    'ownedProduct:id,organization_id',
                    'comparableRecord:id,title',
                    'createdBy:id,email',
                    'organization:id,name',
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
                TextColumn::make('owned_product_id')
                    ->label(__('admin.columns.owned_product'))
                    ->copyable()
                    ->limit(18)
                    ->tooltip(
                        fn (SellComparableMarketNormalization $record): string => (
                            $record->owned_product_id
                        ),
                    ),
                TextColumn::make('comparableRecord.title')
                    ->label(__('admin.columns.comparable'))
                    ->searchable()
                    ->limit(35),
                TextColumn::make('compatibility_status')
                    ->label(__('admin.columns.compatibility'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'market_compatibility_status',
                            $state,
                        ),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('market_route')
                    ->label(__('admin.columns.market_route'))
                    ->state(
                        fn (SellComparableMarketNormalization $record): string => sprintf(
                            '%s / %s -> %s / %s',
                            $record->source_country_code,
                            $record->source_currency_code,
                            $record->target_country_code,
                            $record->target_currency_code,
                        ),
                    ),
                TextColumn::make('source_amount_minor')
                    ->label(__('admin.columns.source_amount_minor'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('normalized_amount_minor')
                    ->label(__('admin.columns.normalized_amount_minor'))
                    ->numeric()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('market_factor_basis_points')
                    ->label(__('admin.columns.market_factor_basis_points'))
                    ->numeric()
                    ->placeholder('—'),
                TextColumn::make('landed_costs_minor')
                    ->label(__('admin.columns.landed_costs_minor'))
                    ->state(
                        fn (SellComparableMarketNormalization $record): int => (
                            $record->shipping_minor
                            + $record->import_duty_minor
                            + $record->tax_minor
                            + $record->other_cost_minor
                        ),
                    )
                    ->numeric(),
                TextColumn::make('rate_value')
                    ->label(__('admin.columns.exchange_rate'))
                    ->placeholder('—')
                    ->formatStateUsing(
                        fn ($state, SellComparableMarketNormalization $record): string => sprintf(
                            '%s (%s)',
                            (string) $state,
                            AdminLabel::value(
                                'exchange_rate_direction',
                                $record->rate_direction,
                            ),
                        ),
                    ),
                TextColumn::make('rate_provider')
                    ->label(__('admin.columns.rate_provider'))
                    ->placeholder('—')
                    ->limit(30),
                TextColumn::make('rate_provider_reference')
                    ->label(__('admin.columns.rate_reference'))
                    ->placeholder('—')
                    ->copyable()
                    ->limit(30),
                TextColumn::make('rate_effective_at')
                    ->label(__('admin.columns.rate_effective_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('evidence_reference')
                    ->label(__('admin.columns.evidence_reference'))
                    ->copyable()
                    ->limit(35)
                    ->tooltip(
                        fn (SellComparableMarketNormalization $record): string => (
                            $record->evidence_reference
                        ),
                    ),
                TextColumn::make('compatibility_note')
                    ->label(__('admin.columns.evidence_note'))
                    ->limit(50)
                    ->wrap(),
                TextColumn::make('evidence_hash')
                    ->label(__('admin.columns.evidence_hash'))
                    ->copyable()
                    ->limit(12),
                TextColumn::make('calculation_version')
                    ->label(__('admin.columns.version'))
                    ->badge(),
                TextColumn::make('createdBy.email')
                    ->label(__('admin.columns.actor'))
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('observed_at')
                    ->label(__('admin.columns.observed_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListSellComparableMarketNormalizations::route('/')];
    }
}
