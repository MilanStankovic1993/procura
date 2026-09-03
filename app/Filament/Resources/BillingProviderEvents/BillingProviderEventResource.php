<?php

namespace App\Filament\Resources\BillingProviderEvents;

use App\Filament\Resources\BillingProviderEvents\Pages\ListBillingProviderEvents;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Support\AdminLabel;
use App\Models\BillingProviderEvent;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class BillingProviderEventResource extends ReadOnlyResource
{
    protected static ?string $model = BillingProviderEvent::class;

    protected static ?string $translationKey = 'billing_provider_events';

    protected static ?string $navigationGroupKey = 'operations';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label(__('admin.columns.occurred_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('organization.name')
                    ->label(__('admin.columns.organization'))
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('event_type')
                    ->label(__('admin.columns.event'))
                    ->formatStateUsing(
                        fn (string $state): string => AdminLabel::value(
                            'billing_event_type',
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
                TextColumn::make('outcome')
                    ->label(__('admin.columns.outcome'))
                    ->formatStateUsing(
                        fn (string $state): string => AdminLabel::value(
                            'billing_event_outcome',
                            $state,
                        ),
                    )
                    ->badge(),
                TextColumn::make('reason_code')
                    ->label(__('admin.columns.reason'))
                    ->formatStateUsing(
                        fn (string $state): string => AdminLabel::value(
                            'billing_event_reason',
                            $state,
                        ),
                    )
                    ->wrap(),
                TextColumn::make('projected_plan_code')
                    ->label(__('admin.columns.projected_plan'))
                    ->formatStateUsing(
                        fn (?string $state): string => AdminLabel::value(
                            'plan_code',
                            $state,
                        ),
                    )
                    ->placeholder('—'),
                IconColumn::make('livemode')
                    ->label(__('admin.columns.live_mode'))
                    ->boolean(),
            ])
            ->defaultSort('occurred_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListBillingProviderEvents::route('/')];
    }
}
