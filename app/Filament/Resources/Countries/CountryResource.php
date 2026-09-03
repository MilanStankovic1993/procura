<?php

namespace App\Filament\Resources\Countries;

use App\Filament\Resources\Countries\Pages\ListCountries;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Support\AdminLabel;
use App\Models\Country;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CountryResource extends ReadOnlyResource
{
    protected static ?string $model = Country::class;

    protected static ?string $translationKey = 'countries';

    protected static ?string $navigationGroupKey = 'market_reference';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label(__('admin.columns.code'))->searchable()->sortable(),
                TextColumn::make('alpha3_code')->label(__('admin.columns.alpha3'))->searchable()->sortable(),
                TextColumn::make('name')
                    ->label(__('admin.columns.name'))
                    ->formatStateUsing(
                        fn (string $state, Country $record): string => AdminLabel::country($record->code, $state),
                    )
                    ->searchable()
                    ->sortable(),
                TextColumn::make('continent.code')
                    ->label(__('admin.columns.continent'))
                    ->formatStateUsing(fn ($state): string => AdminLabel::value('continent', $state))
                    ->sortable(),
                TextColumn::make('currency_code')->label(__('admin.columns.currency'))->sortable(),
                TextColumn::make('measurement_system')
                    ->label(__('admin.columns.measurement'))
                    ->formatStateUsing(fn ($state): string => AdminLabel::value('measurement', $state)),
                IconColumn::make('active')->label(__('admin.columns.active'))->boolean(),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ListCountries::route('/')];
    }
}
