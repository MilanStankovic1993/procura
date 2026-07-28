<?php

namespace App\Filament\Resources\Subscriptions;

use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Support\AdminLabel;
use App\Models\OrganizationPlanAssignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubscriptionResource extends ReadOnlyResource
{
    protected static ?string $model = OrganizationPlanAssignment::class;

    protected static ?string $translationKey = 'subscriptions';

    protected static ?string $navigationGroupKey = 'subscriptions';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('organization.name')->label(__('admin.columns.organization'))->searchable()->sortable(),
                TextColumn::make('plan.name')->label(__('admin.columns.plan'))->sortable(),
                TextColumn::make('plan.version')->label(__('admin.columns.version'))->sortable(),
                TextColumn::make('source')
                    ->label(__('admin.columns.source'))
                    ->formatStateUsing(
                        fn (string $state): string => AdminLabel::value(
                            'subscription_source',
                            $state,
                        ),
                    )
                    ->badge(),
                TextColumn::make('provider_status')
                    ->label(__('admin.columns.provider_status'))
                    ->formatStateUsing(
                        fn (?string $state): string => AdminLabel::value(
                            'billing_provider_status',
                            $state,
                        ),
                    )
                    ->placeholder('—')
                    ->badge(),
                TextColumn::make('starts_at')->label(__('admin.columns.starts_at'))->dateTime()->sortable(),
                TextColumn::make('ends_at')
                    ->label(__('admin.columns.ends_at'))
                    ->dateTime()
                    ->placeholder(__('admin.placeholders.active'))
                    ->sortable(),
            ])
            ->defaultSort('starts_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListSubscriptions::route('/')];
    }
}
