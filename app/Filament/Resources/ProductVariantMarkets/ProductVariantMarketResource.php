<?php

namespace App\Filament\Resources\ProductVariantMarkets;

use App\Filament\Resources\ProductVariantMarkets\Pages\ListProductVariantMarkets;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Support\AdminLabel;
use App\Models\ProductVariantMarket;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class ProductVariantMarketResource extends ReadOnlyResource
{
    protected static ?string $model = ProductVariantMarket::class;

    protected static ?string $translationKey = 'product_variant_markets';

    protected static ?string $navigationGroupKey = 'market_reference';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('productVariant.productModel.brand.name')
                    ->label(__('admin.columns.brand'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productVariant.name')
                    ->label(__('admin.columns.product_variant'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('country.name')
                    ->label(__('admin.columns.country'))
                    ->formatStateUsing(
                        fn (string $state, ProductVariantMarket $record): string => AdminLabel::country(
                            $record->country_code,
                            $state,
                        ),
                    )
                    ->searchable()
                    ->sortable(),
                TextColumn::make('market_model_number')
                    ->label(__('admin.columns.market_model_number'))
                    ->placeholder(__('admin.placeholders.none'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('voltage_millivolts')
                    ->label(__('admin.columns.voltage'))
                    ->formatStateUsing(
                        fn (?int $state): ?string => $state === null
                            ? null
                            : number_format($state / 1000, 0).' V',
                    )
                    ->placeholder(__('admin.placeholders.none'))
                    ->sortable(),
                TextColumn::make('plug_type')
                    ->label(__('admin.columns.plug_type'))
                    ->placeholder(__('admin.placeholders.none'))
                    ->sortable(),
                TextColumn::make('measurement_system')
                    ->label(__('admin.columns.measurement'))
                    ->formatStateUsing(fn ($state): string => AdminLabel::value('measurement', $state))
                    ->placeholder(__('admin.placeholders.none')),
                IconColumn::make('warranty_applicable')
                    ->label(__('admin.columns.warranty_applicable'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('country_code')
                    ->label(__('admin.columns.country'))
                    ->relationship('country', 'name')
                    ->searchable(),
                TernaryFilter::make('warranty_applicable')
                    ->label(__('admin.columns.warranty_applicable'))
                    ->nullable(),
            ])
            ->defaultSort('country_code');
    }

    public static function getPages(): array
    {
        return ['index' => ListProductVariantMarkets::route('/')];
    }
}
