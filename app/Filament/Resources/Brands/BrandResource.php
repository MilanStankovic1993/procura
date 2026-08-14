<?php

namespace App\Filament\Resources\Brands;

use App\Filament\Resources\Brands\Pages\ListBrands;
use App\Filament\Resources\ReadOnlyResource;
use App\Models\Brand;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class BrandResource extends ReadOnlyResource
{
    protected static ?string $model = Brand::class;

    protected static ?string $translationKey = 'brands';

    protected static ?string $navigationGroupKey = 'market_reference';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.columns.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product_models_count')
                    ->counts('productModels')
                    ->label(__('admin.columns.product_models'))
                    ->numeric()
                    ->sortable(),
                IconColumn::make('active')
                    ->label(__('admin.columns.active'))
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label(__('admin.columns.updated_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('active')->label(__('admin.columns.active')),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ListBrands::route('/')];
    }
}
