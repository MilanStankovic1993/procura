<?php

namespace App\Filament\Resources\Usages;

use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Resources\Usages\Pages\ListUsages;
use App\Filament\Support\AdminLabel;
use App\Models\SubscriptionUsage;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsageResource extends ReadOnlyResource
{
    protected static ?string $model = SubscriptionUsage::class;

    protected static ?string $translationKey = 'usages';

    protected static ?string $navigationGroupKey = 'subscriptions';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('organization.name')->label(__('admin.columns.organization'))->searchable(),
                TextColumn::make('feature_code')
                    ->label(__('admin.columns.feature'))
                    ->formatStateUsing(fn ($state): string => AdminLabel::value('feature', $state))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('used')->label(__('admin.columns.used'))->numeric()->sortable(),
                TextColumn::make('period_start')->label(__('admin.columns.period_start'))->date()->sortable(),
                TextColumn::make('period_end')->label(__('admin.columns.period_end'))->date()->sortable(),
                TextColumn::make('updated_at')->label(__('admin.columns.updated_at'))->dateTime()->sortable(),
            ])
            ->defaultSort('period_start', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListUsages::route('/')];
    }
}
