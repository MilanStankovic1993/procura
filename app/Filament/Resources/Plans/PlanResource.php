<?php

namespace App\Filament\Resources\Plans;

use App\Filament\Resources\Plans\Pages\ListPlans;
use App\Filament\Resources\ReadOnlyResource;
use App\Models\Plan;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlanResource extends ReadOnlyResource
{
    protected static ?string $model = Plan::class;

    protected static ?string $translationKey = 'plans';

    protected static ?string $navigationGroupKey = 'subscriptions';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('admin.columns.name'))->searchable()->sortable(),
                TextColumn::make('code')->label(__('admin.columns.code'))->badge()->sortable(),
                TextColumn::make('version')->label(__('admin.columns.version'))->sortable(),
                IconColumn::make('is_active')->label(__('admin.columns.active'))->boolean(),
                TextColumn::make('features_count')->counts('features')->label(__('admin.columns.entitlements')),
                TextColumn::make('assignments_count')->counts('assignments')->label(__('admin.columns.assignments')),
                TextColumn::make('updated_at')->label(__('admin.columns.updated_at'))->dateTime()->sortable(),
            ])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return ['index' => ListPlans::route('/')];
    }
}
