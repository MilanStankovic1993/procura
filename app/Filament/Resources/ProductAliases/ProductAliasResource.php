<?php

namespace App\Filament\Resources\ProductAliases;

use App\Filament\Resources\ProductAliases\Pages\ListProductAliases;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Support\AdminLabel;
use App\Models\ProductAlias;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class ProductAliasResource extends ReadOnlyResource
{
    protected static ?string $model = ProductAlias::class;

    protected static ?string $translationKey = 'product_aliases';

    protected static ?string $navigationGroupKey = 'market_reference';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('alias')
                    ->label(__('admin.columns.alias'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productModel.brand.name')
                    ->label(__('admin.columns.brand'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productModel.name')
                    ->label(__('admin.columns.product_model'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productVariant.name')
                    ->label(__('admin.columns.product_variant'))
                    ->placeholder(__('admin.placeholders.model_wide'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('locale')
                    ->label(__('admin.columns.locale'))
                    ->placeholder(__('admin.placeholders.all_locales'))
                    ->sortable(),
                TextColumn::make('country.name')
                    ->label(__('admin.columns.country'))
                    ->formatStateUsing(
                        fn (string $state, ProductAlias $record): string => AdminLabel::country(
                            $record->country_code,
                            $state,
                        ),
                    )
                    ->placeholder(__('admin.placeholders.global'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('source')
                    ->label(__('admin.columns.source'))
                    ->searchable()
                    ->sortable(),
                IconColumn::make('active')
                    ->label(__('admin.columns.active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('country_code')
                    ->label(__('admin.columns.country'))
                    ->relationship('country', 'name')
                    ->searchable(),
                SelectFilter::make('source')
                    ->label(__('admin.columns.source'))
                    ->options(fn (): array => ProductAlias::query()
                        ->whereNotNull('source')
                        ->distinct()
                        ->orderBy('source')
                        ->pluck('source', 'source')
                        ->all()),
                TernaryFilter::make('active')->label(__('admin.columns.active')),
            ])
            ->defaultSort('alias');
    }

    public static function getPages(): array
    {
        return ['index' => ListProductAliases::route('/')];
    }
}
