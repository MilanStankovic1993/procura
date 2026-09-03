<?php

namespace App\Filament\Resources\Currencies;

use App\Filament\Resources\Currencies\Pages\ListCurrencies;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Support\AdminLabel;
use App\Models\Currency;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CurrencyResource extends ReadOnlyResource
{
    protected static ?string $model = Currency::class;

    protected static ?string $translationKey = 'currencies';

    protected static ?string $navigationGroupKey = 'market_reference';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label(__('admin.columns.code'))->searchable()->sortable(),
                TextColumn::make('numeric_code')->label(__('admin.columns.numeric'))->sortable(),
                TextColumn::make('name')
                    ->label(__('admin.columns.name'))
                    ->formatStateUsing(
                        fn (string $state, Currency $record): string => AdminLabel::currency($record->code, $state),
                    )
                    ->searchable()
                    ->sortable(),
                TextColumn::make('symbol')->label(__('admin.columns.symbol')),
                TextColumn::make('minor_unit')->label(__('admin.columns.minor_unit'))->numeric(),
                IconColumn::make('active')->label(__('admin.columns.active'))->boolean(),
                TextColumn::make('source_version')->label(__('admin.columns.source')),
            ])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return ['index' => ListCurrencies::route('/')];
    }
}
