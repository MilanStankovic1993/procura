<?php

namespace App\Filament\Resources\NotificationDeliveries;

use App\Filament\Resources\NotificationDeliveries\Pages\ListNotificationDeliveries;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Support\AdminLabel;
use App\Models\NotificationLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NotificationDeliveryResource extends ReadOnlyResource
{
    protected static ?string $model = NotificationLog::class;

    protected static ?string $translationKey = 'notification_deliveries';

    protected static ?string $navigationGroupKey = 'operations';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label(__('admin.columns.occurred_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('channel')
                    ->label(__('admin.columns.channel'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'notification_channel',
                            $state,
                        ),
                    )
                    ->badge(),
                TextColumn::make('event_type')
                    ->label(__('admin.columns.event'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'notification_event',
                            $state,
                        ),
                    )
                    ->badge(),
                TextColumn::make('recipient.email')
                    ->label(__('admin.columns.recipient'))
                    ->searchable(),
                TextColumn::make('organization.name')
                    ->label(__('admin.columns.organization'))
                    ->searchable(),
                TextColumn::make('alert.savedSearch.title')
                    ->label(__('admin.columns.saved_search'))
                    ->placeholder('—')
                    ->limit(50),
                TextColumn::make('sequence')
                    ->label(__('admin.columns.sequence'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('payload.delivery.attempt')
                    ->label(__('admin.columns.attempt'))
                    ->placeholder('—'),
                TextColumn::make('payload.delivery.exception_summary')
                    ->label(__('admin.columns.error'))
                    ->placeholder('—')
                    ->limit(80)
                    ->wrap(),
            ])
            ->defaultSort('occurred_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListNotificationDeliveries::route('/')];
    }
}
