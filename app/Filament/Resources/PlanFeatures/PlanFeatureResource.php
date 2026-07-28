<?php

namespace App\Filament\Resources\PlanFeatures;

use App\Filament\Resources\PlanFeatures\Pages\ListPlanFeatures;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Support\AdminLabel;
use App\Models\PlanFeature;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlanFeatureResource extends ReadOnlyResource
{
    protected static ?string $model = PlanFeature::class;

    protected static ?string $translationKey = 'entitlements';

    protected static ?string $navigationGroupKey = 'subscriptions';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('plan.name')->label(__('admin.columns.plan'))->sortable(),
                TextColumn::make('plan.version')->label(__('admin.columns.version'))->sortable(),
                TextColumn::make('feature_code')
                    ->label(__('admin.columns.feature'))
                    ->formatStateUsing(fn ($state): string => AdminLabel::value('feature', $state))
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_enabled')->label(__('admin.columns.enabled'))->boolean(),
                TextColumn::make('limit')
                    ->label(__('admin.columns.limit'))
                    ->placeholder(__('admin.placeholders.unlimited'))
                    ->numeric(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPlanFeatures::route('/')];
    }
}
