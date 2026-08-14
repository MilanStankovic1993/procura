<?php

namespace App\Filament\Resources\ProductModels;

use App\Filament\Resources\ProductModels\Pages\ListProductModels;
use App\Filament\Resources\ReadOnlyResource;
use App\Models\ProductModel;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class ProductModelResource extends ReadOnlyResource
{
    protected static ?string $model = ProductModel::class;

    protected static ?string $translationKey = 'product_models';

    protected static ?string $navigationGroupKey = 'market_reference';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('brand.name')
                    ->label(__('admin.columns.brand'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('admin.columns.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('model_number')
                    ->label(__('admin.columns.model_number'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label(__('admin.columns.category'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('canonical_key')
                    ->label(__('admin.columns.canonical_key'))
                    ->searchable()
                    ->copyable(),
                TextColumn::make('variants_count')
                    ->counts('variants')
                    ->label(__('admin.columns.variants'))
                    ->numeric()
                    ->sortable(),
                IconColumn::make('active')
                    ->label(__('admin.columns.active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('brand_id')
                    ->label(__('admin.columns.brand'))
                    ->relationship('brand', 'name')
                    ->searchable(),
                SelectFilter::make('product_category_id')
                    ->label(__('admin.columns.category'))
                    ->relationship('category', 'name')
                    ->searchable(),
                TernaryFilter::make('active')->label(__('admin.columns.active')),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ListProductModels::route('/')];
    }
}
