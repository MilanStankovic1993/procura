<?php

namespace App\Filament\Resources\ProductVariants;

use App\Filament\Resources\ProductVariants\Pages\ListProductVariants;
use App\Filament\Resources\ReadOnlyResource;
use App\Models\ProductVariant;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class ProductVariantResource extends ReadOnlyResource
{
    protected static ?string $model = ProductVariant::class;

    protected static ?string $translationKey = 'product_variants';

    protected static ?string $navigationGroupKey = 'market_reference';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('productModel.brand.name')
                    ->label(__('admin.columns.brand'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productModel.name')
                    ->label(__('admin.columns.product_model'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('admin.columns.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sku')
                    ->label(__('admin.columns.sku'))
                    ->placeholder(__('admin.placeholders.none'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('canonical_key')
                    ->label(__('admin.columns.canonical_key'))
                    ->searchable()
                    ->copyable(),
                TextColumn::make('market_contexts_count')
                    ->counts('marketContexts')
                    ->label(__('admin.columns.markets'))
                    ->numeric()
                    ->sortable(),
                IconColumn::make('active')
                    ->label(__('admin.columns.active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('product_model_id')
                    ->label(__('admin.columns.product_model'))
                    ->relationship('productModel', 'name')
                    ->searchable(),
                TernaryFilter::make('active')->label(__('admin.columns.active')),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ListProductVariants::route('/')];
    }
}
