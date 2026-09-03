<?php

namespace App\Filament\Resources\TelegramConnections;

use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Resources\TelegramConnections\Pages\ListTelegramConnections;
use App\Filament\Support\AdminLabel;
use App\Models\TelegramConnection;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class TelegramConnectionResource extends ReadOnlyResource
{
    protected static ?string $model = TelegramConnection::class;

    protected static ?string $translationKey = 'telegram_connections';

    protected static ?string $navigationGroupKey = 'operations';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('admin.columns.created_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('user.email')
                    ->label(__('admin.columns.user'))
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('admin.columns.status'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'telegram_connection_status',
                            $state,
                        ),
                    )
                    ->badge(),
                TextColumn::make('bot_username')
                    ->label(__('admin.columns.bot_username'))
                    ->formatStateUsing(
                        fn (string $state): string => '@'.$state,
                    ),
                TextColumn::make('telegram_user_id_hash')
                    ->label(__('admin.columns.telegram_identity'))
                    ->formatStateUsing(
                        fn (?string $state): string => $state === null
                            ? '—'
                            : substr($state, 0, 12).'…',
                    ),
                TextColumn::make('challenge_expires_at')
                    ->label(__('admin.columns.expires_at'))
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('connected_at')
                    ->label(__('admin.columns.connected_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('revoked_at')
                    ->label(__('admin.columns.revoked_at'))
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListTelegramConnections::route('/')];
    }
}
