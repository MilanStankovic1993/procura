<?php

namespace App\Filament\Resources\AuditEvents;

use App\Filament\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Support\AdminLabel;
use App\Models\PlatformAuditEvent;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AuditEventResource extends ReadOnlyResource
{
    protected static ?string $model = PlatformAuditEvent::class;

    protected static ?string $translationKey = 'audit_events';

    protected static ?string $navigationGroupKey = 'operations';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('admin.columns.created_at'))->dateTime()->sortable(),
                TextColumn::make('actor.email')->label(__('admin.columns.actor'))->searchable(),
                TextColumn::make('action')
                    ->label(__('admin.columns.action'))
                    ->formatStateUsing(fn ($state): string => AdminLabel::value('audit_action', $state))
                    ->badge()
                    ->searchable(),
                TextColumn::make('organization.name')
                    ->label(__('admin.columns.organization'))
                    ->placeholder(__('admin.placeholders.platform')),
                TextColumn::make('subject_type')
                    ->label(__('admin.columns.subject'))
                    ->formatStateUsing(fn (string $state): string => AdminLabel::subject($state)),
                TextColumn::make('subject_id')->label(__('admin.columns.subject_id'))->copyable(),
                TextColumn::make('reason')->label(__('admin.columns.reason'))->limit(80)->wrap(),
                TextColumn::make('ip_address')->label(__('admin.columns.ip')),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditEvents::route('/')];
    }
}
